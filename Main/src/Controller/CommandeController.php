<?php

namespace App\Controller;

use App\Service\AdminBIService;
use App\Service\TwilioSmsService;
use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeCheckoutSession;

class CommandeController extends AbstractController
{
    /* ========================= 
     * ADMIN - GESTION COMMANDES
     * ========================= */

    #[Route('/admin/commandes', name: 'admin_commandes_index', methods: ['GET'])]
    public function adminIndex(EntityManagerInterface $em): Response
    {
        $commandes = $em->getRepository(Commande::class)->findBy(
            [], 
            ['date_creation_commande' => 'DESC']
        );
        
        return $this->render('admin/commande.html.twig', [
            'commandes' => $commandes
        ]);
    }

    #[Route('/admin/commandes/{id}', name: 'admin_commande_show', methods: ['GET'])]
    public function adminShow(Commande $commande): Response
    {
        return $this->render('admin/commande.html.twig', [
            'commande' => $commande
        ]);
    }

    #[Route('/admin/commandes/{id}/statut', name: 'admin_commande_statut', methods: ['POST'])]
    public function adminChangerStatut(
        Request $request, 
        Commande $commande, 
        EntityManagerInterface $em
    ): Response {
        $nouveauStatut = (string) $request->request->get('statut');
        $statutsAutorises = ['En attente', 'En cours', 'Expédiée', 'Livrée', 'Annulée'];

        if (in_array($nouveauStatut, $statutsAutorises, true)) {
            $commande->setStatutCommande($nouveauStatut);
            $em->flush();
            $this->addFlash('success', 'Statut mis à jour ✅');
        } else {
            $this->addFlash('error', 'Statut invalide');
        }

        return $this->redirectToRoute('admin_commande_show', [
            'id' => $commande->getIdCommande()
        ]);
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

    /**
     * ✅ ADMIN - Télécharger facture de n'importe quelle commande
     */
    #[Route('/admin/commandes/{id}/facture', name: 'admin_commande_facture_pdf', methods: ['GET'])]
    public function adminFacturePdf(Commande $commande): Response
    {
        $html = $this->renderView('commande/facture_pdf.html.twig', [
            'commande' => $commande
        ]);

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
        // ✅ Vérifier que l'utilisateur est connecté
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
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
                $this->addFlash('error', $produit->getNomProduit() . ' n\'est plus disponible');
                return $this->redirectToRoute('front_produit_index');
            }

            if ((int)$produit->getQuantiteProduit() < $qty) {
                $this->addFlash('error', 'Stock insuffisant pour ' . $produit->getNomProduit());
                return $this->redirectToRoute('front_produit_index');
            }

            // Propriété temporaire pour Twig
            $produit->quantite_panier = $qty;
            $produitsPanier[] = $produit;
            $total += (float)$produit->getPrixProduit() * $qty;
        }

