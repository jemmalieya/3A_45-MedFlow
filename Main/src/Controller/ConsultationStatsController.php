<?php

namespace App\Controller;

use App\Repository\RendezVousRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ConsultationStatsController extends AbstractController
{
    #[Route('/consultation/stats', name: 'consultation_stats')]
    public function stats(Request $request, EntityManagerInterface $em): Response
    {
        $session = $request->getSession();
        $doctorId = $session->get('doctor_id');
        if (!$doctorId) {
            $this->addFlash('error', 'Aucun médecin connecté.');
            return $this->redirectToRoute('app_home');
        }
        $doctor = $em->getRepository('App\\Entity\\User')->find($doctorId);
        if (!$doctor) {
            $this->addFlash('error', 'Médecin introuvable.');
            return $this->redirectToRoute('app_home');
        }
        // Get all FicheMedicale for this doctor's rendezvous
        $qb = $em->createQueryBuilder();
        $fiches = $qb->select('f')
            ->from('App\\Entity\\FicheMedicale', 'f')
            ->join('f.rendezVous', 'r')
            ->where('r.staff = :doctor')
            ->setParameter('doctor', $doctor)
            ->getQuery()
            ->getResult();

        $durations = [];
        $modeCounts = ['Présentiel' => 0, 'Distanciel' => 0, 'Autre' => 0];
        foreach ($fiches as $fiche) {
            $start = $fiche->getStartTime();
            $end = $fiche->getEndTime();
            if ($start && $end) {
                $durations[] = ($end->getTimestamp() - $start->getTimestamp()) / 60.0;
            }
            $mode = $fiche->getRendezVous() && $fiche->getRendezVous()->getMode() ? $fiche->getRendezVous()->getMode() : 'Autre';
            if (isset($modeCounts[$mode])) {
                $modeCounts[$mode]++;
            } else {
                $modeCounts['Autre']++;
            }
        }
        $count = count($durations);
        $average = $count ? array_sum($durations) / $count : 0;
        $max = $count ? max($durations) : 0;
        $min = $count ? min($durations) : 0;
        return $this->render('consultation/stats.html.twig', [
            'doctor' => $doctor,
            'durations' => $durations,
            'average' => $average,
            'max' => $max,
            'min' => $min,
            'count' => $count,
            'modeCounts' => $modeCounts,
        ]);
    }

    #[Route('/consultation/stats/pdf/{idStaff}', name: 'app_stats_pdf')]
    public function exportStatsPdf(int $idStaff, EntityManagerInterface $em): Response
    {
        $doctor = $em->getRepository('App\\Entity\\User')->find($idStaff);
        if (!$doctor) {
            $this->addFlash('error', 'Médecin introuvable.');
            return $this->redirectToRoute('app_home');
        }
        $qb = $em->createQueryBuilder();
        $fiches = $qb->select('f')
            ->from('App\\Entity\\FicheMedicale', 'f')
            ->join('f.rendezVous', 'r')
            ->where('r.staff = :doctor')
            ->setParameter('doctor', $doctor)
            ->getQuery()
            ->getResult();
        $durations = [];
        $modeCounts = ['Présentiel' => 0, 'Distanciel' => 0, 'Autre' => 0];
        foreach ($fiches as $fiche) {
            $start = $fiche->getStartTime();
            $end = $fiche->getEndTime();
            if ($start && $end) {
                $durations[] = ($end->getTimestamp() - $start->getTimestamp()) / 60.0;
            }
            $mode = $fiche->getRendezVous() && $fiche->getRendezVous()->getMode() ? $fiche->getRendezVous()->getMode() : 'Autre';
            if (isset($modeCounts[$mode])) {
                $modeCounts[$mode]++;
            } else {
                $modeCounts['Autre']++;
            }
        }
        $count = count($durations);
        $average = $count ? array_sum($durations) / $count : 0;
        $max = $count ? max($durations) : 0;
        $min = $count ? min($durations) : 0;
        $html = $this->renderView('pdf/stats.html.twig', [
            'doctor' => $doctor,
            'durations' => $durations,
            'average' => $average,
            'max' => $max,
            'min' => $min,
            'count' => $count,
            'modeCounts' => $modeCounts,
        ]);
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $filename = 'stats_' . $doctor->getId() . '.pdf';
        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }
}
