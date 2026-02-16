<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\EvenementRepository;
use App\Repository\RessourceRepository;


use App\Repository\ProduitRepository;
use App\Repository\CommandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
//#[IsGranted('ROLE_ADMIN')]
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Log\LoggerInterface;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use Symfony\Contracts\HttpClient\HttpClientInterface;
// Brevo email via API (same logic as registration)

use App\Entity\User;


#[Route('/ad')]
final class AdController extends AbstractController
{
    // ========== INDEX (PAGE D'ACCUEIL ADMIN) ==========
    #[Route('', name: 'app_ad')]
    public function index(UserRepository $userRepo): Response
    {
        return $this->render('dashboard_ad/index.html.twig', [
            'controller_name' => 'AdController',
        ]);
    }

    // ========== PAGE LISTE PRODUITS ==========
    #[Route('/produits', name: 'ad_produits_liste')]
    public function produits(ProduitRepository $produitRepo): Response
    {
        $produits = $produitRepo->findBy([], ['id_produit' => 'DESC']);

        $totalProduits = count($produits);
        $produitsDisponibles = $produitRepo->count(['status_produit' => 'Disponible']);
        $produitsRupture = $produitRepo->count(['status_produit' => 'Rupture']);

        $stockTotal = 0;
        foreach ($produits as $p) {
            $stockTotal += (int) $p->getQuantiteProduit();
        }

        return $this->render('dashboard_ad/produits_liste.html.twig', [
            'produits' => $produits,
            'totalProduits' => $totalProduits,
            'produitsDisponibles' => $produitsDisponibles,
            'produitsRupture' => $produitsRupture,
            'stockTotal' => $stockTotal,
        ]);
    }

    // ========== PAGE LISTE COMMANDES ==========
    #[Route('/commandes', name: 'ad_commandes_liste')]
    public function commandes(CommandeRepository $commandeRepo, EntityManagerInterface $em): Response
    {
        $commandes = $commandeRepo->findBy([], ['date_creation_commande' => 'DESC']);

        $totalCommandes = count($commandes);
        $enAttente = $commandeRepo->count(['statut_commande' => 'En attente']);
        $enCours = $commandeRepo->count(['statut_commande' => 'En cours']);
        $enLivraison = $commandeRepo->count(['statut_commande' => 'En livraison']);
        $livrees = $commandeRepo->count(['statut_commande' => 'Livrée']);
        $annulees = $commandeRepo->count(['statut_commande' => 'Annulée']);

        // CA total (payées uniquement)
        $caTotal = 0.0;
        try {
            $caTotal = (float) ($em->createQuery(
                'SELECT COALESCE(SUM(c.montant_total), 0) FROM App\Entity\Commande c WHERE c.paid_at IS NOT NULL'
            )->getSingleScalarResult() ?? 0);
        } catch (\Exception $e) {}

        // CA ce mois (payées uniquement)
        $caMois = 0.0;
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('m');

        foreach ($commandes as $commande) {
            if ($commande->getPaidAt()) {
                $y = (int) $commande->getPaidAt()->format('Y');
                $m = (int) $commande->getPaidAt()->format('m');
                if ($y === $currentYear && $m === $currentMonth) {
                    $caMois += (float) $commande->getMontantTotal();
                }
            }
        }

        return $this->render('dashboard_ad/commandes_liste.html.twig', [
            'commandes' => $commandes,
            'totalCommandes' => $totalCommandes,
            'enAttente' => $enAttente,
            'enCours' => $enCours,
            'enLivraison' => $enLivraison,
            'livrees' => $livrees,
            'annulees' => $annulees,
            'caTotal' => $caTotal,
            'caMois' => $caMois,
        ]);
    }

