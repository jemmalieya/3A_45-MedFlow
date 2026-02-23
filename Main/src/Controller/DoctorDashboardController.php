<?php

namespace App\Controller;

use App\Repository\RendezVousRepository;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
class DoctorDashboardController extends AbstractController
{
    #[Route('/accueil-docteur', name: 'accueil_docteur')]
    public function index(RendezVousRepository $rendezVousRepository, \App\Service\AIMedicalNewsService $newsService, ChartBuilderInterface $chartBuilder): Response
    {
        $doctor = $this->getUser();
        if (!$doctor) {
            throw $this->createAccessDeniedException('You must be logged in as a doctor.');
        }

        $today = new \DateTimeImmutable('today');
        $tomorrow = (new \DateTimeImmutable('today'))->modify('+1 day');

        $appointments = $rendezVousRepository->findTodayAppointmentsForDoctor($doctor, $today, $tomorrow);
        $pendingAppointments = $rendezVousRepository->createQueryBuilder('r')
            ->andWhere('r.statut = :statut')
            ->andWhere('r.staff = :staff')
            ->setParameter('statut', 'Demande')
            ->setParameter('staff', $doctor)
            ->orderBy('r.datetime', 'ASC')
            ->getQuery()
            ->getResult();
        $news = $newsService->getHourlyInsights();

        // Chart Analytics
        $chart = $chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            'datasets' => [
                [
                    'label' => 'Consultations',
                    'backgroundColor' => 'rgba(78,115,223,0.2)',
                    'borderColor' => 'rgba(78,115,223,1)',
                    'data' => [12, 19, 8, 15, 22, 10],
                ],
            ],
        ]);

        return $this->render('fiche_medicale/accueil_docteur.html.twig', [
            'doctor' => $doctor,
            'appointments' => $appointments,
            'pendingAppointments' => $pendingAppointments,
            'today' => $today,
            'news' => $news,
            'chart' => $chart,
        ]);
    }
}
