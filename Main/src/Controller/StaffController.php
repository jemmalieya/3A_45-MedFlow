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

    /* =========================
     *  PATIENT RADAR (RESP_PATIENTS)
     * ========================= */
    #[Route('/admin/staff/patients/radar', name: 'staff_patients_radar', methods: ['GET'])]
    public function patientsRadar(Request $request, UserRepository $repo): Response
    {
        $patients = $repo->findPatientsWithFilters(['q' => null, 'verified' => null]);

        $counts = ['OK' => 0, 'INCOMPLETE' => 0, 'UNVERIFIED' => 0, 'CONTACT_BAD' => 0];
        $queues = ['new24h' => [], 'new7d' => [], 'incomplete' => [], 'unverified' => [], 'contactRisk' => []];
        $topAnomalies = [];

        $now = new \DateTime();
        foreach ($patients as $p) {
            $q = $this->qualityScore($p);
            $t = $this->triageBucket($p);
            $counts[$t]++;
            // Approximate "new" based on available timestamps
            $created = null;
            if (method_exists($p, 'getDerniereConnexion') && $p->getDerniereConnexion() instanceof \DateTimeInterface) {
                // Use last connection as a proxy for recent signup/activity
                $created = $p->getDerniereConnexion();
            } elseif (method_exists($p, 'getTokenExpiresAt') && $p->getTokenExpiresAt() instanceof \DateTimeInterface) {
                // Registration sets token_expires_at = now + 24h; infer signup time by subtracting 24h
                $created = (clone $p->getTokenExpiresAt())->modify('-24 hours');
            }
            if ($created instanceof \DateTimeInterface) {
                $diff = $now->getTimestamp() - $created->getTimestamp();
                if ($diff <= 86400) { $queues['new24h'][] = $p; }
                if ($diff <= 604800) { $queues['new7d'][] = $p; }
            }
            if ($q['incomplete']) $queues['incomplete'][] = $p;
            if (!$p->isVerified()) $queues['unverified'][] = $p;
            if ($q['contactBad']) $queues['contactRisk'][] = $p;

            $anomScore = $q['scoreMissing'] + $q['scoreInvalid'];
            $topAnomalies[] = ['p' => $p, 'score' => $anomScore, 'details' => $q['details']];
        }

        usort($topAnomalies, fn($a, $b) => $b['score'] <=> $a['score']);
        $topAnomalies = array_slice($topAnomalies, 0, 10);

        $stats = $this->buildStats($patients);

        return $this->render('staff/dashboard.html.twig', [
            'counts' => $counts,
            'queues' => $queues,
            'topAnomalies' => $topAnomalies,
            'stats' => $stats,
        ]);
    }

    #[Route('/admin/staff/patients', name: 'staff_patients_list', methods: ['GET'])]
    public function patientsList(Request $request, UserRepository $repo): Response
    {
        $queue = (string) $request->query->get('queue', '');
        $q = trim((string) $request->query->get('q', ''));
        $verified = $request->query->has('verified') ? (string) $request->query->get('verified') : '';

        $patients = $repo->findPatientsWithFilters(['q' => $q ?: null, 'verified' => $verified === '' ? null : (bool) $verified]);

        $rows = [];
        foreach ($patients as $p) {
            $qs = $this->qualityScore($p);
            if ($queue === 'incomplete' && !$qs['incomplete']) continue;
            if ($queue === 'unverified' && $p->isVerified()) continue;
            if ($queue === 'contactRisk' && !$qs['contactBad']) continue;
            $rows[] = ['p' => $p, 'quality' => $qs];
        }

        usort($rows, fn($a, $b) => $b['quality']['score'] <=> $a['quality']['score']);

        return $this->render('staff/patients/index.html.twig', [
            'rows' => $rows,
            'queue' => $queue,
            'q' => $q,
            'verified' => $verified,
        ]);
    }

    #[Route('/admin/staff/patients/export.csv', name: 'staff_patients_export_csv', methods: ['GET'])]
    public function exportCsv(Request $request, UserRepository $repo): Response
    {
        $patients = $repo->findPatientsWithFilters(['q' => null, 'verified' => null]);
        $csv = "prenom,nom,email,telephone,verifie,score\n";
        foreach ($patients as $p) {
            $qs = $this->qualityScore($p);
            $csv .= sprintf("%s,%s,%s,%s,%s,%d\n",
                $p->getPrenom() ?? '',
                $p->getNom() ?? '',
                $p->getEmailUser() ?? '',
                $p->getTelephoneUser() ?? '',
                $p->isVerified() ? '1' : '0',
                $qs['score']
            );
        }
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="patients_quality.csv"'
        ]);
    }

    #[Route('/admin/staff/patients/{id}', name: 'staff_patients_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function patientShow(User $user): Response
    {
        $qs = $this->qualityScore($user);
        return $this->render('staff/patients/show.html.twig', [
            'p' => $user,
            'quality' => $qs,
        ]);
    }

    #[Route('/admin/staff/patients/stats', name: 'staff_patients_stats', methods: ['GET'])]
    public function patientsStats(UserRepository $repo): Response
    {
        $patients = $repo->findPatientsWithFilters(['q' => null, 'verified' => null]);
        $stats = $this->buildStats($patients);
        return $this->render('staff/patients/stats.html.twig', [
            'stats' => $stats,
        ]);
    }

    private function triageBucket(User $p): string
    {
        $q = $this->qualityScore($p);
        if ($p->isVerified() && !$q['incomplete'] && !$q['contactBad']) return 'OK';
        if (!$p->isVerified()) return 'UNVERIFIED';
        if ($q['contactBad']) return 'CONTACT_BAD';
        return 'INCOMPLETE';
    }

    private function qualityScore(User $p): array
    {
        $score = 0; $miss = 0; $invalid = 0; $details = [];
        $email = (string) ($p->getEmailUser() ?? '');
        $phone = (string) ($p->getTelephoneUser() ?? '');
        $addr  = (string) ($p->getAdresseUser() ?? '');
        $cin   = (string) ($p->getCin() ?? '');

        if ($email) $score += 20; else { $miss += 20; $details[] = 'Email manquant'; }
        if ($phone) $score += 20; else { $miss += 20; $details[] = 'Téléphone manquant'; }
        if ($addr)  $score += 20; else { $miss += 20; $details[] = 'Adresse manquante'; }
        if ($cin)   $score += 20; else { $miss += 20; $details[] = 'CIN manquant'; }
        if ($p->isVerified()) $score += 20; else { $details[] = 'Compte non vérifié'; }

        $emailValid = $email && filter_var($email, FILTER_VALIDATE_EMAIL);
        $phoneValid = $phone && preg_match('/^\+?[0-9\s\-]{7,}$/', $phone);
        if ($email && !$emailValid) { $invalid += 10; $details[] = 'Email invalide'; }
        if ($phone && !$phoneValid) { $invalid += 10; $details[] = 'Téléphone invalide'; }

        $contactBad = (!$phoneValid) || (!$emailValid && $email !== '');

        return [
            'score' => max(0, min(100, $score - $invalid)),
            'scoreMissing' => $miss,
            'scoreInvalid' => $invalid,
            'incomplete' => ($miss > 0),
            'contactBad' => $contactBad,
            'details' => $details,
        ];
    }

    private function buildStats(array $patients): array
    {
        $total = count($patients);
        $verified = 0; $complete = 0; $missing = ['telephone' => 0, 'adresse' => 0, 'cin' => 0, 'email' => 0];
        foreach ($patients as $p) {
            if ($p->isVerified()) $verified++;
            $qs = $this->qualityScore($p);
            if (!$qs['incomplete']) $complete++;
            if (!$p->getTelephoneUser()) $missing['telephone']++;
            if (!$p->getAdresseUser()) $missing['adresse']++;
            if (!$p->getCin()) $missing['cin']++;
            if (!$p->getEmailUser()) $missing['email']++;
        }
        return [
            'total' => $total,
            'verifiedPct' => $total ? round($verified * 100 / $total) : 0,
            'completePct' => $total ? round($complete * 100 / $total) : 0,
            'missing' => $missing,
        ];
    }


}