    // ========== PAGE STATISTIQUES ==========
    #[Route('/statistiques', name: 'ad_statistiques')]
    public function statistiques(
        UserRepository $userRepo,
        ProduitRepository $produitRepo,
        CommandeRepository $commandeRepo,
        EntityManagerInterface $em
    ): Response {
        $totalUsers = $userRepo->count([]);
        $totalProduits = $produitRepo->count([]);
        $totalCommandes = $commandeRepo->count([]);

        // CA total (payées uniquement)
        $caTotal = 0.0;
        try {
            $caTotal = (float) ($em->createQuery(
                'SELECT COALESCE(SUM(c.montant_total), 0) FROM App\Entity\Commande c WHERE c.paid_at IS NOT NULL'
            )->getSingleScalarResult() ?? 0);
        } catch (\Exception $e) {}

        // CA ce mois (optionnel)
        $caMois = 0.0;
        $nowY = (int) date('Y');
        $nowM = (int) date('m');

        try {
            $commandesPayees = $em->createQuery(
                'SELECT c FROM App\Entity\Commande c WHERE c.paid_at IS NOT NULL'
            )->getResult();

            foreach ($commandesPayees as $c) {
                $y = (int) $c->getPaidAt()->format('Y');
                $m = (int) $c->getPaidAt()->format('m');
                if ($y === $nowY && $m === $nowM) {
                    $caMois += (float) $c->getMontantTotal();
                }
            }
        } catch (\Exception $e) {}

        // ✅ CA par mois (12 derniers mois) — MySQL/MariaDB
        // Retour: [ ['month' => 'Jan', 'total' => 1200], ... ]
        $caParMois = [];
        try {
            // 12 derniers mois (YYYY-MM)
            $months = [];
            $cursor = new \DateTimeImmutable('first day of this month');
            for ($i = 11; $i >= 0; $i--) {
                $d = $cursor->modify("-{$i} months");
                $months[] = $d->format('Y-m');
            }

            // Query DB (group by YYYY-MM)
            $rows = $em->getConnection()->fetchAllAssociative("
                SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, COALESCE(SUM(montant_total), 0) AS total
                FROM commande
                WHERE paid_at IS NOT NULL
                GROUP BY ym
                ORDER BY ym ASC
            ");

            $map = [];
            foreach ($rows as $r) {
                $map[$r['ym']] = (float) $r['total'];
            }

            // Format final (labels courts)
            $moisLabel = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];
            foreach ($months as $ym) {
                [$y, $m] = explode('-', $ym);
                $mInt = (int) $m;
                $caParMois[] = [
                    'month' => $moisLabel[$mInt - 1],
                    'total' => $map[$ym] ?? 0.0,
                ];
            }
        } catch (\Exception $e) {
            // si erreur => chart vide
            $caParMois = [];
        }

        // ✅ Top 5 produits les plus vendus (uniquement commandes payées)
        $topProduits = [];
        try {
            $topProduits = $em->createQuery(
                'SELECT p.nom_produit AS nom_produit, SUM(lc.quantite_commandee) as total_vendu
                 FROM App\Entity\LigneCommande lc
                 JOIN lc.produit p
                 JOIN lc.commande c
                 WHERE c.paid_at IS NOT NULL
                 GROUP BY p.id_produit, p.nom_produit
                 ORDER BY total_vendu DESC'
            )->setMaxResults(5)->getResult();
        } catch (\Exception $e) {}

        // Produits en stock faible
        $produitsRupture = $produitRepo->createQueryBuilder('p')
            ->where('p.quantite_produit <= 5')
            ->orderBy('p.quantite_produit', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // Commandes par statut
        $commandesParStatut = [
            'En attente' => $commandeRepo->count(['statut_commande' => 'En attente']),
            'En cours' => $commandeRepo->count(['statut_commande' => 'En cours']),
            'En livraison' => $commandeRepo->count(['statut_commande' => 'En livraison']),
            'Livrée' => $commandeRepo->count(['statut_commande' => 'Livrée']),
            'Annulée' => $commandeRepo->count(['statut_commande' => 'Annulée']),
        ];

        // Dernières commandes
        $dernieresCommandes = $commandeRepo->findBy([], ['date_creation_commande' => 'DESC'], 5);

        return $this->render('dashboard_ad/statistiques.html.twig', [
            'totalUsers' => $totalUsers,
            'totalProduits' => $totalProduits,
            'totalCommandes' => $totalCommandes,
            'caTotal' => $caTotal,
            'caMois' => $caMois,
            'caParMois' => $caParMois,
            'topProduits' => $topProduits,
            'produitsRupture' => $produitsRupture,
            'commandesParStatut' => $commandesParStatut,
            'dernieresCommandes' => $dernieresCommandes,
        ]);
    }




    /////////////////////////////////User
    /* =========================
     *  ADMIN - STAFF REQUESTS REVIEW
     * ========================= */
    #[Route('/ad/staff-requests', name: 'admin_staff_requests_index', methods: ['GET'])]
    public function staffRequestsIndex(UserRepository $repo): Response
    {
        $pending = $repo->createQueryBuilder('u')
            ->andWhere('u.staffRequestStatus = :p')
            ->setParameter('p', 'PENDING')
            ->orderBy('u.staffRequestedAt', 'DESC')
            ->getQuery()->getResult();

        return $this->render('dashboard_ad/staff_requests/index.html.twig', [
            'rows' => $pending,
        ]);
    }

    #[Route('/ad/staff-requests/{id}/doc/{i}', name: 'admin_staff_request_doc', requirements: ['id' => '\\d+', 'i' => '\\d+'], methods: ['GET'])]
    public function staffRequestDoc(User $user, int $i): Response
    {
        $docs = $user->getStaffDocuments();
        if (!$docs || !isset($docs['files']) || !isset($docs['files'][$i])) {
            throw $this->createNotFoundException('Document introuvable.');
        }
        $rel = $docs['files'][$i]['stored'] ?? null;
        if (!$rel) {
            throw $this->createNotFoundException('Chemin introuvable.');
        }
        $full = $this->getParameter('kernel.project_dir') . '/var/' . $rel;
        if (!is_file($full)) {
            throw $this->createNotFoundException('Fichier manquant.');
        }
        return $this->file($full, $docs['files'][$i]['original'] ?? basename($full));
    }

    #[Route('/ad/staff-requests/{id}/approve', name: 'admin_staff_request_approve', methods: ['POST'])]
    public function staffRequestApprove(User $user, Request $request, EntityManagerInterface $em): Response
    {
        // CSRF disabled per request: admin-only action
        if ($user->getStaffRequestStatus() !== 'PENDING') {
            $this->addFlash('danger', 'Demande non valide.');
            return $this->redirectToRoute('admin_staff_requests_index');
        }
        $role = strtoupper((string) $request->request->get('role_systeme', ''));
        $type = strtoupper((string) $request->request->get('type_staff', ''));

        if ($role === 'ADMIN') {
            $user->setRoleSysteme('ADMIN');
            $user->setTypeStaff(null);
        } elseif ($role === 'STAFF') {
            $user->setRoleSysteme('STAFF');
            $user->setTypeStaff($type ?: null);
        } else {
            $this->addFlash('danger', 'Rôle invalide.');
            return $this->redirectToRoute('admin_staff_requests_index');
        }

        $user->setStaffRequestStatus('APPROVED');
        $user->setStaffReviewedAt(new \DateTime());
        $user->setStaffReviewedBy($this->getUser()?->getId());
        $user->setStaffRequestReason(null);
        $em->flush();

        // Notify user by Brevo API (same logic as registration)
        if ($user->getEmailUser()) {
            $subject = 'Votre demande de rôle a été approuvée';
            $html = "<div style='font-family:Arial,sans-serif'>"
                ."<h2>Demande approuvée ✅</h2>"
                .sprintf("<p>Bonjour %s,</p>", htmlspecialchars((string) $user->getPrenom()))
                .sprintf("<p>Votre demande de rôle a été approuvée.<br>Rôle: <strong>%s</strong><br>Type staff: <strong>%s</strong></p>", htmlspecialchars((string) ($user->getRoleSysteme() ?: '—')), htmlspecialchars((string) ($user->getTypeStaff() ?: '—')))
                ."<p>Vous pouvez vous reconnecter pour profiter de vos nouveaux accès.</p>"
                ."<p style='color:#666;font-size:12px'>— MedFlow</p>"
                ."</div>";
            try { $this->sendBrevoEmail($user->getEmailUser(), trim(($user->getPrenom() ?? '').' '.($user->getNom() ?? '')), $subject, $html); } catch (\Throwable $e) { /* ignore */ }
        }

        $this->addFlash('success', 'Demande approuvée et rôle mis à jour.');
        return $this->redirectToRoute('admin_staff_requests_index');
    }

    #[Route('/ad/staff-requests/{id}/reject', name: 'admin_staff_request_reject', methods: ['POST'])]
    public function staffRequestReject(User $user, Request $request, EntityManagerInterface $em): Response
    {
        // CSRF disabled per request: admin-only action
        if ($user->getStaffRequestStatus() !== 'PENDING') {
            $this->addFlash('danger', 'Demande non valide.');
            return $this->redirectToRoute('admin_staff_requests_index');
        }
        $user->setStaffRequestStatus('REJECTED');
        $reason = trim((string) $request->request->get('reason', ''));
        $user->setStaffRequestReason($reason ?: null);
        $user->setStaffReviewedAt(new \DateTime());
        $user->setStaffReviewedBy($this->getUser()?->getId());
        $em->flush();

        // Notify user by Brevo API (same logic as registration)
        if ($user->getEmailUser()) {
            $subject = 'Votre demande de rôle a été refusée';
            $html = "<div style='font-family:Arial,sans-serif'>"
                ."<h2>Demande refusée ❌</h2>"
                .sprintf("<p>Bonjour %s,</p>", htmlspecialchars((string) $user->getPrenom()))
                .sprintf("<p>Votre demande de rôle a été refusée.<br>Motif: <strong>%s</strong></p>", htmlspecialchars((string) ($user->getStaffRequestReason() ?: 'Non précisé')))
                ."<p>Vous pouvez soumettre une nouvelle demande avec plus de détails ou contacter l'administration.</p>"
                ."<p style='color:#666;font-size:12px'>— MedFlow</p>"
                ."</div>";
            try { $this->sendBrevoEmail($user->getEmailUser(), trim(($user->getPrenom() ?? '').' '.($user->getNom() ?? '')), $subject, $html); } catch (\Throwable $e) { /* ignore */ }
        }

        $this->addFlash('info', 'Demande refusée.');
        return $this->redirectToRoute('admin_staff_requests_index');
    }

    private function sendBrevoEmail(string $toEmail, string $toName, string $subject, string $html): array
    {
        $apiKey = $_ENV['BREVO_API_KEY'] ?? null;
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? null;
        $senderName  = $_ENV['BREVO_SENDER_NAME'] ?? 'MedFlow';

        if (!$apiKey || !$senderEmail) {
            throw new \Exception("Config Brevo manquante: BREVO_API_KEY ou BREVO_SENDER_EMAIL.");
        }

        $payload = [
            'sender' => [
                'name' => $senderName,
                'email' => $senderEmail,
            ],
            'to' => [[
                'email' => $toEmail,
                'name' => $toName,
            ]],
            'subject' => $subject,
            'htmlContent' => $html,
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Erreur CURL: ' . $err);
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \Exception("Brevo error ($httpCode): " . $response);
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['raw' => $response, 'httpCode' => $httpCode];
    }

    /* =========================
     *  ADMIN - PATIENTS LIST
     * ========================= */
    #[Route('/ad/patients', name: 'admin_patients_index', methods: ['GET'])]
    public function patientsIndex(Request $request, UserRepository $repo): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $verified = (string) $request->query->get('verified', ''); // '' | '1' | '0'
        $sort = (string) $request->query->get('sort', 'recent');
        $triageFilter = (string) $request->query->get('triage', '');
        $alertFilter = (string) $request->query->get('alert', '');

        $patients = $repo->findPatientsWithFilters([
            'q' => $q,
            'verified' => $verified === '' ? null : (bool) $verified,
        ]);

        $rows = [];
        $stats = [
            'total' => 0,
            'verified' => 0,
            'unverified' => 0,
            'phone_valid' => 0,
            'phone_invalid' => 0,
            'phone_pending' => 0,
            'blocked' => 0,
            'expired_links' => 0,
            'priorities' => ['CRITIQUE' => 0, 'HAUTE' => 0, 'MOYENNE' => 0, 'OK' => 0],
        ];

        foreach ($patients as $p) {
            $t = $this->buildTriage($p);

            $stats['total']++;
            $p->isVerified() ? $stats['verified']++ : $stats['unverified']++;
            $t['phoneOk'] ? $stats['phone_valid']++ : ($t['phoneOk'] === false ? $stats['phone_invalid']++ : $stats['phone_pending']++);
            if ($t['blocked']) $stats['blocked']++;
            if ($t['expired']) $stats['expired_links']++;
            $stats['priorities'][$t['priority']['level']]++;

            if ($triageFilter && $t['priority']['level'] !== $triageFilter) continue;

            if ($alertFilter) {
                $hasAlert = false;
                foreach ($t['alerts'] as $a) {
                    if ($a['key'] === $alertFilter) { $hasAlert = true; break; }
                }
                if (!$hasAlert) continue;
            }

            $rows[] = ['p' => $p, 'triage' => $t];
        }

        usort($rows, fn($a, $b) => $b['triage']['score'] <=> $a['triage']['score']);
        $top = array_slice($rows, 0, 10);

        $tips = [];
        if ($stats['unverified'] > 0) $tips[] = "Relancer les non-vérifiés via Brevo (email de vérification).";
        if ($stats['expired_links'] > 0) $tips[] = "Beaucoup de liens expirés : proposer un bouton de renvoi en masse.";
        if ($stats['phone_invalid'] > 0) $tips[] = "Téléphones invalides : demander correction lors de la prochaine connexion.";
        if ($stats['blocked'] > 0) $tips[] = "Comptes bloqués : vérifier l’origine et décider déblocage/suppression.";
        if ($tips === []) $tips[] = "Aucune anomalie critique détectée.";

        return $this->render('dashboard_ad/patients/index.html.twig', [
            'rows' => $rows,
            'q' => $q,
            'verified' => $verified,
            'sort' => $sort,
            'triage' => $triageFilter,
            'alert' => $alertFilter,
            'stats' => $stats,
            'tips' => $tips,
            'top' => $top,
        ]);
    }

    /* =========================
     *  ADMIN - PATIENT SHOW
     * ========================= */
    #[Route('/ad/patients/{id}', name: 'admin_patients_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function patientsShow(User $patient): Response
    {
        if ($patient->getRoleSysteme() !== 'PATIENT') {
            throw $this->createNotFoundException('Patient introuvable.');
        }

        return $this->render('dashboard_ad/patients/show.html.twig', [
            'patient' => $patient,
        ]);
    }

    /* =========================
     *  ADMIN - RESEND VERIFICATION
     * ========================= */
    #[Route('/ad/patients/{id}/resend-verification', name: 'admin_patient_resend_verification', methods: ['POST'])]
    public function resendPatientVerification(User $patient, EntityManagerInterface $em, LoggerInterface $logger): Response
    {
        if ($patient->getRoleSysteme() !== 'PATIENT') {
            throw $this->createNotFoundException('Patient introuvable.');
        }

        if ($patient->isVerified()) {
            $this->addFlash('info', 'Compte déjà vérifié.');
            return $this->redirectToRoute('admin_patients_show', ['id' => $patient->getId()]);
        }

        $patient->setVerificationToken(bin2hex(random_bytes(32)));
        $patient->setTokenExpiresAt((new \DateTime())->modify('+24 hours'));
        $em->flush();

        $this->sendVerificationEmail($patient, $logger);

        $this->addFlash('success', 'Email de vérification renvoyé ✅');
        return $this->redirectToRoute('admin_patients_show', ['id' => $patient->getId()]);
    }

    /* =========================
     *  ADMIN - STATS
     * ========================= */
    #[Route('/ad/patients/stats', name: 'admin_patients_stats', methods: ['GET'])]
    public function patientsStats(UserRepository $repo): Response
    {
        $patients = $repo->createQueryBuilder('u')
            ->andWhere('u.roleSysteme = :role')
            ->setParameter('role', 'PATIENT')
            ->orderBy('u.id', 'DESC')
            ->getQuery()->getResult();

        $stats = [
            'total' => 0,
            'verified' => 0,
            'unverified' => 0,
            'phone_invalid' => 0,
            'blocked' => 0,
            'expired_links' => 0,
            'priorities' => ['CRITIQUE' => 0, 'HAUTE' => 0, 'MOYENNE' => 0, 'OK' => 0],
        ];

        foreach ($patients as $p) {
            $t = $this->buildTriage($p);
            $stats['total']++;
            $p->isVerified() ? $stats['verified']++ : $stats['unverified']++;
            if (!$t['phoneOk']) $stats['phone_invalid']++;
            if ($t['blocked']) $stats['blocked']++;
            if ($t['expired']) $stats['expired_links']++;
            $stats['priorities'][$t['priority']['level']]++;
        }

        $chartPriorities = [
            $stats['priorities']['CRITIQUE'],
            $stats['priorities']['HAUTE'],
            $stats['priorities']['MOYENNE'],
            $stats['priorities']['OK'],
        ];
        $chartVerification = [$stats['verified'], $stats['unverified']];
        $chartDataQuality = [$stats['phone_invalid'], $stats['blocked'], $stats['expired_links']];

        $tips = [];
        if ($stats['unverified'] > 0 && $stats['unverified'] >= ($stats['total'] * 0.4)) {
            $tips[] = "Taux de non-vérification élevé : prévoir une relance email ciblée (Brevo).";
        }
        if ($stats['phone_invalid'] > 0) $tips[] = "Beaucoup de téléphones invalides : renforcer la validation + proposer correction en front.";
        if ($stats['blocked'] > 0) $tips[] = "Présence de comptes bloqués : vérifier tentatives frauduleuses / spam.";
        if ($tips === []) $tips[] = "Situation globale saine : continuer le monitoring.";

        return $this->render('dashboard_ad/patients/stats.html.twig', [
            'stats' => $stats,
            'chartPriorities' => $chartPriorities,
            'chartVerification' => $chartVerification,
            'chartDataQuality' => $chartDataQuality,
            'tips' => $tips,
        ]);
    }

    /* =========================
     *  ADMIN - PDF REPORT
     * ========================= */
    #[Route('/ad/patients/report/pdf', name: 'admin_patients_report_pdf', methods: ['GET'])]
    public function generatePatientReport(UserRepository $repo): Response
    {
        $patients = $repo->createQueryBuilder('u')
            ->andWhere('u.roleSysteme = :role')
            ->setParameter('role', 'PATIENT')
            ->orderBy('u.id', 'DESC')
            ->getQuery()->getResult();

        $rows = [];
        $stats = [
            'total' => 0,
            'verified' => 0,
            'unverified' => 0,
            'phone_invalid' => 0,
            'blocked' => 0,
            'expired_links' => 0,
            'priorities' => ['CRITIQUE' => 0, 'HAUTE' => 0, 'MOYENNE' => 0, 'OK' => 0],
        ];

        foreach ($patients as $p) {
            $t = $this->buildTriage($p);
            $stats['total']++;
            $p->isVerified() ? $stats['verified']++ : $stats['unverified']++;
            if (!$t['phoneOk']) $stats['phone_invalid']++;
            if ($t['blocked']) $stats['blocked']++;
            if ($t['expired']) $stats['expired_links']++;
            $stats['priorities'][$t['priority']['level']]++;
            $rows[] = ['p' => $p, 'triage' => $t];
        }

        usort($rows, fn($a, $b) => $b['triage']['score'] <=> $a['triage']['score']);
        $top = array_slice($rows, 0, 10);

        $tips = [];
        if ($stats['unverified'] > 0) $tips[] = "Relancer les non-vérifiés via Brevo (email de vérification).";
        if ($stats['expired_links'] > 0) $tips[] = "Beaucoup de liens expirés : proposer un bouton de renvoi en masse.";
        if ($stats['phone_invalid'] > 0) $tips[] = "Téléphones invalides : demander correction lors de la prochaine connexion.";
        if ($stats['blocked'] > 0) $tips[] = "Comptes bloqués : vérifier l’origine et décider déblocage/suppression.";
        if ($tips === []) $tips[] = "Aucune anomalie critique détectée.";

        $html = $this->renderView('dashboard_ad/patients/report_pdf.html.twig', [
            'stats' => $stats,
            'top' => $top,
            'tips' => $tips,
            'generatedAt' => new \DateTimeImmutable(),
            'staffName' => $this->getUser()?->getPrenom(),
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();

        $response = new Response($output);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'rapport_qualite_patients.pdf'
        );

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /* =========================
     *  ADMIN - EMAIL CHECK (HUNTER)
     * ========================= */
    #[Route('/ad/patients/{id}/email-check', name: 'admin_patient_email_check', methods: ['POST'])]
    public function emailCheck(
        User $patient,
        HttpClientInterface $http,
        Request $request,
        CsrfTokenManagerInterface $csrf
    ): JsonResponse {
        if ($patient->getRoleSysteme() !== 'PATIENT') {
            return $this->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $tokenValue = (string) $request->request->get('_token', '');
        if (!$csrf->isTokenValid(new CsrfToken('email_check_'.$patient->getId(), $tokenValue))) {
            return $this->json(['ok' => false, 'error' => 'Invalid CSRF'], 403);
        }

        $email = (string) $patient->getEmailUser();
        if ($email === '') {
            return $this->json(['ok' => false, 'error' => 'Email vide'], 400);
        }

        $session = $request->getSession();
        $k = 'email_check_last_'.$patient->getId();
        $last = (int) $session->get($k, 0);
        if (time() - $last < 30) {
            return $this->json(['ok' => false, 'error' => 'Patiente 30s avant de relancer.'], 429);
        }
        $session->set($k, time());

        $apiKey = $_ENV['HUNTER_API_KEY'] ?? '';
        if ($apiKey === '') {
            return $this->json(['ok' => false, 'error' => 'HUNTER_API_KEY manquant dans .env'], 500);
        }

        try {
            $res = $http->request('GET', 'https://api.hunter.io/v2/email-verifier', [
                'query' => ['email' => $email, 'api_key' => $apiKey],
                'timeout' => 10,
            ]);

            $raw = $res->toArray(false);
            $d = $raw['data'] ?? [];

            $status     = strtoupper((string) ($d['status'] ?? 'UNKNOWN'));
            $result     = strtoupper((string) ($d['result'] ?? 'UNKNOWN'));
            $mx         = (bool) ($d['mx_records'] ?? false);
            $smtpServer = (bool) ($d['smtp_server'] ?? false);
            $smtpCheck  = (bool) ($d['smtp_check'] ?? false);
            $webmail    = (bool) ($d['webmail'] ?? false);
            $disposable = (bool) ($d['disposable'] ?? false);
            $acceptAll  = (bool) ($d['accept_all'] ?? false);
            $block      = (bool) ($d['block'] ?? false);

            $score = 100;
            if ($status === 'INVALID' || $result === 'UNDELIVERABLE' || $block) {
                $score = 5;
            } else {
                if (!$mx)         $score -= 25;
                if (!$smtpServer) $score -= 20;
                if (!$smtpCheck)  $score -= 20;
                if ($acceptAll)   $score -= 10;
                if ($disposable)  $score -= 35;
                if ($webmail)     $score -= 5;
            }
            $score = max(0, min(100, (int) $score));

            $risk = 'LOW';
            if ($status === 'INVALID' || $result === 'UNDELIVERABLE' || $score < 35) $risk = 'HIGH';
            elseif ($disposable || $score < 70) $risk = 'MEDIUM';

            $impactPriority = match ($risk) {
                'HIGH' => 'HAUTE',
                'MEDIUM' => 'MOYENNE',
                default => 'OK',
            };

            $advice = match ($risk) {
                'HIGH' => "Email probablement invalide. Demander une correction.",
                'MEDIUM' => $disposable ? "Email jetable détecté. Compte à surveiller." : "Qualité moyenne. Recommandé de confirmer l’email.",
                default => "Email OK.",
            };

            $deliverability = 'UNKNOWN';
            if ($status === 'VALID') $deliverability = 'DELIVERABLE';
            if ($status === 'INVALID' || $result === 'UNDELIVERABLE') $deliverability = 'UNDELIVERABLE';

            return $this->json([
                'ok' => true,
                'provider' => 'hunter',
                'email' => $email,
                'status' => $status,
                'deliverability' => $deliverability,
                'disposable' => $disposable,
                'webmail' => $webmail,
                'smtp_check' => $smtpCheck,
                'mx_records' => $mx,
                'accept_all' => $acceptAll,
                'quality_score' => $score,
                'risk' => $risk,
                'impact_priority' => $impactPriority,
                'advice' => $advice,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => 'Erreur API: '.$e->getMessage()], 500);
        }
    }

    /* =========================
     *  ADMIN - BLOCK / UNBLOCK PATIENT
     * ========================= */
    #[Route('/ad/patients/{id}/block', name: 'admin_patient_block', methods: ['POST'])]
    public function blockPatient(
        User $patient,
        EntityManagerInterface $em,
        Request $request,
        CsrfTokenManagerInterface $csrf
    ): Response {
        if ($patient->getRoleSysteme() !== 'PATIENT') {
            throw $this->createNotFoundException('Patient introuvable.');
        }

        $tokenValue = (string) $request->request->get('_token', '');
        if (!$csrf->isTokenValid(new CsrfToken('patient_block_'.$patient->getId(), $tokenValue))) {
            throw $this->createAccessDeniedException('CSRF token invalide');
        }

        $reason = trim((string) $request->request->get('reason', ''));
        if ($reason === '') {
            $this->addFlash('danger', 'Veuillez saisir une raison du blocage.');
            return $this->redirectToRoute('admin_patients_index');
        }

        $patient->setStatutCompte('BLOQUE');
        $patient->setBanReason($reason);
        $patient->setBannedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', 'Patient bloqué: '.$reason);
        return $this->redirectToRoute('admin_patients_index');
    }

    #[Route('/ad/patients/{id}/unblock', name: 'admin_patient_unblock', methods: ['POST'])]
    public function unblockPatient(
        User $patient,
        EntityManagerInterface $em,
        Request $request,
        CsrfTokenManagerInterface $csrf
    ): Response {
        if ($patient->getRoleSysteme() !== 'PATIENT') {
            throw $this->createNotFoundException('Patient introuvable.');
        }

        $tokenValue = (string) $request->request->get('_token', '');
        if (!$csrf->isTokenValid(new CsrfToken('patient_unblock_'.$patient->getId(), $tokenValue))) {
            throw $this->createAccessDeniedException('CSRF token invalide');
        }

        $patient->setStatutCompte('ACTIF');
        $patient->setBanReason(null);
        $patient->setBannedAt(null);
        $em->flush();

        $this->addFlash('success', 'Patient débloqué.');
        return $this->redirectToRoute('admin_patients_index');
    }

    /* =========================
     *  Helpers (copied from StaffController)
     * ========================= */
    private function sendVerificationEmail(User $user, LoggerInterface $logger): void
    {
        $apiKey = $_ENV['BREVO_API_KEY'];
        $sender = $_ENV['BREVO_SENDER_EMAIL'];
        $appUrl = $_ENV['APP_URL'];

        $link = $appUrl . '/verify-email?token=' . $user->getVerificationToken();

        $payload = [
            'sender' => ['email' => $sender, 'name' => 'MedFlow'],
            'to' => [['email' => $user->getEmailUser()]],
            'subject' => 'Vérification de votre compte',
            'htmlContent' => "<p><a href='$link'>Vérifier mon email</a></p>",
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['api-key: '.$apiKey, 'Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $logger->info('Admin resend verification email', [
            'email' => $user->getEmailUser(),
            'response' => $response,
        ]);
    }

    private function isPhoneValid(?string $phone): bool
    {
        if (!$phone) return false;
        $p = preg_replace('/\s+/', '', $phone);
        if (preg_match('/^\d{8}$/', $p)) return true;
        if (preg_match('/^\+216\d{8}$/', $p)) return true;
        return false;
    }

    private function isBlocked(?string $statutCompte): bool
    {
        if (!$statutCompte) return false;
        return strtoupper($statutCompte) === 'BLOQUE';
    }

    private function buildTriage(User $p): array
    {
        $alerts = [];
        $expired = false;

        if (!$p->isVerified() && $p->getTokenExpiresAt() instanceof \DateTimeInterface) {
            $expired = $p->getTokenExpiresAt() < new \DateTimeImmutable();
        }

        if (!$p->isVerified()) {
            $alerts[] = $expired
                ? ['key' => 'unverified_expired', 'label' => 'Non vérifié (lien expiré)']
                : ['key' => 'unverified', 'label' => 'Non vérifié'];
        }

        $phoneOk = $this->isPhoneValid($p->getTelephoneUser());
        if (!$phoneOk) $alerts[] = ['key' => 'phone_invalid', 'label' => 'Téléphone invalide'];

        $blocked = $this->isBlocked($p->getStatutCompte());
        if ($blocked) $alerts[] = ['key' => 'blocked', 'label' => 'Compte bloqué'];

        $score = 0;
        if ($blocked) $score += 3;
        if (!$p->isVerified()) $score += 2;
        if ($expired) $score += 2;
        if (!$phoneOk) $score += 1;

        if ($score >= 5) $priority = ['level' => 'CRITIQUE', 'badge' => 'danger'];
        elseif ($score >= 3) $priority = ['level' => 'HAUTE', 'badge' => 'warning'];
        elseif ($score >= 1) $priority = ['level' => 'MOYENNE', 'badge' => 'info'];
        else $priority = ['level' => 'OK', 'badge' => 'success'];

        return [
            'alerts' => $alerts,
            'priority' => $priority,
            'score' => $score,
            'phoneOk' => $phoneOk,
            'blocked' => $blocked,
            'expired' => $expired,
        ];
    }
    
// ✅ READ-ONLY: Liste événements (dans design Duralux)
#[Route('/ad/evenements', name: 'ad_evenements_liste', methods: ['GET'])]
public function evenementsListe(Request $request, EvenementRepository $repo): Response
{
    $sort = (string) $request->query->get('sort', 'date_desc');
    $orderBy = ['date_debut_event' => 'DESC'];

    switch ($sort) {
        case 'date_asc':  $orderBy = ['date_debut_event' => 'ASC']; break;
        case 'titre_asc': $orderBy = ['titre_event' => 'ASC']; break;
        case 'titre_desc':$orderBy = ['titre_event' => 'DESC']; break;
        case 'ville_asc': $orderBy = ['ville_event' => 'ASC']; break;
        case 'type_asc':  $orderBy = ['type_event' => 'ASC']; break;
    }

    $evenements = $repo->findBy([], $orderBy);

    return $this->render('dashboard_ad/indexevent.html.twig', [
        'section' => 'evenements',
        'evenements' => $evenements,
        'sort' => $sort,
    ]);
}

// ✅ READ-ONLY: Demandes/Participants (liste par événement + stats)
#[Route('/ad/participants', name: 'ad_participants', methods: ['GET'])]
public function participants(EvenementRepository $repo): Response
{
    $events = $repo->findBy([], ['date_debut_event' => 'DESC']);

    $totalPending = 0;
    $totalAccepted = 0;
    $totalRefused = 0;

    foreach ($events as $ev) {
        $totalPending  += $ev->countDemandesByStatus('pending');
        $totalAccepted += $ev->countDemandesByStatus('accepted');
        $totalRefused  += $ev->countDemandesByStatus('refused');
    }

    return $this->render('dashboard_ad/indexevent.html.twig', [
        'section' => 'participants',
        'events' => $events,
        'totalPending' => $totalPending,
        'totalAccepted' => $totalAccepted,
        'totalRefused' => $totalRefused,
    ]);
}

// ✅ READ-ONLY: Ressources (liste + tri + search)
#[Route('/ad/ressources', name: 'ad_ressources', methods: ['GET'])]
public function ressources(Request $request, RessourceRepository $repo): Response
{
    $search = trim((string) $request->query->get('search', ''));
    $sort = (string) $request->query->get('sort', 'date_desc');

    $orderBy = ['date_creation_ressource' => 'DESC'];
    switch ($sort) {
        case 'date_asc': $orderBy = ['date_creation_ressource' => 'ASC']; break;
        case 'nom_asc':  $orderBy = ['nom_ressource' => 'ASC']; break;
        case 'nom_desc': $orderBy = ['nom_ressource' => 'DESC']; break;
        case 'cat_asc':  $orderBy = ['categorie_ressource' => 'ASC']; break;
        case 'type_asc': $orderBy = ['type_ressource' => 'ASC']; break;
    }

    // si tu as searchAdmin() dans repo
    if ($search !== '' && method_exists($repo, 'searchAdmin')) {
        $ressources = $repo->searchAdmin($search, $orderBy);
    } else {
        $ressources = $repo->findBy([], $orderBy);
    }

    return $this->render('dashboard_ad/indexevent.html.twig', [
        'section' => 'ressources',
        'ressources' => $ressources,
        'search' => $search,
        'sort' => $sort,
    ]);
}

// ✅ READ-ONLY: Stats événements (tu peux garder tes stats JS/AJAX si tu veux après)
#[Route('/ad/stats-evenements', name: 'ad_stats_evenements', methods: ['GET'])]
public function statsEvenements(EvenementRepository $repo): Response
{
    $events = $repo->findAll();

    $byType = [];
    $byVille = [];
    $byStatut = [];
    $demandes = ['total'=>0,'accepted'=>0,'pending'=>0,'refused'=>0];

    foreach ($events as $ev) {
        $type = $ev->getTypeEvent() ?? 'N/A';
        $ville = $ev->getVilleEvent() ?? 'N/A';
        $statut = $ev->getStatutEvent() ?? 'N/A';

        $byType[$type] = ($byType[$type] ?? 0) + 1;
        $byVille[$ville] = ($byVille[$ville] ?? 0) + 1;
        $byStatut[$statut] = ($byStatut[$statut] ?? 0) + 1;

        foreach ($ev->getDemandesJson() as $d) {
            $demandes['total']++;
            $s = $d['status'] ?? 'pending';
            if (isset($demandes[$s])) $demandes[$s]++;
        }
    }

    return $this->render('dashboard_ad/indexevent.html.twig', [
        'section' => 'stats_evenements',
        'byType' => $byType,
        'byVille' => $byVille,
        'byStatut' => $byStatut,
        'demandes' => $demandes,
    ]);
}

// ✅ READ-ONLY: Stats ressources (KPI + top)
#[Route('/ad/stats-ressources', name: 'ad_stats_ressources', methods: ['GET'])]
public function statsRessources(RessourceRepository $repo): Response
{
    $kpi = method_exists($repo, 'getKpiStats') ? $repo->getKpiStats() : [];
    $byType = method_exists($repo, 'countByType') ? $repo->countByType() : [];
    $byCategorie = method_exists($repo, 'countByCategorie') ? $repo->countByCategorie() : [];
    $topEvents = method_exists($repo, 'topEvenementsByRessources') ? $repo->topEvenementsByRessources(5) : [];

    return $this->render('dashboard_ad/indexevent.html.twig', [
        'section' => 'stats_ressources',
        'kpi' => $kpi,
        'byTypeR' => $byType,
        'byCategorieR' => $byCategorie,
        'topEventsR' => $topEvents,
    ]);
}




#[Route('/ad/consultation', name: 'app_ad_consultation')]
    public function consultation(
        UserRepository $userRepo,
        \App\Repository\RendezVousRepository $rendezVousRepo,
        \App\Repository\FicheMedicaleRepository $ficheRepo,
        \App\Repository\PrescriptionRepository $prescRepo
    ): Response {
        // Get all doctors (STAFF, RESP_PATIENTS)
        $doctors = $userRepo->findDoctorsRespPatients();
        $doctorsData = [];
        foreach ($doctors as $doctor) {
            $rendezvous = $rendezVousRepo->findBy(['staff' => $doctor], ['datetime' => 'DESC']);
            $fiches = $ficheRepo->findFichesByStaffId($doctor->getId());
            // Get all prescriptions for this doctor's fiches
            $prescriptions = [];
            foreach ($fiches as $fiche) {
                foreach ($fiche->getPrescriptions() as $presc) {
                    $prescriptions[] = $presc;
                }
            }
            $doctorsData[] = [
                'id' => $doctor->getId(),
                'nom' => $doctor->getNom(),
                'prenom' => $doctor->getPrenom(),
                'typeStaff' => $doctor->getTypeStaff(),
                'telephoneUser' => $doctor->getTelephoneUser(),
                'emailUser' => $doctor->getEmailUser(),
                'rendezvous' => $rendezvous,
                'fiches' => $fiches,
                'prescriptions' => $prescriptions,
            ];
        }
        return $this->render('dashboard_ad/cons_ad.html.twig', [
            'controller_name' => 'AdController',
            'doctors' => $doctorsData,
        ]);
    }
    #[Route('/ad/statcons', name: 'app_ad_statcons')]
    public function statCons(
        UserRepository $userRepo,
        \App\Repository\RendezVousRepository $rendezVousRepo,
        \App\Repository\FicheMedicaleRepository $ficheRepo,
        \App\Repository\PrescriptionRepository $prescRepo
    ): Response {
        // Total counts
        $totalDoctors = count($userRepo->findDoctorsRespPatients());
        $totalRendezVous = $rendezVousRepo->count([]);
        $totalFiches = $ficheRepo->count([]);

        // Rendez-vous status distribution
        $rdvStatutLabels = ['Demande', 'Confirmé', 'Terminée'];
        $rdvStatutCounts = [];
        foreach ($rdvStatutLabels as $statut) {
            $rdvStatutCounts[] = $rendezVousRepo->count(['statut' => $statut]);
        }

        // Fiche diagnostics distribution
        $diagnostics = $ficheRepo->createQueryBuilder('f')
            ->select('f.diagnostic, COUNT(f.id) as count')
            ->groupBy('f.diagnostic')
            ->getQuery()
            ->getResult();
        $ficheDiagnosticLabels = [];
        $ficheDiagnosticCounts = [];
        foreach ($diagnostics as $diag) {
            $ficheDiagnosticLabels[] = $diag['diagnostic'] ?: 'Non spécifié';
            $ficheDiagnosticCounts[] = $diag['count'];
        }

        // Consultations per doctor (bar chart)
        $doctors = $userRepo->findDoctorsRespPatients();
        $doctorsConsultationsLabels = [];
        $doctorsConsultationsCounts = [];
        foreach ($doctors as $doctor) {
            $doctorsConsultationsLabels[] = $doctor->getNom() . ' ' . $doctor->getPrenom();
            $count = $rendezVousRepo->count(['staff' => $doctor]);
            $doctorsConsultationsCounts[] = $count;
        }

        // Rendez-vous per month (line chart)
        $rdvMonthLabels = [];
        $rdvMonthCounts = [];
        // Rendez-vous per month (line chart)
        $rdvMonthLabels = [];
        $rdvMonthCounts = [];
        $rdvMonthMap = [];
        $allRdv = $rendezVousRepo->findAll();
        foreach ($allRdv as $rdv) {
            if ($rdv->getDatetime()) {
                $month = $rdv->getDatetime()->format('Y-m');
                if (!isset($rdvMonthMap[$month])) {
                    $rdvMonthMap[$month] = 0;
                }
                $rdvMonthMap[$month]++;
            }
        }
        ksort($rdvMonthMap);
        foreach ($rdvMonthMap as $month => $count) {
            $rdvMonthLabels[] = $month;
            $rdvMonthCounts[] = $count;
        }

        // Fiches médicales per month (line chart)
        $ficheMonthLabels = [];
        $ficheMonthCounts = [];
        $ficheMonthMap = [];
        $allFiches = $ficheRepo->findAll();
        foreach ($allFiches as $fiche) {
            if ($fiche->getCreatedAt()) {
                $month = $fiche->getCreatedAt()->format('Y-m');
                if (!isset($ficheMonthMap[$month])) {
                    $ficheMonthMap[$month] = 0;
                }
                $ficheMonthMap[$month]++;
            }
        }
        ksort($ficheMonthMap);
        foreach ($ficheMonthMap as $month => $count) {
            $ficheMonthLabels[] = $month;
            $ficheMonthCounts[] = $count;
        }

        return $this->render('dashboard_ad/stat_cons.html.twig', [
            'controller_name' => 'AdController',
            'totalDoctors' => $totalDoctors,
            'totalRendezVous' => $totalRendezVous,
            'totalFiches' => $totalFiches,
            'rdvStatutLabels' => $rdvStatutLabels,
            'rdvStatutCounts' => $rdvStatutCounts,
            'ficheDiagnosticLabels' => $ficheDiagnosticLabels,
            'ficheDiagnosticCounts' => $ficheDiagnosticCounts,
            'doctorsConsultationsLabels' => $doctorsConsultationsLabels,
            'doctorsConsultationsCounts' => $doctorsConsultationsCounts,
            'rdvMonthLabels' => $rdvMonthLabels,
            'rdvMonthCounts' => $rdvMonthCounts,
            'ficheMonthLabels' => $ficheMonthLabels,
            'ficheMonthCounts' => $ficheMonthCounts,
        ]);
    }

}
