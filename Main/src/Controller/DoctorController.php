<?php

namespace App\Controller;

use App\Repository\RendezVousRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class DoctorController extends AbstractController
{
    #[Route('/doctor/appointments/{id}', name: 'doctor_appointments', methods: ['GET'])]
    public function appointments(int $id, RendezVousRepository $repo): JsonResponse
    {
        $appointments = $repo->findActiveByStaff($id);
        $events = [];
        foreach ($appointments as $rdv) {
            $start = $rdv->getDatetime();
            $end = (clone $start)->modify('+1 hour');
            $events[] = [
                'title' => 'Occupé(e)',
                'start' => $start->format('Y-m-d\TH:i:s'),
                'end' => $end->format('Y-m-d\TH:i:s'),
                'color' => '#dc3545',
                'allDay' => false,
            ];
        }
        return new JsonResponse($events);
    }
}
