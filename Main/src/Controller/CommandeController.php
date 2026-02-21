<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Service\AdminBIService;
use App\Service\TwilioSmsService;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Stripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CommandeController extends AbstractController
{
    /* =========================
     *         BACK (ADMIN)
     * ========================= */

    #[Route('/admin/commandes', name: 'admin_commandes_index', methods: ['GET'])]
    public function adminIndex(EntityManagerInterface $em): Response
    {
        $commandes = $em->getRepository(Commande::class)->findBy([], ['date_creation_commande' => 'DESC']);

        return $this->render('admin/commande.html.twig', [
            'commandes' => $commandes
        ]);
    }

    #[Route('/admin/commandes/{id}', name: 'admin_commande_show', methods: ['GET'])]
    public function adminShow(Commande $commande, EntityManagerInterface $em): Response
    {
        $commandes = $em->getRepository(Commande::class)->findBy([], ['date_creation_commande' => 'DESC']);

        return $this->render('admin/commande.html.twig', [
            'commande'  => $commande,
            'commandes' => $commandes,
        ]);
    }

    #[Route('/admin/commandes/{id}/statut', name: 'admin_commande_statut', methods: ['POST'])]
    public function adminChangerStatut(Request $request, Commande $commande, EntityManagerInterface $em): Response
    {
        $nouveauStatut = (string) $request->request->get('statut');

        $statutsAutorises = ['En attente', 'En cours', 'En livraison', 'Expédiée', 'Livrée', 'Annulée'];

        if (in_array($nouveauStatut, $statutsAutorises, true)) {
            $commande->setStatutCommande($nouveauStatut);
            $em->flush();
            $this->addFlash('success', 'Statut mis à jour ✅');
        } else {
            $this->addFlash('error', 'Statut invalide ❌');
        }

        return $this->redirectToRoute('admin_commande_show', ['id' => $commande->getIdCommande()]);
    }

    /**
     * ✅ ADMIN/STAFF : démarrer livraison (En cours -> En livraison)
     * (tu peux ajouter un contrôle de rôle plus tard)
     */
    #[Route('/admin/commandes/{id}/start-livraison', name: 'admin_commande_start_livraison', methods: ['POST'])]
    public function adminStartLivraison(Commande $commande, EntityManagerInterface $em): Response
    {
        if ($commande->getStatutCommande() !== 'En cours') {
            $this->addFlash('error', 'Impossible de démarrer : statut actuel = ' . $commande->getStatutCommande());
            return $this->redirectToRoute('admin_commande_show', ['id' => $commande->getIdCommande()]);
        }

        $commande->setStatutCommande('En livraison');
        $em->flush();

        $this->addFlash('success', 'Livraison démarrée 🚚');
        return $this->redirectToRoute('admin_commande_show', ['id' => $commande->getIdCommande()]);
    }

    #[Route('/admin/commandes/{id}/delete', name: 'admin_commande_delete', methods: ['POST'])]
    public function adminDelete(Commande $commande, EntityManagerInterface $em): Response
    {
        foreach ($commande->getLigneCommandes() as $ligne) {
            $em->remove($ligne);
        }

        $em->remove($commande);
        $em->flush();

        $this->addFlash('success', 'Commande supprimée ✅');
        return $this->redirectToRoute('admin_commandes_index');
    }

    /* =========================
     *         FRONT (USER)
     * ========================= */

    #[Route('/valider', name: 'commande_valider', methods: ['GET'])]
    public function valider(SessionInterface $session, EntityManagerInterface $em): Response
    {
        $panier = $session->get('panier', []);
        if (empty($panier)) {
            $this->addFlash('error', 'Votre panier est vide');
            return $this->redirectToRoute('front_produit_index');
        }

        $produitsPanier = [];
        $total = 0.0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) continue;

            $qty = (int)($item['quantite'] ?? 0);
            if ($qty <= 0) continue;

            if ($produit->getStatusProduit() !== 'Disponible') {
                $this->addFlash('error', $produit->getNomProduit() . " n'est plus disponible");
                return $this->redirectToRoute('front_produit_index');
            }

            if ((int)$produit->getQuantiteProduit() < $qty) {
                $this->addFlash('error', 'Stock insuffisant pour ' . $produit->getNomProduit());
                return $this->redirectToRoute('front_produit_index');
            }

            $produit->quantite_panier = $qty;
            $produitsPanier[] = $produit;
            $total += (float)$produit->getPrixProduit() * $qty;
        }

        return $this->render('commande/valider.html.twig', [
            'produits' => $produitsPanier,
            'total' => $total
        ]);
    }

    #[Route('/stripe/checkout', name: 'commande_stripe_checkout', methods: ['POST'])]
    public function stripeCheckout(SessionInterface $session, EntityManagerInterface $em): Response
    {
        $stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        $appUrl = $_ENV['APP_URL'] ?? 'http://127.0.0.1:8000';

        if (!$stripeSecret) {
            $this->addFlash('error', 'STRIPE_SECRET_KEY introuvable.');
            return $this->redirectToRoute('commande_valider');
        }

        $panier = $session->get('panier', []);
        if (empty($panier)) {
            $this->addFlash('error', 'Votre panier est vide');
            return $this->redirectToRoute('front_produit_index');
        }

        $stripeCurrency = 'eur';
        $dtToEurRate = 0.30;

        $lineItems = [];
        $totalDt = 0.0;

        $commande = new Commande();
        $commande->setDateCreationCommande(new \DateTimeImmutable());
        $commande->setStatutCommande('En attente');
        $commande->setIdUser(1); // TODO user connecté

        $em->persist($commande);

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) continue;

            $qty = (int)($item['quantite'] ?? 0);
            $prixDt = (float)$produit->getPrixProduit();

            if ($qty <= 0 || $prixDt <= 0) continue;

            if ($produit->getStatusProduit() !== 'Disponible' || (int)$produit->getQuantiteProduit() < $qty) {
                $this->addFlash('error', 'Problème stock/disponibilité : ' . $produit->getNomProduit());
                return $this->redirectToRoute('front_produit_index');
            }

            $totalDt += $prixDt * $qty;

            $ligne = new LigneCommande();
            $ligne->setProduit($produit);
            $ligne->setQuantite_commandee($qty);
            $commande->addLigneCommande($ligne);
            $em->persist($ligne);

            $unitAmount = (int) round(($prixDt * $dtToEurRate) * 100);
            if ($unitAmount < 1) $unitAmount = 1;

            $lineItems[] = [
                'price_data' => [
                    'currency' => $stripeCurrency,
                    'product_data' => ['name' => $produit->getNomProduit()],
                    'unit_amount' => $unitAmount,
                ],
                'quantity' => $qty,
            ];
        }

        if (empty($lineItems)) {
            $this->addFlash('error', 'Aucun produit valide.');
            return $this->redirectToRoute('commande_valider');
        }

        $commande->setMontantTotal($totalDt);
        $em->flush(); // obtenir id commande

        Stripe::setApiKey($stripeSecret);

        $checkout = StripeCheckoutSession::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'metadata' => [
                'commande_id' => (string) $commande->getIdCommande(),
            ],
            'success_url' => $appUrl . '/commande/paiement/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $appUrl . '/commande/paiement/cancel?commande_id=' . $commande->getIdCommande(),
        ]);

        if (method_exists($commande, 'setStripeSessionId')) {
            $commande->setStripeSessionId($checkout->id);
            if (method_exists($commande, 'setPaidAt')) {
                $commande->setPaidAt(null);
            }
            $em->flush();
        }

        return $this->redirect($checkout->url);
    }

    #[Route('/commande/paiement/success', name: 'commande_paiement_success', methods: ['GET'])]
    public function paiementSuccess(
        Request $request,
        SessionInterface $session,
        EntityManagerInterface $em,
        TwilioSmsService $sms
    ): Response {
        $stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        if (!$stripeSecret) {
            $this->addFlash('error', 'STRIPE_SECRET_KEY introuvable.');
            return $this->redirectToRoute('commande_valider');
        }

        $sessionId = (string) $request->query->get('session_id');
        if (!$sessionId) {
            $this->addFlash('error', 'Session manquante.');
            return $this->redirectToRoute('commande_valider');
        }

        Stripe::setApiKey($stripeSecret);
        $stripeSession = StripeCheckoutSession::retrieve($sessionId);

        if (($stripeSession->payment_status ?? null) !== 'paid') {
            $this->addFlash('warning', 'Paiement non confirmé.');
            return $this->redirectToRoute('commande_valider');
        }

        $commandeId = (int)($stripeSession->metadata->commande_id ?? 0);
        if ($commandeId <= 0) {
            $this->addFlash('error', 'Commande introuvable (metadata manquante).');
            return $this->redirectToRoute('front_produit_index');
        }

        $commande = $em->getRepository(Commande::class)->find($commandeId);
        if (!$commande) {
            $this->addFlash('error', 'Commande introuvable en base.');
            return $this->redirectToRoute('front_produit_index');
        }

        if (method_exists($commande, 'getPaidAt') && $commande->getPaidAt() !== null) {
            $this->addFlash('success', 'Paiement déjà confirmé ✅');
            return $this->redirectToRoute('commande_details', ['id' => $commande->getIdCommande()]);
        }

        foreach ($commande->getLigneCommandes() as $ligne) {
            $produit = $ligne->getProduit();
            $qty = (int)$ligne->getQuantite_commandee();
            if (!$produit) continue;

            if ($produit->getStatusProduit() !== 'Disponible' || (int)$produit->getQuantiteProduit() < $qty) {
                $this->addFlash('error', 'Stock insuffisant pour ' . $produit->getNomProduit());
                return $this->redirectToRoute('front_produit_index');
            }

            $nouveauStock = (int)$produit->getQuantiteProduit() - $qty;
            $produit->setQuantiteProduit($nouveauStock);

            if ($nouveauStock <= 0) {
                $produit->setStatusProduit('Rupture');
            }
        }

        $commande->setStatutCommande('En cours');

        if (method_exists($commande, 'setStripeSessionId')) {
            $commande->setStripeSessionId($sessionId);
        }
        if (method_exists($commande, 'setPaidAt')) {
            $commande->setPaidAt(new \DateTimeImmutable());
        }

        $em->flush();

        try {
            $to = $_ENV['TWILIO_TO'] ?? '+21654430709';

            $items = [];
            foreach ($commande->getLigneCommandes() as $ligne) {
                $p = $ligne->getProduit();
                if (!$p) continue;
                $items[] = $p->getNomProduit() . ' x' . (int)$ligne->getQuantite_commandee();
            }

            $message =
                "✅ Merci pour votre commande MedFlow !\n" .
                "Commande #" . $commande->getIdCommande() . "\n" .
                "Payé: " . number_format((float)$commande->getMontantTotal(), 2, ',', ' ') . " DT\n" .
                "Articles: " . implode(', ', $items) . "\n" .
                "Statut: " . $commande->getStatutCommande();

          //  $sms->send($to, $message);
        } catch (\Throwable $e) {}

        $session->remove('panier');

        $this->addFlash('success', 'Paiement réussi ✅ Votre commande est confirmée.');
        return $this->redirectToRoute('commande_details', ['id' => $commande->getIdCommande()]);
    }

    #[Route('/commande/paiement/cancel', name: 'commande_paiement_cancel', methods: ['GET'])]
    public function paiementCancel(Request $request, EntityManagerInterface $em): Response
    {
        $commandeId = (int)$request->query->get('commande_id');

        if ($commandeId > 0) {
            $commande = $em->getRepository(Commande::class)->find($commandeId);
            if ($commande && $commande->getStatutCommande() === 'En attente') {
                foreach ($commande->getLigneCommandes() as $ligne) {
                    $em->remove($ligne);
                }
                $em->remove($commande);
                $em->flush();
            }
        }

        $this->addFlash('warning', 'Paiement annulé. Vous pouvez réessayer.');
        return $this->redirectToRoute('commande_valider');
    }

    #[Route('/details/{id}', name: 'commande_details', methods: ['GET'])]
    public function details(Commande $commande): Response
    {
        return $this->render('commande/details.html.twig', ['commande' => $commande]);
    }

    #[Route('/mes-commandes', name: 'mes_commandes', methods: ['GET'])]
    public function mesCommandes(EntityManagerInterface $em): Response
    {
        $commandes = $em->getRepository(Commande::class)->findBy([], ['date_creation_commande' => 'DESC']);
        return $this->render('commande/mes_commandes.html.twig', ['commandes' => $commandes]);
    }

    #[Route('/commande/{id}/facture', name: 'commande_facture_pdf', methods: ['GET'])]
    public function facturePdf(Commande $commande): Response
    {
        $html = $this->renderView('commande/facture_pdf.html.twig', ['commande' => $commande]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'facture_commande_' . $commande->getIdCommande() . '.pdf';

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => (new ResponseHeaderBag())->makeDisposition(
                    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    $filename
                ),
            ]
        );
    }

    /* =========================
     *         BI DASHBOARD
     * ========================= */

    #[Route('/admin/bi', name: 'admin_bi_dashboard')]
    public function dashboard(Request $request, AdminBIService $bi): Response
    {
        $days = (int) $request->query->get('days', 30);
        $from = $request->query->get('from');
        $to   = $request->query->get('to');
        $cat  = $request->query->get('cat');

        $data = $bi->buildDashboard($days, $from, $to, $cat);

        return $this->render('admin/bi_dashboard.html.twig', [
            'days' => $data['period']['days'],
            'from' => $data['period']['from'],
            'to'   => $data['period']['to'],
            'cat'  => $data['period']['cat'],
            'categories' => $data['period']['categories'],
            'kpi' => $data['kpi'],
            'charts' => $data['charts'],
            'topProduits' => $data['topProduits'],
            'stocksBas' => $data['stocksBas'],
            'alerts' => $data['alerts'],
            'tips' => $data['tips'],
        ]);
    }

    /* =========================
     *   LIVRAISON DEMO (MAP)
     * ========================= */

    #[Route('/commande/{id}/livraison-demo', name: 'commande_livraison_demo', methods: ['GET'])]
    public function livraisonDemoPage(Commande $commande): Response
    {
        // Départ (pharmacie) : Tunis centre (démo)
        $startLat = 36.8065;
        $startLng = 10.1815;

        // Destination démo : Ariana
        $adresse = "Ariana, Tunisie";
        $destLat = 36.8665;
        $destLng = 10.1647;

        return $this->render('commande/livraison_demo.html.twig', [
            'commande' => $commande,
            'adresse' => $adresse,
            'startLat' => $startLat,
            'startLng' => $startLng,
            'destLat' => $destLat,
            'destLng' => $destLng,
        ]);
    }

    // ✅ API statut pour que le front sache si l'admin a démarré la livraison
    #[Route('/api/commande/{id}/statut', name: 'api_commande_statut', methods: ['GET'])]
    public function apiCommandeStatut(Commande $commande): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'statut' => $commande->getStatutCommande(),
        ]);
    }

    #[Route('/api/commande/{id}/livraison/route', name: 'api_commande_livraison_route', methods: ['GET'])]
    public function livraisonRoute(Commande $commande, HttpClientInterface $http): JsonResponse
    {
        $startLat = 36.8065;
        $startLng = 10.1815;

        $destLat = 36.8665;
        $destLng = 10.1647;

        $url = sprintf(
            'https://router.project-osrm.org/route/v1/driving/%f,%f;%f,%f?overview=full&geometries=geojson',
            $startLng, $startLat,
            $destLng, $destLat
        );

        try {
            $res = $http->request('GET', $url);
            $data = $res->toArray(false);

            if (!isset($data['routes'][0]['geometry']['coordinates'])) {
                return new JsonResponse(['success' => false, 'message' => 'Route introuvable'], 500);
            }

            $coords = array_map(fn($c) => [$c[1], $c[0]], $data['routes'][0]['geometry']['coordinates']);

            return new JsonResponse([
                'success' => true,
                'coords' => $coords,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur OSRM: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/api/commande/{id}/livraison/ping', name: 'api_commande_livraison_ping', methods: ['GET'])]
    public function livraisonPing(Commande $commande): JsonResponse
    {
        return new JsonResponse(['success' => true]);
    }
}