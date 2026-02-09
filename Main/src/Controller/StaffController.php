<?php

namespace App\Controller;

use App\Entity\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;



//#[Route('/staff')]
//#[IsGranted('ROLE_STAFF')]
class StaffController extends AbstractController
{
    /* =========================
     *  DASHBOARD STAFF
     * ========================= */
    #[Route('/admin/staff', name: 'staff_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('staff/dashboard.html.twig');
    }

  
#[Route('/admin/staff/patients', name: 'staff_patients_index', methods: ['GET'])]
public function patientsIndex(Request $request, UserRepository $repo): Response
{

    $q = trim((string) $request->query->get('q', ''));
    $verified = (string) $request->query->get('verified', ''); // '' | '1' | '0'
    $sort = (string) $request->query->get('sort', 'recent');   // recent|name|cin
    $triageFilter = (string) $request->query->get('triage', ''); // ''|CRITIQUE|HAUTE|MOYENNE|OK
    $alertFilter = (string) $request->query->get('alert', '');   // ''|unverified|unverified_expired|phone_invalid|blocked

    $qb = $repo->createQueryBuilder('u')
        ->andWhere('u.roleSysteme = :role')
        ->setParameter('role', 'PATIENT');

    if ($q !== '') {
        $qb->andWhere('u.cin LIKE :q OR u.nom LIKE :q OR u.prenom LIKE :q OR u.emailUser LIKE :q')
           ->setParameter('q', '%'.$q.'%');
    }

    if ($verified === '1') {
        $qb->andWhere('u.isVerified = 1');
    } elseif ($verified === '0') {
        $qb->andWhere('u.isVerified = 0');
    }

    // Tri simple
    if ($sort === 'name') {
        $qb->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC');
    } elseif ($sort === 'cin') {
        $qb->orderBy('u.cin', 'ASC');
    } else {
        $qb->orderBy('u.id', 'DESC');
    }

    $patients = $qb->getQuery()->getResult();

    // ✅ Triage + stats (sans table)
    $rows = [];
    $stats = [
        'total' => 0,
        'verified' => 0,
        'unverified' => 0,
        'phone_invalid' => 0,
        'blocked' => 0,
        'priorities' => ['CRITIQUE' => 0, 'HAUTE' => 0, 'MOYENNE' => 0, 'OK' => 0],
        'expired_links' => 0,
    ];

    foreach ($patients as $p) {
        $t = $this->buildTriage($p);

        // stats globales
        $stats['total']++;
        $p->isVerified() ? $stats['verified']++ : $stats['unverified']++;
        if (!$t['phoneOk']) $stats['phone_invalid']++;
        if ($t['blocked']) $stats['blocked']++;
        if ($t['expired']) $stats['expired_links']++;
        $stats['priorities'][$t['priority']['level']]++;

        // filtres avancés (après calcul triage)
        if ($triageFilter && $t['priority']['level'] !== $triageFilter) {
            continue;
        }
        if ($alertFilter) {
            $has = false;
            foreach ($t['alerts'] as $a) {
                if ($a['key'] === $alertFilter) { $has = true; break; }
            }
            if (!$has) continue;
        }

        $rows[] = [
            'p' => $p,
            'triage' => $t,
        ];
    }

    return $this->render('staff/patients/index.html.twig', [
        'rows' => $rows,
        'q' => $q,
        'verified' => $verified,
        'sort' => $sort,
        'triage' => $triageFilter,
        'alert' => $alertFilter,
        'stats' => $stats,
    ]);
}


    /* =========================
     *  GESTION PATIENTS (RESP_PATIENTS ONLY)
     *  Page 2 : Fiche patient
     * ========================= */
    #[Route('admin/staff/patients/{id}', name: 'staff_patients_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function patientsShow(User $patient): Response
    {


        // Sécurité supplémentaire : on n'affiche que les patients
        if ($patient->getRoleSysteme() !== 'PATIENT') {
            throw $this->createNotFoundException('Patient introuvable.');
        }

        return $this->render('staff/patients/show.html.twig', [
            'patient' => $patient,
        ]);
    }


    #[Route('admin/staff/patients/{id}/resend-verification', name: 'staff_patient_resend_verification', methods: ['POST'])]
