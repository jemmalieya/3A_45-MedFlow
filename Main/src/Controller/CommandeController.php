<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Entity\User;
use App\Service\AdminBIService;
use App\Service\TwilioSmsService;
use App\Service\GeocodingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Dompdf\Dompdf;
use Dompdf\Options;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeCheckoutSession;
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
    /**
     * Pagination Doctrine (compatible PHPStan)
     *
     * @return array{
     *   items: list<Commande>,
     *   total: int,
     *   page: int,
     *   limit: int
     * }
     */
    private function paginateDoctrine(QueryBuilder $qb, int $page, int $limit): array
    {
        $page  = max(1, $page);
        $limit = max(1, $limit);

        // Clone pour compter (sans ORDER BY)
        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy');

        // ⚠️ si ton QB contient des joins, count(DISTINCT c.idCommande) évite les doublons
        $countQb->select('COUNT(DISTINCT c.id_commande)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // Appliquer LIMIT/OFFSET au QB principal
        $qb->setFirstResult(($page - 1) * $limit)
           ->setMaxResults($limit);

        // fetchJoinCollection = true parce que tu joins ligne_commandes + produit
        $paginator = new Paginator($qb, fetchJoinCollection: true);

        /** @var list<Commande> $items */
        $items = iterator_to_array($paginator->getIterator());

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    /* =========================
     * ADMIN - GESTION COMMANDES (PAGINATION)
     * ========================= */

    #[Route('/admin/commandes', name: 'admin_commandes_index', methods: ['GET'])]
    public function adminIndex(Request $request, EntityManagerInterface $em): Response
    {
        $qb = $em->getRepository(Commande::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.ligne_commandes', 'l')
            ->addSelect('l')
            ->leftJoin('l.produit', 'p')
            ->addSelect('p')
            ->orderBy('c.date_creation_commande', 'DESC');

        $page  = $request->query->getInt('page', 1);
        $limit = 10;

        $pagination = $this->paginateDoctrine($qb, $page, $limit);

        return $this->render('admin/commande.html.twig', [
            'commandes'  => $pagination['items'],
            'pagination' => $pagination,
            'commande'   => null,
        ]);
    }

    #[Route('/admin/commandes/{id}', name: 'admin_commande_show', methods: ['GET'])]
    public function adminShow(Request $request, Commande $commande, EntityManagerInterface $em): Response
    {
        $qb = $em->getRepository(Commande::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.ligne_commandes', 'l')
            ->addSelect('l')
            ->leftJoin('l.produit', 'p')
            ->addSelect('p')
            ->orderBy('c.date_creation_commande', 'DESC');

        $page  = $request->query->getInt('page', 1);
        $limit = 10;

        $pagination = $this->paginateDoctrine($qb, $page, $limit);

        return $this->render('admin/commande.html.twig', [
            'commande'   => $commande,
            'commandes'  => $pagination['items'],
            'pagination' => $pagination,
        ]);
    }

    #[Route('/admin/commandes/{id}/statut', name: 'admin_commande_statut', methods: ['POST'])]
    public function adminChangerStatut(
        Request $request,
        Commande $commande,
        EntityManagerInterface $em
    ): Response {
        $nouveauStatut = (string) $request->request->get('statut');
        $statutsAutorises = ['En attente', 'En cours', 'En livraison', 'Expédiée', 'Livrée', 'Annulée'];

        if (in_array($nouveauStatut, $statutsAutorises, true)) {
            $commande->setStatutCommande($nouveauStatut);
            $em->flush();
            $this->addFlash('success', 'Statut mis à jour ✅');
        } else {
            $this->addFlash('error', 'Statut invalide');
        }

        return $this->redirectToRoute('admin_commande_show', [
            'id' => $commande->getIdCommande(),
        ]);
    }

    #[Route('/admin/commandes/{id}/start-livraison', name: 'admin_commande_start_livraison', methods: ['POST'])]
    public function adminStartLivraison(Commande $commande, EntityManagerInterface $em): Response
    {
        if ($commande->getStatutCommande() !== 'En cours') {
            $this->addFlash('error', 'Impossible de démarrer : statut actuel = ' . (string) $commande->getStatutCommande());
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

    #[Route('/admin/commandes/{id}/facture', name: 'admin_commande_facture_pdf', methods: ['GET'])]
    public function adminFacturePdf(Commande $commande): Response
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
     * FRONT - VALIDATION COMMANDE
     * ========================= */

    #[Route('/commande/valider', name: 'commande_valider', methods: ['GET'])]
    public function valider(SessionInterface $session, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);
        if (empty($panier)) {
            $this->addFlash('error', 'Votre panier est vide');
            return $this->redirectToRoute('front_produit_index');
        }

        $produitsPanier = [];
        $total = 0.0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) {
                continue;
            }

            $qty = (int) ($item['quantite'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            if ($produit->getStatusProduit() !== 'Disponible') {
                $this->addFlash('error', $produit->getNomProduit() . ' n\'est plus disponible');
                return $this->redirectToRoute('front_produit_index');
            }

            if ($produit->getQuantiteProduit() < $qty) {
                $this->addFlash('error', 'Stock insuffisant pour ' . $produit->getNomProduit());
                return $this->redirectToRoute('front_produit_index');
            }

            $produitsPanier[] = [
                'produit' => $produit,
                'quantite' => $qty,
            ];

            $total += (float) $produit->getPrixProduit() * $qty;
        }

        return $this->render('commande/valider.html.twig', [
            'produits' => $produitsPanier,
            'total' => $total,
        ]);
    }

    /* =========================
     * STRIPE CHECKOUT
     * ========================= */

    #[Route('/commande/stripe/checkout', name: 'commande_stripe_checkout', methods: ['POST'])]
    public function stripeCheckout(Request $request, SessionInterface $session, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        if (!is_string($stripeSecret) || $stripeSecret === '') {
            $this->addFlash('error', 'STRIPE_SECRET_KEY introuvable.');
            return $this->redirectToRoute('commande_valider');
        }

        $appUrl = $request->getSchemeAndHttpHost();

        /** @var array<int|string, array{quantite?:int, prix?:float}> $panier */
        $panier = $session->get('panier', []);
        if (empty($panier)) {
            $this->addFlash('error', 'Votre panier est vide');
            return $this->redirectToRoute('front_produit_index');
        }

        $stripeCurrency = 'eur';
        $dtToEurRate = 0.30;

        /** @var list<array{price_data: array{currency: string, product_data: array{name: string}, unit_amount: int}, quantity: int}> $lineItems */
        $lineItems = [];

        $totalDt = 0.0;

        $commande = new Commande();
        $commande->setDateCreationCommande(new \DateTimeImmutable());
        $commande->setStatutCommande('En attente');

        $u = $this->getUser();
        if (!$u instanceof User) {
            $this->addFlash('error', 'Utilisateur invalide.');
            return $this->redirectToRoute('commande_valider');
        }
        $commande->setUser($u);

        $em->persist($commande);

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) {
                continue;
            }

            $qty = (int) ($item['quantite'] ?? 0);
            $prixDt = (float) $produit->getPrixProduit();

            if ($qty <= 0 || $prixDt <= 0) {
                continue;
            }

            $stock = $produit->getQuantiteProduit();
            if ($produit->getStatusProduit() !== 'Disponible' || $stock < $qty) {
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
            if ($unitAmount < 1) {
                $unitAmount = 1;
            }

            $productName = trim($produit->getNomProduit());
            if ($productName === '') {
                $productName = 'Produit';
            }

            $lineItems[] = [
                'price_data' => [
                    'currency' => $stripeCurrency,
                    'product_data' => ['name' => $productName],
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
        $em->flush();

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

        $commande->setStripeSessionId((string) $checkout->id);
        $commande->setPaidAt(null);
        $em->flush();

        $redirectUrl = $checkout->url;
        if (!is_string($redirectUrl) || $redirectUrl === '') {
            $this->addFlash('error', 'Erreur Stripe: URL de redirection manquante.');
            return $this->redirectToRoute('commande_valider');
        }

        return $this->redirect($redirectUrl);
    }

    /* =========================
     * PAIEMENT SUCCESS / CANCEL
     * ========================= */

    #[Route('/commande/paiement/success', name: 'commande_paiement_success', methods: ['GET'])]
    public function paiementSuccess(
        Request $request,
        SessionInterface $session,
        EntityManagerInterface $em,
        TwilioSmsService $sms
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        if (!is_string($stripeSecret) || $stripeSecret === '') {
            $this->addFlash('error', 'STRIPE_SECRET_KEY introuvable.');
            return $this->redirectToRoute('commande_valider');
        }

        $sessionId = (string) $request->query->get('session_id', '');
        if ($sessionId === '') {
            $this->addFlash('error', 'Session manquante.');
            return $this->redirectToRoute('commande_valider');
        }

        Stripe::setApiKey($stripeSecret);
        $stripeSession = StripeCheckoutSession::retrieve($sessionId);

        if (($stripeSession->payment_status ?? null) !== 'paid') {
            $this->addFlash('warning', 'Paiement non confirmé.');
            return $this->redirectToRoute('commande_valider');
        }

        $commandeId = (int) ($stripeSession->metadata->commande_id ?? 0);
        if ($commandeId <= 0) {
            $this->addFlash('error', 'Commande introuvable (metadata manquante).');
            return $this->redirectToRoute('front_produit_index');
        }

        $commande = $em->getRepository(Commande::class)->find($commandeId);
        if (!$commande) {
            $this->addFlash('error', 'Commande introuvable en base.');
            return $this->redirectToRoute('front_produit_index');
        }

        if ($commande->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Accès non autorisé à cette commande.');
            return $this->redirectToRoute('front_produit_index');
        }

        if ($commande->getPaidAt() !== null) {
            $this->addFlash('success', 'Paiement déjà confirmé ✅');
            return $this->redirectToRoute('commande_details', ['id' => $commande->getIdCommande()]);
        }

        foreach ($commande->getLigneCommandes() as $ligne) {
            $produit = $ligne->getProduit();
            $qty = (int) $ligne->getQuantite_commandee();
            if (!$produit) {
                continue;
            }

            $stock = $produit->getQuantiteProduit();
            if ($produit->getStatusProduit() !== 'Disponible' || $stock < $qty) {
                $this->addFlash('error', 'Stock devenu insuffisant pour ' . $produit->getNomProduit());
                return $this->redirectToRoute('front_produit_index');
            }

            $nouveauStock = $stock - $qty;
            $produit->setQuantiteProduit($nouveauStock);

            if ($nouveauStock <= 0) {
                $produit->setStatusProduit('Rupture');
            }
        }

        $commande->setStatutCommande('En cours');
        $commande->setStripeSessionId($sessionId);
        $commande->setPaidAt(new \DateTimeImmutable());
        $em->flush();

        try {
            $to = $_ENV['TWILIO_TO'] ?? '+21623257464';
            $items = [];
            foreach ($commande->getLigneCommandes() as $ligne) {
                $p = $ligne->getProduit();
                if ($p) {
                    $items[] = $p->getNomProduit() . ' x' . (int) $ligne->getQuantite_commandee();
                }
            }

            $message = "✅ Merci pour votre commande MedFlow !\n" .
                "Commande #" . $commande->getIdCommande() . "\n" .
                "Payé: " . number_format((float) $commande->getMontantTotal(), 2, ',', ' ') . " DT\n" .
                "Articles: " . implode(', ', $items) . "\n" .
                "Statut: " . (string) $commande->getStatutCommande();

            // $sms->send($to, $message);
        } catch (\Throwable $e) {
            // silence
        }

        $session->remove('panier');
        $this->addFlash('success', 'Paiement réussi ✅ Votre commande est confirmée.');
        return $this->redirectToRoute('commande_details', ['id' => $commande->getIdCommande()]);
    }

    #[Route('/commande/paiement/cancel', name: 'commande_paiement_cancel', methods: ['GET'])]
    public function paiementCancel(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $commandeId = (int) $request->query->get('commande_id', 0);
        if ($commandeId > 0) {
            $commande = $em->getRepository(Commande::class)->find($commandeId);

            if ($commande && $commande->getUser() === $this->getUser() && $commande->getStatutCommande() === 'En attente') {
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

    /* =========================
     * USER - DETAILS / MES COMMANDES (PAGINATION)
     * ========================= */

    #[Route('/commande/details/{id}', name: 'commande_details', methods: ['GET'])]
    public function details(Commande $commande): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if ($commande->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Accès non autorisé à cette commande.');
            return $this->redirectToRoute('mes_commandes');
        }

        return $this->render('commande/details.html.twig', ['commande' => $commande]);
    }

    #[Route('/commande/mes-commandes', name: 'mes_commandes', methods: ['GET'])]
    public function mesCommandes(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $u = $this->getUser();
        if (!$u instanceof User) {
            $this->addFlash('error', 'Utilisateur invalide.');
            return $this->redirectToRoute('front_produit_index');
        }

        $qb = $em->getRepository(Commande::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.ligne_commandes', 'l')
            ->addSelect('l')
            ->leftJoin('l.produit', 'p')
            ->addSelect('p')
            ->andWhere('c.user = :u')
            ->setParameter('u', $u)
            ->orderBy('c.date_creation_commande', 'DESC');

        $page  = $request->query->getInt('page', 1);
        $limit = 8;

        $pagination = $this->paginateDoctrine($qb, $page, $limit);

        return $this->render('commande/mes_commandes.html.twig', [
            'commandes'  => $pagination['items'],
            'pagination' => $pagination,
        ]);
    }

    #[Route('/commande/{id}/facture', name: 'commande_facture_pdf', methods: ['GET'])]
    public function facturePdf(Commande $commande): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if ($commande->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Accès non autorisé à cette facture.');
            return $this->redirectToRoute('mes_commandes');
        }

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
     * ADMIN - BI DASHBOARD
     * ========================= */

    #[Route('/admin/bi', name: 'admin_bi_dashboard')]
    public function dashboard(Request $request, AdminBIService $bi): Response
    {
        $days = (int) $request->query->get('days', 30);

        $fromRaw = $request->query->get('from');
        $toRaw   = $request->query->get('to');
        $catRaw  = $request->query->get('cat');

        $from = is_string($fromRaw) ? $fromRaw : null;
        $to   = is_string($toRaw) ? $toRaw : null;
        $cat  = is_string($catRaw) ? $catRaw : null;

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
     * SUIVI LIVRAISON (MAP)
     * ========================= */

    #[Route('/commande/{id}/livraison-demo', name: 'commande_livraison_demo', methods: ['GET'])]
    public function livraisonDemoPage(Commande $commande, GeocodingService $geo): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        if ($commande->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Accès non autorisé.');
            return $this->redirectToRoute('mes_commandes');
        }

        $startLat = 36.8065;
        $startLng = 10.1815;

        $user = $commande->getUser();
        $adresseUser = trim((string) $user->getAdresseUser());

        if ($adresseUser === '') {
            $adresseFull = 'Tunis, Tunisie';
            $adresseAffiche = 'Adresse non renseignée (Tunis par défaut)';
        } else {
            $adresseFull = $adresseUser . ', Tunisie';
            $adresseAffiche = $adresseUser;
        }

        $geoResult = $geo->geocode($adresseFull);

        $destLat = $geoResult['lat'] ?? 36.8665;
        $destLng = $geoResult['lng'] ?? 10.1647;

        return $this->render('commande/livraison_demo.html.twig', [
            'commande' => $commande,
            'adresse'  => $adresseAffiche,
            'startLat' => $startLat,
            'startLng' => $startLng,
            'destLat'  => $destLat,
            'destLng'  => $destLng,
        ]);
    }

    #[Route('/api/commande/{id}/statut', name: 'api_commande_statut', methods: ['GET'])]
    public function apiCommandeStatut(Commande $commande): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'statut' => $commande->getStatutCommande(),
        ]);
    }

    #[Route('/api/commande/{id}/livraison/route', name: 'api_commande_livraison_route', methods: ['GET'])]
    public function livraisonRoute(Commande $commande, HttpClientInterface $http, GeocodingService $geo): JsonResponse
    {
        $startLat = 36.8065;
        $startLng = 10.1815;

        $user = $commande->getUser();
        $adresseUser = trim((string) $user->getAdresseUser());

        $adresseFull = $adresseUser !== '' ? ($adresseUser . ', Tunisie') : 'Tunis, Tunisie';

        $geoResult = $geo->geocode($adresseFull);

        $destLat = $geoResult['lat'] ?? 36.8665;
        $destLng = $geoResult['lng'] ?? 10.1647;

        $url = sprintf(
            'https://router.project-osrm.org/route/v1/driving/%f,%f;%f,%f?overview=full&geometries=geojson',
            $startLng,
            $startLat,
            $destLng,
            $destLat
        );

        try {
            $res = $http->request('GET', $url);
            $data = $res->toArray(false);

            if (!isset($data['routes'][0]['geometry']['coordinates'])) {
                return new JsonResponse(['success' => false, 'message' => 'Route introuvable'], 500);
            }

            /** @var array<int, array{0: float, 1: float}> $rawCoords */
            $rawCoords = $data['routes'][0]['geometry']['coordinates'];

            /** @var list<array{0: float, 1: float}> $coords */
            $coords = array_map(static fn(array $c): array => [$c[1], $c[0]], $rawCoords);

            return new JsonResponse([
                'success' => true,
                'coords' => $coords,
                'dest' => ['lat' => $destLat, 'lng' => $destLng],
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