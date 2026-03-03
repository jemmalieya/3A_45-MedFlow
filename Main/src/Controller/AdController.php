<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\EvenementRepository;
use App\Repository\RessourceRepository;
use App\Repository\ReclamationRepository;
use App\Entity\Reclamation;

use App\Repository\PostRepository;

use App\Repository\ProduitRepository;
use App\Repository\CommandeRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
use App\Service\GeminiService;
use App\Service\TesseractOcrService;
use App\Service\EpidemieDetectionService;




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
// ========== PAGE LISTE PRODUITS ==========
#[Route('/produits', name: 'ad_produits_liste')]
public function produits(Request $request, ProduitRepository $produitRepo, EntityManagerInterface $em): Response
{
    $page = max(1, (int) $request->query->get('page', 1));
    $pageSize = 20;

    $produits = $produitRepo->createQueryBuilder('p')
        ->orderBy('p.id_produit', 'DESC')
        ->setFirstResult(($page - 1) * $pageSize)
        ->setMaxResults($pageSize)
        ->getQuery()
        ->getResult();

    $conn = $em->getConnection();
    $totalProduits = (int) $conn->fetchOne("SELECT COUNT(*) FROM produit LIMIT 1");

    $stockTotal = (int) $conn->fetchOne("
        SELECT COALESCE(SUM(quantite_produit), 0)
        FROM produit
        LIMIT 1
    ");

    $rows = $conn->fetchAllAssociative("
        SELECT status_produit AS status, COUNT(*) AS total
        FROM produit
        GROUP BY status_produit
    ");

    $produitsDisponibles = 0;
    $produitsRupture = 0;

    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? '');
        $total  = (int) ($r['total'] ?? 0);

        if ($status === 'Disponible') $produitsDisponibles = $total;
        if ($status === 'Rupture')    $produitsRupture = $total;
    }

    return $this->render('dashboard_ad/produits_liste.html.twig', [
        'produits' => $produits,
        'totalProduits' => $totalProduits,
        'produitsDisponibles' => $produitsDisponibles,
        'produitsRupture' => $produitsRupture,
        'stockTotal' => $stockTotal,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalPages' => (int) ceil($totalProduits / $pageSize),
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

        $caTotal = 0.0;
        try {
            $caTotal = (float) ($em->createQuery(
                'SELECT COALESCE(SUM(c.montant_total), 0) FROM App\\Entity\\Commande c WHERE c.paid_at IS NOT NULL'
            )->getSingleScalarResult() ?? 0);
        } catch (\Throwable $e) {
        }

        $caMois = 0.0;
        $nowY = (int) date('Y');
        $nowM = (int) date('m');

        try {
            $commandesPayees = $em->createQuery(
                'SELECT c FROM App\\Entity\\Commande c WHERE c.paid_at IS NOT NULL'
            )->getResult();

            foreach ($commandesPayees as $c) {
                $paidAt = $c->getPaidAt();
                if (!$paidAt) {
                    continue;
                }
                $y = (int) $paidAt->format('Y');
                $m = (int) $paidAt->format('m');
                if ($y === $nowY && $m === $nowM) {
                    $caMois += (float) $c->getMontantTotal();
                }
            }
        } catch (\Throwable $e) {
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
            ->setMaxResults(50)
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
        if (!is_string($rel) || $rel === '') {
            throw $this->createNotFoundException('Chemin introuvable.');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        if (!is_string($projectDir)) {
            throw new \RuntimeException('kernel.project_dir doit être une chaîne.');
        }

        $full = $projectDir . '/var/' . $rel;
        if (!is_file($full)) {
            throw $this->createNotFoundException('Fichier manquant.');
        }
        // No strict path check, allow access as before
        return $this->file($full, $docs['files'][$i]['original'] ?? basename($full));
    }

#[Route(
    '/ad/staff-requests/{id}/analyze/{i}',
    name: 'admin_staff_request_analyze',
    requirements: ['id' => '\\d+', 'i' => '\\d+'],
    methods: ['POST']
)]
public function staffRequestAnalyze(
    User $user,
    int $i,
    EntityManagerInterface $em,
    TesseractOcrService $ocr,
    GeminiService $gemini
): JsonResponse {
    $docs = $user->getStaffDocuments();

    // ✅ $docs est déjà un array → on sécurise juste la structure attendue
    $files = $docs['files'] ?? null;
    if (!is_array($files) || !isset($files[$i]) || !is_array($files[$i])) {
        return $this->json(['success' => false, 'error' => 'Document introuvable.'], 404);
    }

    $rel = $files[$i]['stored'] ?? null;
    if (!is_string($rel) || $rel === '') {
        return $this->json(['success' => false, 'error' => 'Chemin introuvable.'], 404);
    }

    $projectDir = $this->getParameter('kernel.project_dir');
    if (!is_string($projectDir)) {
        return $this->json(['success' => false, 'error' => 'Config kernel.project_dir invalide.'], 500);
    }
    $baseDir = realpath($projectDir . '/var/staff_requests');
    $full = realpath($projectDir . '/var/' . $rel);

    $baseNorm = $baseDir === false ? '' : str_replace('\\', '/', $baseDir);
    $fullNorm = $full === false ? '' : str_replace('\\', '/', $full);
    $baseNorm = rtrim(strtolower($baseNorm), '/') . '/';
    $fullNorm = strtolower($fullNorm);

    if ($baseDir === false || $full === false || !str_starts_with($fullNorm, $baseNorm)) {
        return $this->json(['success' => false, 'error' => 'Chemin interdit.'], 404);
    }
    if (!is_file($full)) {
        return $this->json(['success' => false, 'error' => 'Fichier manquant.'], 404);
    }

    $mime = (string) ($files[$i]['mime'] ?? '');
    $ocrText = null;
    $ocrError = null;

    if (str_starts_with($mime, 'image/')) {
        try {
            // ✅ On considère extractText() retourne un array (PHPStan)
            $res = $ocr->extractText($full);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => 'OCR failed: ' . $e->getMessage()], 500);
        }

        // ✅ lecture safe
        $ocrText  = isset($res['text']) && is_string($res['text']) ? trim($res['text']) : null;
        $ocrError = isset($res['error']) && is_string($res['error']) ? $res['error'] : null;

        if (is_string($ocrText) && $ocrText !== '') {
            $files[$i]['ocrText'] = mb_substr($ocrText, 0, 4000);
        }
    }

    // Build (optional) AI suggestion for THIS document if not already stored
    $aiSuggestion = is_string($files[$i]['aiSuggestion'] ?? null) ? trim((string) $files[$i]['aiSuggestion']) : '';
    if ($aiSuggestion === '' && is_string($ocrText) && trim($ocrText) !== '') {
        $meta = is_array($docs['meta'] ?? null) ? $docs['meta'] : [];
        $metaText = sprintf(
            "specialite=%s; etablissement=%s; numero=%s; role=%s; type=%s",
            (string) ($meta['specialite'] ?? ''),
            (string) ($meta['etablissement'] ?? ''),
            (string) ($meta['numero'] ?? ''),
            (string) ($meta['roleWanted'] ?? ''),
            (string) ($meta['typeStaffWanted'] ?? '')
        );

        $prompt = "Tu es un assistant pour un admin MedFlow.\n"
            . "On a un document (photo) soumis pour une demande de rôle.\n"
            . "Méta: {$metaText}\n\n"
            . "Texte OCR (peut contenir des erreurs):\n" . mb_substr($ocrText, 0, 3500) . "\n\n"
            . "Donne une courte synthèse (4 à 7 puces) et signale si tu vois: nom/prénom, numéros (CIN, ordre, etc.), spécialité, incohérences évidentes.\n"
            . "Ne pas inventer si ce n'est pas clairement présent.";

        try {
            $aiSuggestion = trim($gemini->generate($prompt));
            if ($aiSuggestion !== '') {
                $files[$i]['aiSuggestion'] = mb_substr($aiSuggestion, 0, 3000);
            } else {
                $aiSuggestion = '';
            }
        } catch (\Throwable $e) {
            $aiSuggestion = '';
        }
    }

    // ✅ Réinjecter les fichiers modifiés dans docs avant sauvegarde
    $docs['files'] = $files;

    $user->setStaffDocuments($docs);
    $em->flush();

    return $this->json([
        'success' => true,
        'ocrText' => $docs['files'][$i]['ocrText'] ?? null,
        'aiSuggestion' => $docs['files'][$i]['aiSuggestion'] ?? null,
        'ocrError' => $ocrError,
    ]);
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
        $user->markStaffReviewedAt();
        $me = $this->getUser();
        $user->setStaffReviewedBy($me instanceof User ? $me->getId() : null);
        $user->setStaffRequestReason(null);

        // If this was a staff pre-registration, unlock the account
        if (strtoupper((string) $user->getStatutCompte()) === 'EN_ATTENTE_ADMIN') {
            $user->setStatutCompte(null);
        }
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
        $user->markStaffReviewedAt();
        $me = $this->getUser();
        $user->setStaffReviewedBy($me instanceof User ? $me->getId() : null);
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

    /**
     * @return array<string, mixed>
     */
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

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!is_string($response)) {
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
        $qRaw = $request->query->get('q', '');
        $q = is_scalar($qRaw) ? trim((string) $qRaw) : '';

        $verifiedRaw = $request->query->get('verified', ''); // '' | '1' | '0'
        $verified = is_scalar($verifiedRaw) ? (string) $verifiedRaw : '';

        $sortRaw = $request->query->get('sort', 'recent');
        $sort = is_scalar($sortRaw) ? (string) $sortRaw : 'recent';

        $triageRaw = $request->query->get('triage', '');
        $triageFilter = is_scalar($triageRaw) ? (string) $triageRaw : '';

        $alertRaw = $request->query->get('alert', '');
        $alertFilter = is_scalar($alertRaw) ? (string) $alertRaw : '';

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

            if ($t['phoneOk']) {
                $stats['phone_valid']++;
            } else {
                $stats['phone_invalid']++;
            }
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
        $patient->updateTokenExpiresAt((new \DateTime())->modify('+24 hours'));
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

        $me = $this->getUser();
        $staffName = $me instanceof User ? $me->getPrenom() : null;

        $html = $this->renderView('dashboard_ad/patients/report_pdf.html.twig', [
            'stats' => $stats,
            'top' => $top,
            'tips' => $tips,
            'generatedAt' => new \DateTimeImmutable(),
            'staffName' => $staffName,
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
        $patient->markBannedAt();
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
        $patient->clearBannedAt();
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

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['api-key: '.$apiKey, 'Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
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
        if ($phone === null || $phone === '') {
            return false;
        }

        $p = preg_replace('/\s+/', '', $phone);
        if (!is_string($p)) {
            return false;
        }

        if (preg_match('/^\d{8}$/', $p)) return true;
        if (preg_match('/^\+216\d{8}$/', $p)) return true;
        return false;
    }

    private function isBlocked(?string $statutCompte): bool
    {
        if (!$statutCompte) return false;
        return strtoupper($statutCompte) === 'BLOQUE';
    }

    /**
     * @return array{
     *   alerts: array<int, array{key:string,label:string}>,
     *   priority: array{level:string,badge:string},
     *   score: int,
     *   phoneOk: bool,
     *   blocked: bool,
     *   expired: bool
     * }
     */
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
#[Route('/evenements', name: 'ad_evenements_liste', methods: ['GET'])]
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
#[Route('/participants', name: 'ad_participants', methods: ['GET'])]
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
#[Route('/ressources', name: 'ad_ressources', methods: ['GET'])]
public function ressources(Request $request, RessourceRepository $repo): Response
{
    $search = trim((string) $request->query->get('search', ''));
    $sort   = (string) $request->query->get('sort', 'date_desc');

    $orderBy = ['date_creation_ressource' => 'DESC'];
    switch ($sort) {
        case 'date_asc': $orderBy = ['date_creation_ressource' => 'ASC']; break;
        case 'nom_asc':  $orderBy = ['nom_ressource' => 'ASC']; break;
        case 'nom_desc': $orderBy = ['nom_ressource' => 'DESC']; break;
        case 'cat_asc':  $orderBy = ['categorie_ressource' => 'ASC']; break;
        case 'type_asc': $orderBy = ['type_ressource' => 'ASC']; break;
    }

    // ✅ search
    $ressources = ($search !== '')
        ? $repo->searchAdmin($search, $orderBy)
        : $repo->findBy([], $orderBy);

    return $this->render('dashboard_ad/indexevent.html.twig', [
        'section'    => 'ressources',
        'ressources' => $ressources,
        'search'     => $search,
        'sort'       => $sort,
    ]);
}
// ✅ READ-ONLY: Stats événements (tu peux garder tes stats JS/AJAX si tu veux après)
#[Route('/stats-evenements', name: 'ad_stats_evenements', methods: ['GET'])]
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
#[Route('/stats-ressources', name: 'ad_stats_ressources', methods: ['GET'])]
public function statsRessources(RessourceRepository $repo): Response
{
    // ✅ Appels directs : phpstan sera content
    $kpi        = $repo->getKpiStats();
    $byType     = $repo->countByType();
    $byCategorie= $repo->countByCategorie();
    $topEvents  = $repo->topEvenementsByRessources(5);

    return $this->render('dashboard_ad/indexevent.html.twig', [
        'section'      => 'stats_ressources',
        'kpi'          => $kpi,
        'byTypeR'      => $byType,
        'byCategorieR' => $byCategorie,
        'topEventsR'   => $topEvents,
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
        // Limit doctor list to 100 for performance (adjust as needed)
        $doctors = $userRepo->createQueryBuilder('u')
            ->where('u.roleSysteme IN (:roles)')
            ->setParameter('roles', ['STAFF', 'RESP_PATIENTS'])
            ->orderBy('u.nom', 'ASC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
        $doctorsData = [];
        foreach ($doctors as $doctor) {
            // $doctor is now an array due to HYDRATE_ARRAY
            $doctorId = $doctor['id'] ?? $doctor['id_user'] ?? null;
            $rendezvous = $rendezVousRepo->findBy(['staff' => $doctorId], ['datetime' => 'DESC']);
            $fiches = $ficheRepo->findFichesByStaffId($doctorId);

            // Eager load all prescriptions for these fiches in one query
            $ficheIds = array_map(fn($fiche) => $fiche->getId(), $fiches);
            $prescriptions = [];
            if (count($ficheIds) > 0) {
                $qb = $prescRepo->createQueryBuilder('p')
                    ->leftJoin('p.ficheMedicale', 'f')
                    ->addSelect('f')
                    ->where('f.id IN (:ficheIds)')
                    ->setParameter('ficheIds', $ficheIds);
                $prescriptions = $qb->getQuery()->getResult();
            }

            $doctorsData[] = [
                'id' => $doctorId,
                'nom' => $doctor['nom'] ?? null,
                'prenom' => $doctor['prenom'] ?? null,
                'typeStaff' => $doctor['typeStaff'] ?? $doctor['type_staff'] ?? null,
                'telephoneUser' => $doctor['telephoneUser'] ?? $doctor['telephone_user'] ?? null,
                'emailUser' => $doctor['emailUser'] ?? $doctor['email_user'] ?? null,
                'rendezvous' => $rendezvous,
                'fiches' => $fiches,
                'prescriptions' => $prescriptions,
            ];
        }

        // Rendez-vous statistics
        $totalRendezVous = $rendezVousRepo->count([]);
        $rdvDemande = $rendezVousRepo->count(['statut' => 'Demande']);
        $rdvConfirme = $rendezVousRepo->count(['statut' => 'Confirmé']);
        $rdvTerminee = $rendezVousRepo->count(['statut' => 'Terminée']);

        return $this->render('dashboard_ad/cons_ad.html.twig', [
            'controller_name' => 'AdController',
            'doctors' => $doctorsData,
            'totalRendezVous' => $totalRendezVous,
            'rdvDemande' => $rdvDemande,
            'rdvConfirme' => $rdvConfirme,
            'rdvTerminee' => $rdvTerminee,
        ]);
    }
    #[Route('/ad/statcons', name: 'app_ad_statcons')]
    public function statCons(
        UserRepository $userRepo,
        \App\Repository\RendezVousRepository $rendezVousRepo,
        \App\Repository\FicheMedicaleRepository $ficheRepo,
        \App\Repository\PrescriptionRepository $prescRepo,
        EntityManagerInterface $em
    ): Response {
        // Total counts
        // Limit doctor list to 100 for performance (adjust as needed)
        $doctorList = $userRepo->createQueryBuilder('u')
            ->where('u.roleSysteme IN (:roles)')
            ->setParameter('roles', ['STAFF', 'RESP_PATIENTS'])
            ->orderBy('u.nom', 'ASC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
        $totalDoctors = count($doctorList);
        $totalRendezVous = $rendezVousRepo->count([]);
        $totalFiches = $ficheRepo->count([]);

        // Rendez-vous status distribution
        $rdvStatutLabels = ['Demande', 'Confirmé', 'Terminée'];
        $rdvStatutCounts = [];
        foreach ($rdvStatutLabels as $statut) {
            $rdvStatutCounts[] = $rendezVousRepo->count(['statut' => $statut]);
        }

        // Fiche diagnostics distribution using DTO hydration
        // Create DTO: src/DTO/FicheDiagnosticCount.php
        // namespace App\DTO;
        // class FicheDiagnosticCount {
        //     public function __construct(
        //         public readonly ?string $diagnostic,
        //         public readonly int $count
        //     ) {}
        // }

        // Use injected EntityManagerInterface for DQL
        $diagnostics = $em->createQuery('
            SELECT NEW App\\DTO\\FicheDiagnosticCount(f.diagnostic, COUNT(f.id))
            FROM App\\Entity\\FicheMedicale f
            GROUP BY f.diagnostic
        ')->getResult();
        $ficheDiagnosticLabels = [];
        $ficheDiagnosticCounts = [];
        foreach ($diagnostics as $diag) {
            $ficheDiagnosticLabels[] = $diag->diagnostic ?: 'Non spécifié';
            $ficheDiagnosticCounts[] = $diag->count;
        }

        // Consultations per doctor (bar chart)
            $doctors = $doctorList;
            $doctorsConsultationsLabels = [];
            $doctorsConsultationsCounts = [];
            // Fetch all counts in one query to avoid N+1
            $conn = $em->getConnection();
            $sql = 'SELECT idStaff, COUNT(*) AS cnt FROM rendez_vous GROUP BY idStaff';
            $stmt = $conn->prepare($sql);
            $result = $stmt->executeQuery()->fetchAllAssociative();
            $countsByStaff = [];
            foreach ($result as $row) {
                $countsByStaff[$row['idStaff']] = (int)$row['cnt'];
            }
            foreach ($doctors as $doctor) {
                $doctorsConsultationsLabels[] = ($doctor['nom'] ?? '') . ' ' . ($doctor['prenom'] ?? '');
                $doctorId = $doctor['id'] ?? $doctor['id_user'] ?? null;
                $doctorsConsultationsCounts[] = $countsByStaff[$doctorId] ?? 0;
            }


        // Rendez-vous per day (line chart)
        $rdvDayLabels = [];
        $rdvDayCounts = [];
        $rdvDayMap = [];
        $allRdv = $rendezVousRepo->findAll();
        foreach ($allRdv as $rdv) {
            if ($rdv->getDatetime()) {
                $day = $rdv->getDatetime()->format('Y-m-d');
                if (!isset($rdvDayMap[$day])) {
                    $rdvDayMap[$day] = 0;
                }
                $rdvDayMap[$day]++;
            }
        }
        ksort($rdvDayMap);
        foreach ($rdvDayMap as $day => $count) {
            $rdvDayLabels[] = $day;
            $rdvDayCounts[] = $count;
        }

        // Fiches médicales per day (line chart)
        $ficheDayLabels = [];
        $ficheDayCounts = [];
        $ficheDayMap = [];
        $allFiches = $ficheRepo->findAll();
        foreach ($allFiches as $fiche) {
            if ($fiche->getCreatedAt()) {
                $day = $fiche->getCreatedAt()->format('Y-m-d');
                if (!isset($ficheDayMap[$day])) {
                    $ficheDayMap[$day] = 0;
                }
                $ficheDayMap[$day]++;
            }
        }
        ksort($ficheDayMap);
        foreach ($ficheDayMap as $day => $count) {
            $ficheDayLabels[] = $day;
            $ficheDayCounts[] = $count;
        }

        // Define missing variables as empty arrays
        $rdvMonthLabels = [];
        $rdvMonthCounts = [];
        $ficheMonthLabels = [];
        $ficheMonthCounts = [];
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
            'rdvDayLabels' => $rdvDayLabels,
            'rdvDayCounts' => $rdvDayCounts,
            'ficheDayLabels' => $ficheDayLabels,
            'ficheDayCounts' => $ficheDayCounts,
        ]);
    }

    #[Route('/reclamations', name: 'ad_reclamations_liste', methods: ['GET'])]
    public function reclamationsListe(
        \App\Repository\ReclamationRepository $recRepo
    ): Response
    {
        // ✅ TRI CORRECT: champ Doctrine = date_creation_r
        $reclamations = $recRepo->findBy([], ['date_creation_r' => 'DESC']);
    
        $rows = [];
        foreach ($reclamations as $rec) {
    
            // ✅ récupérer la dernière réponse si elle existe
            $rep = null;
            $reps = $rec->getReponses();
    
            if ($reps && $reps->count() > 0) {
                $arr = $reps->toArray();
    
                // Si ton entité ReponseReclamation a une date, tu peux trier ici.
                // Sinon, on prend juste la dernière ajoutée dans la collection.
                $rep = end($arr) ?: null;
            }
    
            $rows[] = [
                'rec' => $rec,
                'rep' => $rep
            ];
        }
    
        return $this->render('dashboard_ad/list_rec.html.twig', [
            'rows' => $rows,
            'total' => count($rows),
        ]);
    }
    
    
    #[Route('/reclamations/{id}/reponses', name: 'ad_reclamation_reponses', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function reclamationReponses(Reclamation $reclamation): JsonResponse
    {
        $data = [];
    
        foreach ($reclamation->getReponses() as $rep) {
            $data[] = [
                'id' => $rep->getIdReponse(),
                'message' => $rep->getMessage(),
                'type' => $rep->getTypeReponse(),
                'createdAt' => $rep->getDateCreationRep() ? $rep->getDateCreationRep()->format('d/m/Y H:i') : null,
                'isRead' => $rep->isRead(),
            ];
        }
    
        // (optionnel) trier du plus récent au plus ancien
        usort($data, fn($a, $b) => strcmp((string)$b['createdAt'], (string)$a['createdAt']));
    
        return $this->json([
            'ok' => true,
            'reclamationId' => $reclamation->getIdReclamation(),
            'reference' => $reclamation->getReferenceReclamation(),
            'count' => count($data),
            'reponses' => $data,
        ]);
    }
    #[Route('/admin/statistiques/reclamations', name: 'ad_stats_reclamations')]
    public function statsReclamations(ReclamationRepository $repo): Response
    {
        $recs = $repo->findAll();
    
        $total = count($recs);
        $traitees = 0;
        $urgentes = 0;
        $byType = [];
        $byMonth = array_fill(1, 12, 0);
    
        foreach ($recs as $r) {
            if ($r->getStatutReclamation() === 'TRAITEE') {
                $traitees++;
            }
    
            if ($r->isUrgente()) {
                $urgentes++;
            }
    
            $type = $r->getType();
            $byType[$type] = ($byType[$type] ?? 0) + 1;
    
            if ($r->getDateCreationR()) {
                $m = (int) $r->getDateCreationR()->format('n'); // 1..12
                $byMonth[$m]++;
            }
        }
    
        // ✅ Calcul propre: En attente = Total - Traitées
        $enAttente = $total - $traitees;
    
        return $this->render('dashboard_ad/stats_rec.html.twig', [
            'total' => $total,
            'enAttente' => $enAttente,
            'traitees' => $traitees,
            'urgentes' => $urgentes,
            'byType' => $byType,
            'byMonth' => array_values($byMonth), // pour Chart.js
        ]);
    }
    
    
    
    
    #[Route('/admin/posts', name: 'ad_posts_liste')]
    public function listePosts(PostRepository $postRepo): Response
    {
        $posts = $postRepo->findBy([], ['date_creation' => 'DESC']);
    
        $total = count($posts);
        $approved = 0;
        $pending = 0;
        $rejected = 0;
    
        foreach ($posts as $p) {
            if ($p->getModerationStatus() === 'APPROVED') $approved++;
            if ($p->getModerationStatus() === 'PENDING') $pending++;
            if ($p->getModerationStatus() === 'REJECTED') $rejected++;
        }
    
        return $this->render('dashboard_ad/list_posts.html.twig', [
            'posts' => $posts,
            'total' => $total,
            'approved' => $approved,
            'pending' => $pending,
            'rejected' => $rejected,
        ]);
    }
    
    
    #[Route('/admin/statistiques/posts', name: 'ad_stats_posts')]
    public function statsPosts(PostRepository $repo): Response
    {
        $posts = $repo->findAll();
    
        $total = count($posts);
        $approved = 0;
        $pending = 0;
        $rejected = 0;
    
        $byCategorie = [];
        $byVisibilite = [];
        $byHumeur = [];
        $byMonth = array_fill(1, 12, 0);
    
        foreach ($posts as $p) {
            // Statuts de modération
            if ($p->getModerationStatus() === 'APPROVED') $approved++;
            if ($p->getModerationStatus() === 'PENDING')  $pending++;
            if ($p->getModerationStatus() === 'REJECTED') $rejected++;
    
            // Par catégorie
            $cat = $p->getCategorie();
            $byCategorie[$cat] = ($byCategorie[$cat] ?? 0) + 1;
    
            // Par visibilité
            $vis = $p->getVisibilite();
            $byVisibilite[$vis] = ($byVisibilite[$vis] ?? 0) + 1;
    
            // Par humeur
            $hum = $p->getHumeur() ?: 'Non défini';
            $byHumeur[$hum] = ($byHumeur[$hum] ?? 0) + 1;
    
            // Par mois de création
            if ($p->getDateCreation()) {
                $m = (int) $p->getDateCreation()->format('n'); // 1..12
                $byMonth[$m]++;
            }
        }
    
        return $this->render('dashboard_ad/stats_posts.html.twig', [
            'total' => $total,
            'approved' => $approved,
            'pending' => $pending,
            'rejected' => $rejected,
    
            'labelsCategorie' => array_keys($byCategorie),
            'valuesCategorie' => array_values($byCategorie),
    
            'labelsVisibilite' => array_keys($byVisibilite),
            'valuesVisibilite' => array_values($byVisibilite),
    
            'labelsHumeur' => array_keys($byHumeur),
            'valuesHumeur' => array_values($byHumeur),
    
            'byMonth' => array_values($byMonth),
        ]);
    }
        // ============================================================
//  Détection Épidémique — Dashboard YASSMIIIIIIINEEEEEEEEEEEEEEEEE
// ============================================================
#[Route('/admin/epidemie-detection', name: 'admin_epidemie_detection', methods: ['GET'])]
public function epidemieDashboard(Request $request, EpidemieDetectionService $epi): Response
{
    $country = (string) $request->query->get('country', 'Tunisia');
    $daysApi = (int) $request->query->get('days', 60);
    $lat     = (float) $request->query->get('lat', 36.8);
    $lon     = (float) $request->query->get('lon', 10.18);

    $whoOutbreaks = $epi->getWhoOutbreaks();
    $meteoCorrel  = $epi->getInfluenzaData($country);
    $meteoHisto   = $epi->getMeteoHistorique($lat, $lon);

    $localChart    = $epi->localSignalsChart(7, 30);
    $tendances     = $epi->getTendances6Mois();
    $topParMaladie = $epi->getTopProduitsByMaladie(30);

    // ✅ couleur KPI selon risque
    $risqueColor = match ($localChart['risk']) {
        'Élevé'  => 'danger',
        'Modéré' => 'warning',
        default  => 'success',
    };

    return $this->render('dashboard_ad/epidemie_dashboard.html.twig', [
        'country'       => $country,
        'daysApi'       => $daysApi,
        'lat'           => $lat,
        'lon'           => $lon,

        'whoOutbreaks'  => $whoOutbreaks,
        'meteoCorrel'   => $meteoCorrel,
        'meteoHisto'    => $meteoHisto,

        'localChart'    => $localChart,
        'tendances'     => $tendances,
        'topParMaladie' => $topParMaladie,

        'risqueColor'   => $risqueColor, // 👈 IMPORTANT
    ]);
}

#[Route('/admin/epidemie-detection/export-csv', name: 'admin_epidemie_export_csv', methods: ['GET'])]
public function epidemieExportCsv(EpidemieDetectionService $epi): StreamedResponse
{
    $localChart = $epi->localSignalsChart(7, 30);
    $csvContent = $epi->exportSignalsCsv($localChart['meta']);

    $response = new StreamedResponse(function () use ($csvContent) {
        echo $csvContent;
    });

    $filename = 'signaux_epidemie_' . date('Y-m-d') . '.csv';
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

    return $response;
}
// ============================================================
//  API JSON (pour refresh AJAX)
// ============================================================
#[Route('/admin/epidemie-detection/api', name: 'admin_epidemie_api_json', methods: ['GET'])]
public function epidemieApiJson(EpidemieDetectionService $epi): Response
{
    return $this->json([
        'localChart'    => $epi->localSignalsChart(7, 30),
        'tendances'     => $epi->getTendances6Mois(),
        'who'           => $epi->getWhoOutbreaks(),
        'meteo'         => $epi->getMeteoHistorique(),
        'topParMaladie' => $epi->getTopProduitsByMaladie(30),
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

    // =========================
    // KPIs (count) - OK
    // =========================
    $totalUsers     = $userRepo->count([]);
    $totalProduits  = $produitRepo->count([]);
    $totalCommandes = $commandeRepo->count([]);

    // =========================
    // CA total (commandes payées) - DQL
    // =========================
    $caTotal = 0.0;
    try {
        $caTotal = (float) $em->createQuery(
            "SELECT COALESCE(SUM(c.montant_total), 0)
             FROM App\Entity\Commande c
             WHERE c.paid_at IS NOT NULL"
        )->getSingleScalarResult();
    } catch (\Throwable $e) {
        $caTotal = 0.0;
    }

    // =========================
    // CA du mois courant - DQL
    // =========================
    $caMois = 0.0;
    try {
        $start = new \DateTimeImmutable('first day of this month 00:00:00');
        $end   = $start->modify('+1 month');

        $caMois = (float) $em->createQuery(
            "SELECT COALESCE(SUM(c.montant_total), 0)
             FROM App\Entity\Commande c
             WHERE c.paid_at >= :start
               AND c.paid_at <  :end"
        )
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getSingleScalarResult();
    } catch (\Throwable $e) {
        $caMois = 0.0;
    }

    // =========================================
    // CA par mois (12 derniers mois) - SQL MySQL/MariaDB
    // ✅ FIX: filtre de période (>= start et < end) pour éviter SELECT illimité
    // Retour: [ ['month' => 'Jan', 'total' => 1200], ... ]
    // =========================================
    $caParMois = [];
    try {
        $cursor = new \DateTimeImmutable('first day of this month 00:00:00');
        $start12 = $cursor->modify('-11 months');   // début fenêtre 12 mois
        $end12   = $cursor->modify('+1 month');     // fin fenêtre (début mois prochain)

        // Liste des 12 mois dans l’ordre (YYYY-MM)
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $start12->modify("+{$i} months")->format('Y-m');
        }

        // Query DB : group by YYYY-MM, mais limitée à 12 mois ✅
        $rows = $em->getConnection()->fetchAllAssociative("
        SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym,
               COALESCE(SUM(montant_total), 0) AS total
        FROM commande
        WHERE paid_at IS NOT NULL
          AND paid_at >= :start
          AND paid_at <  :end
        GROUP BY ym
        ORDER BY ym ASC
        LIMIT 12
    ", [
        'start' => $start12->format('Y-m-d H:i:s'),
        'end'   => $end12->format('Y-m-d H:i:s'),
    ]);
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['ym']] = (float) $r['total'];
        }

        $moisLabel = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];

        foreach ($months as $ym) {
            [, $m] = explode('-', $ym);
            $mInt = (int) $m;

            $caParMois[] = [
                'month' => $moisLabel[$mInt - 1],
                'total' => $map[$ym] ?? 0.0,
            ];
        }
    } catch (\Throwable $e) {
        $caParMois = [];
    }

    // =========================================
    // Top 5 produits les plus vendus (commandes payées)
    // =========================================
    $topProduits = [];
    try {
        $topProduits = $em->getConnection()->fetchAllAssociative("
            SELECT
                p.nom_produit AS nom_produit,
                SUM(lc.quantite_commandee) AS total_vendu
            FROM commande_produit lc
            INNER JOIN produit p ON lc.produit_id = p.id_produit
            INNER JOIN commande c ON lc.commande_id = c.id_commande
            WHERE c.paid_at IS NOT NULL
            GROUP BY p.id_produit, p.nom_produit
            ORDER BY total_vendu DESC
            LIMIT 5
        ");
    } catch (\Throwable $e) {
        $topProduits = [];
    }

    // =========================================
    // Produits en stock faible (limit 10) - OK
    // =========================================
    $produitsRupture = $produitRepo->createQueryBuilder('p')
        ->where('p.quantite_produit <= :seuil')
        ->setParameter('seuil', 5)
        ->orderBy('p.quantite_produit', 'ASC')
        ->setMaxResults(10)
        ->getQuery()
        ->getResult();

// =========================================
// Commandes par statut (12 derniers mois) ✅
// =========================================
$commandesParStatut = [
    'En attente'   => 0,
    'En cours'     => 0,
    'En livraison' => 0,
    'Livrée'       => 0,
    'Annulée'      => 0,
];

try {
    $end   = new \DateTimeImmutable('first day of this month 00:00:00');
    $start = $end->modify('-11 months');      // fenêtre 12 mois
    $end2  = $end->modify('+1 month');        // inclus mois courant

    $rows = $em->getConnection()->fetchAllAssociative("
        SELECT statut_commande AS statut, COUNT(*) AS total
        FROM commande
        WHERE date_creation_commande >= :start
          AND date_creation_commande <  :end
        GROUP BY statut_commande
    ", [
        'start' => $start->format('Y-m-d H:i:s'),
        'end'   => $end2->format('Y-m-d H:i:s'),
    ]);

    foreach ($rows as $r) {
        $statut = (string) $r['statut'];
        $total  = (int) $r['total'];

        if (array_key_exists($statut, $commandesParStatut)) {
            $commandesParStatut[$statut] = $total;
        }
    }
} catch (\Throwable $e) {
    // garde les zéros
}

    // =========================================
    // Dernières commandes (limit 5) - OK
    // =========================================
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

}