public function resendPatientVerification(
    User $patient,
    EntityManagerInterface $em,
    LoggerInterface $logger
): Response {

    // sécurité: uniquement patient
    if ($patient->getRoleSysteme() !== 'PATIENT') {
        throw $this->createNotFoundException('Patient introuvable.');
    }

    // déjà vérifié
    if ($patient->isVerified()) {
        $this->addFlash('info', 'Compte déjà vérifié.');
        return $this->redirectToRoute('staff_patients_show', ['id' => $patient->getId()]);
    }

    // 🔁 Nouveau token
    $patient->setVerificationToken(bin2hex(random_bytes(32)));
    $patient->setTokenExpiresAt((new \DateTime())->modify('+24 hours'));
    $em->flush();

    // ✉️ même logique Brevo que ton VerifyEmailController
    $this->sendVerificationEmail($patient, $logger);

    $this->addFlash('success', 'Email de vérification renvoyé ✅');
    return $this->redirectToRoute('staff_patients_show', ['id' => $patient->getId()]);
}
private function sendVerificationEmail(User $user, LoggerInterface $logger): void
{
    $apiKey = $_ENV['BREVO_API_KEY'];
    $sender = $_ENV['BREVO_SENDER_EMAIL'];
    $appUrl = $_ENV['APP_URL'];

    $link = $appUrl . '/verify-email?token=' . $user->getVerificationToken();

    $payload = [
        'sender' => [
            'email' => $sender,
            'name' => 'MedFlow',
        ],
        'to' => [[
            'email' => $user->getEmailUser(),
        ]],
        'subject' => 'Vérification de votre compte',
        'htmlContent' => "<p><a href='$link'>Vérifier mon email</a></p>",
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $logger->info('Staff resend verification email', [
        'email' => $user->getEmailUser(),
        'response' => $response,
    ]);
}
private function isPhoneValid(?string $phone): bool
{
    if (!$phone) return false;

    $p = preg_replace('/\s+/', '', $phone);

    // accepte: 8 chiffres (TN) ou +216XXXXXXXX
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

    // Alerte: non vérifié + lien expiré (≈ >24h dans ton système token)
    $expired = false;
    if (!$p->isVerified() && $p->getTokenExpiresAt() instanceof \DateTimeInterface) {
        $expired = $p->getTokenExpiresAt() < new \DateTimeImmutable();
    }

    if (!$p->isVerified()) {
        if ($expired) {
            $alerts[] = ['key' => 'unverified_expired', 'label' => 'Non vérifié (lien expiré)'];
        } else {
            $alerts[] = ['key' => 'unverified', 'label' => 'Non vérifié'];
        }
    }

    // Alerte: téléphone
    $phoneOk = $this->isPhoneValid($p->getTelephoneUser());
    if (!$phoneOk) {
        $alerts[] = ['key' => 'phone_invalid', 'label' => 'Téléphone invalide'];
    }

    // Alerte: bloqué
    $blocked = $this->isBlocked($p->getStatutCompte());
    if ($blocked) {
        $alerts[] = ['key' => 'blocked', 'label' => 'Compte bloqué'];
    }

    // Score priorité (simple & robuste)
    $score = 0;
    if ($blocked) $score += 3;
    if (!$p->isVerified()) $score += 2;
    if ($expired) $score += 2; // lien expiré = plus urgent
    if (!$phoneOk) $score += 1;

    // Niveau priorité
    if ($score >= 5) {
        $priority = ['level' => 'CRITIQUE', 'badge' => 'danger'];
    } elseif ($score >= 3) {
        $priority = ['level' => 'HAUTE', 'badge' => 'warning'];
    } elseif ($score >= 1) {
        $priority = ['level' => 'MOYENNE', 'badge' => 'info'];
    } else {
        $priority = ['level' => 'OK', 'badge' => 'success'];
    }

    return [
        'alerts' => $alerts,
        'priority' => $priority,
        'score' => $score,
        'phoneOk' => $phoneOk,
        'blocked' => $blocked,
        'expired' => $expired,
    ];
}
#[Route('admin/staff/patients/stats', name: 'staff_patients_stats', methods: ['GET'])]
public function patientsStats(Request $request, UserRepository $repo): Response
{


    // Reprend la même base que index : patients only
    $patients = $repo->createQueryBuilder('u')
        ->andWhere('u.roleSysteme = :role')
        ->setParameter('role', 'PATIENT')
        ->orderBy('u.id', 'DESC')
        ->getQuery()->getResult();

    // Calcule stats + distribution (utilise buildTriage() qu'on a déjà)
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

    // Data pour graphiques (Chart.js)
    $chartPriorities = [
        $stats['priorities']['CRITIQUE'],
        $stats['priorities']['HAUTE'],
        $stats['priorities']['MOYENNE'],
        $stats['priorities']['OK'],
    ];
    $chartVerification = [
        $stats['verified'],
        $stats['unverified'],
    ];
    $chartDataQuality = [
        $stats['phone_invalid'],
        $stats['blocked'],
        $stats['expired_links'],
    ];

    // “Conseils” automatiques
    $tips = [];
    if ($stats['unverified'] > 0 && $stats['unverified'] >= ($stats['total'] * 0.4)) {
        $tips[] = "Taux de non-vérification élevé : prévoir une relance email ciblée (Brevo).";
    }
    if ($stats['phone_invalid'] > 0) {
        $tips[] = "Beaucoup de téléphones invalides : renforcer la validation + proposer correction en front.";
    }
    if ($stats['blocked'] > 0) {
        $tips[] = "Présence de comptes bloqués : vérifier tentatives frauduleuses / spam.";
    }
    if ($tips === []) {
        $tips[] = "Situation globale saine : continuer le monitoring.";
    }

    return $this->render('staff/patients/stats.html.twig', [
        'stats' => $stats,
        'chartPriorities' => $chartPriorities,
        'chartVerification' => $chartVerification,
        'chartDataQuality' => $chartDataQuality,
        'tips' => $tips,
    ]);
}

#[Route('admin/staff/patients/report/pdf', name: 'staff_patients_report_pdf', methods: ['GET'])]
public function patientsReportPdf(UserRepository $repo): Response
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

    // Top 10 critiques/hautes (rapport utile)
    usort($rows, fn($a, $b) => $b['triage']['score'] <=> $a['triage']['score']);
    $top = array_slice($rows, 0, 10);

    // Conseils
    $tips = [];
    if ($stats['unverified'] > 0) $tips[] = "Relancer les non-vérifiés via Brevo (email de vérification).";
    if ($stats['expired_links'] > 0) $tips[] = "Beaucoup de liens expirés : proposer un bouton de renvoi en masse.";
    if ($stats['phone_invalid'] > 0) $tips[] = "Téléphones invalides : demander correction lors de la prochaine connexion.";
    if ($stats['blocked'] > 0) $tips[] = "Comptes bloqués : vérifier l’origine et décider déblocage/suppression.";
    if ($tips === []) $tips[] = "Aucune anomalie critique détectée.";

    // HTML PDF
    $html = $this->renderView('staff/patients/report_pdf.html.twig', [
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
#[Route('admin/staff/patients/{id}/email-check', name: 'staff_patient_email_check', methods: ['POST'])]
public function emailCheck(
    User $patient,
    HttpClientInterface $http,
    Request $request,
    CsrfTokenManagerInterface $csrf
): JsonResponse {

    if ($patient->getRoleSysteme() !== 'PATIENT') {
        return $this->json(['ok' => false, 'error' => 'Not found'], 404);
    }

    // CSRF
    $tokenValue = (string) $request->request->get('_token', '');
    if (!$csrf->isTokenValid(new CsrfToken('email_check_'.$patient->getId(), $tokenValue))) {
        return $this->json(['ok' => false, 'error' => 'Invalid CSRF'], 403);
    }

    $email = (string) $patient->getEmailUser();
    if ($email === '') {
        return $this->json(['ok' => false, 'error' => 'Email vide'], 400);
    }

    // Anti-spam simple : 1 appel / 30s (session)
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
        // Hunter Email Verifier
        $res = $http->request('GET', 'https://api.hunter.io/v2/email-verifier', [
            'query' => [
                'email' => $email,
                'api_key' => $apiKey,
            ],
            'timeout' => 10,
        ]);

        $raw = $res->toArray(false);
        $d = $raw['data'] ?? [];

        // Champs utiles Hunter
        $status     = strtoupper((string) ($d['status'] ?? 'UNKNOWN'));         // VALID / INVALID / UNKNOWN
        $result     = strtoupper((string) ($d['result'] ?? 'UNKNOWN'));         // DELIVERABLE / UNDELIVERABLE (deprecated chez eux)
        $mx         = (bool) ($d['mx_records'] ?? false);
        $smtpServer = (bool) ($d['smtp_server'] ?? false);
        $smtpCheck  = (bool) ($d['smtp_check'] ?? false);
        $webmail    = (bool) ($d['webmail'] ?? false);
        $disposable = (bool) ($d['disposable'] ?? false);
        $acceptAll  = (bool) ($d['accept_all'] ?? false);
        $block      = (bool) ($d['block'] ?? false);

        /**
         * ✅ Score interne MedFlow (0..100)
         * But : varier selon les indicateurs et être stable même si Hunter "score" = 0.
         */
        $score = 100;

        // Cas très mauvais
        if ($status === 'INVALID' || $result === 'UNDELIVERABLE' || $block) {
            $score = 5;
        } else {
            if (!$mx)         $score -= 25;
            if (!$smtpServer) $score -= 20;
            if (!$smtpCheck)  $score -= 20;   // beaucoup d’emails auront smtp_check=false → score baisse mais pas à 0
            if ($acceptAll)   $score -= 10;
            if ($disposable)  $score -= 35;
            if ($webmail)     $score -= 5;
        }

        // Clamp
        $score = max(0, min(100, (int) $score));

        // Risk métier basé sur status + score
        $risk = 'LOW';
        if ($status === 'INVALID' || $result === 'UNDELIVERABLE' || $score < 35) {
            $risk = 'HIGH';
        } elseif ($disposable || $score < 70) {
            $risk = 'MEDIUM';
        }

        $impactPriority = match ($risk) {
            'HIGH' => 'HAUTE',
            'MEDIUM' => 'MOYENNE',
            default => 'OK',
        };

        $advice = match ($risk) {
            'HIGH' => "Email probablement invalide. Demander une correction.",
            'MEDIUM' => $disposable
                ? "Email jetable détecté. Compte à surveiller."
                : "Qualité moyenne. Recommandé de confirmer l’email.",
            default => "Email OK.",
        };

        // Deliverability lisible
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
            'quality_score' => $score, // ✅ ici c’est 0..100 (plus jamais bloqué à 0)
            'risk' => $risk,
            'impact_priority' => $impactPriority,
            'advice' => $advice,
            'raw' => $raw, // garde pour debug (tu peux enlever après)
        ]);

    } catch (\Throwable $e) {
        return $this->json([
            'ok' => false,
            'error' => 'Erreur API: '.$e->getMessage(),
        ], 500);
    }
}




}