        return $this->render('commande/valider.html.twig', [
            'produits' => $produitsPanier,
            'total' => $total
        ]);
    }

    /* ========================= 
     * STRIPE CHECKOUT
     * ========================= */

    #[Route('/commande/stripe/checkout', name: 'commande_stripe_checkout', methods: ['POST'])]
    public function stripeCheckout(SessionInterface $session, EntityManagerInterface $em): Response
    {
        // ✅ Vérifier que l'utilisateur est connecté
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
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

        // ✅ Créer la commande pour l'utilisateur connecté
        $commande = new Commande();
        $commande->setDateCreationCommande(new \DateTimeImmutable());
        $commande->setStatutCommande('En attente');
        $commande->setUser($this->getUser()); // ✅ Associer l'utilisateur

        $em->persist($commande);

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) continue;

            $qty = (int)($item['quantite'] ?? 0);
            $prixDt = (float)$produit->getPrixProduit();
            
            if ($qty <= 0 || $prixDt <= 0) continue;

            if ($produit->getStatusProduit() !== 'Disponible' || 
                (int)$produit->getQuantiteProduit() < $qty) {
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
                    'product_data' => [
                        'name' => $produit->getNomProduit(),
                    ],
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
            'cancel_url' => $appUrl . '/commande/paiement/cancel?commande_id=' . $commande->getIdCommande(),
        ]);

        $commande->setStripeSessionId($checkout->id);
        $commande->setPaidAt(null);
        $em->flush();

        return $this->redirect($checkout->url);
    }

    /* ========================= 
     * PAIEMENT SUCCESS
     * ========================= */

    #[Route('/commande/paiement/success', name: 'commande_paiement_success', methods: ['GET'])]
    public function paiementSuccess(
        Request $request,
        SessionInterface $session,
        EntityManagerInterface $em,
        TwilioSmsService $sms
    ): Response {
        // Vérification de l'utilisateur connecté
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
    
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
    
        // Vérification que la commande appartient à l'utilisateur
        if ($commande->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Accès non autorisé à cette commande.');
            return $this->redirectToRoute('front_produit_index');
        }
    
        // Anti-doublon : si déjà payé => ne pas renvoyer SMS
        if ($commande->getPaidAt() !== null) {
            $this->addFlash('success', 'Paiement déjà confirmé ✅');
            return $this->redirectToRoute('commande_details', [
                'id' => $commande->getIdCommande()
            ]);
        }
    
        // Décrémenter stock
        foreach ($commande->getLigneCommandes() as $ligne) {
            $produit = $ligne->getProduit();
            $qty = (int)$ligne->getQuantite_commandee();
    
            if (!$produit) continue;
    
            if ($produit->getStatusProduit() !== 'Disponible' || (int)$produit->getQuantiteProduit() < $qty) {
                $this->addFlash('error', 'Stock devenu insuffisant pour ' . $produit->getNomProduit());
                return $this->redirectToRoute('front_produit_index');
            }
    
            $nouveauStock = (int)$produit->getQuantiteProduit() - $qty;
            $produit->setQuantiteProduit($nouveauStock);
    
            if ($nouveauStock <= 0) {
                $produit->setStatusProduit('Rupture');
            }
        }
    
        // Mettre commande payée
        $commande->setStatutCommande('En cours');
        $commande->setStripeSessionId($sessionId);
        $commande->setPaidAt(new \DateTimeImmutable());
    
        $em->flush();
    
        // Envoi du SMS Twilio
        try {
            $to = $_ENV['TWILIO_TO'] ?? '+21623257464'; // Numéro destinataire
            $items = [];
    
            foreach ($commande->getLigneCommandes() as $ligne) {
                $p = $ligne->getProduit();
                if (!$p) continue;
                $items[] = $p->getNomProduit() . ' x' . (int)$ligne->getQuantite_commandee();
            }
    
            $message = "✅ Merci pour votre commande MedFlow !\n" .
                "Commande #" . $commande->getIdCommande() . "\n" .
                "Payé: " . number_format((float)$commande->getMontantTotal(), 2, ',', ' ') . " DT\n" .
                "Articles: " . implode(', ', $items) . "\n" .
                "Statut: " . $commande->getStatutCommande();
    
            // Envoi du message
            $sms->send($to, $message);
        } catch (\Throwable $e) {
            // Loguer ou gérer l'erreur
            $this->addFlash('error', 'Erreur lors de l\'envoi du SMS : ' . $e->getMessage());
        }
    
        // Vider le panier après commande réussie
        $session->remove('panier');
    
        $this->addFlash('success', 'Paiement réussi ✅ Votre commande est confirmée.');
        return $this->redirectToRoute('commande_details', ['id' => $commande->getIdCommande()]);
    }
    
    #[Route('/commande/paiement/cancel', name: 'commande_paiement_cancel', methods: ['GET'])]
    public function paiementCancel(Request $request, EntityManagerInterface $em): Response
    {
        // ✅ Vérifier que l'utilisateur est connecté
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        $commandeId = (int)$request->query->get('commande_id');

        if ($commandeId > 0) {
            $commande = $em->getRepository(Commande::class)->find($commandeId);

            // ✅ Vérifier que la commande appartient à l'utilisateur
            if ($commande && 
                $commande->getUser() === $this->getUser() && 
                $commande->getStatutCommande() === 'En attente') {
                
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
     * USER - DÉTAILS COMMANDE
     * ========================= */

    #[Route('/commande/details/{id}', name: 'commande_details', methods: ['GET'])]
    public function details(Commande $commande): Response
    {
        // ✅ Vérifier que l'utilisateur est connecté
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        // ✅ Vérifier que la commande appartient à l'utilisateur
        if ($commande->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Accès non autorisé à cette commande.');
            return $this->redirectToRoute('mes_commandes');
        }

        return $this->render('commande/details.html.twig', [
            'commande' => $commande
        ]);
    }

    /* ========================= 
     * USER - MES COMMANDES
     * ========================= */

    #[Route('/commande/mes-commandes', name: 'mes_commandes', methods: ['GET'])]
    public function mesCommandes(EntityManagerInterface $em): Response
    {
        // ✅ Vérifier que l'utilisateur est connecté
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        // ✅ Récupérer uniquement les commandes de l'utilisateur
        $commandes = $em->getRepository(Commande::class)->findBy(
            ['user' => $this->getUser()],
            ['date_creation_commande' => 'DESC']
        );

        return $this->render('commande/mes_commandes.html.twig', [
            'commandes' => $commandes
        ]);
    }

    /* ========================= 
     * USER - FACTURE PDF
     * ========================= */

    #[Route('/commande/{id}/facture', name: 'commande_facture_pdf', methods: ['GET'])]
    public function facturePdf(Commande $commande): Response
    {
        // ✅ Vérifier que l'utilisateur est connecté
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        
        // ✅ Vérifier que la commande appartient à l'utilisateur
        if ($commande->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Accès non autorisé à cette facture.');
            return $this->redirectToRoute('mes_commandes');
        }

        $html = $this->renderView('commande/facture_pdf.html.twig', [
            'commande' => $commande
        ]);

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
        $from = $request->query->get('from');
        $to = $request->query->get('to');
        $cat = $request->query->get('cat');

        $data = $bi->buildDashboard($days, $from, $to, $cat);

        return $this->render('admin/bi_dashboard.html.twig', [
            'days' => $data['period']['days'],
            'from' => $data['period']['from'],
            'to' => $data['period']['to'],
            'cat' => $data['period']['cat'],
            'categories' => $data['period']['categories'],
            'kpi' => $data['kpi'],
            'charts' => $data['charts'],
            'topProduits' => $data['topProduits'],
            'stocksBas' => $data['stocksBas'],
            'alerts' => $data['alerts'],
            'tips' => $data['tips'],
        ]);
    }
}