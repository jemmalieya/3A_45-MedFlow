<?php

namespace App\Controller;

use App\Entity\FicheMedicale;
use App\Repository\FicheMedicaleRepository;
use App\Repository\RendezVousRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FicheMedicaleController extends AbstractController
{
    #[Route('/fiche', name: 'app_fiche_medicale')]
    public function index(): Response
    {
        return $this->render('fiche_medicale/ficheMed.html.twig', [
            'controller_name' => 'FicheMedicaleController',
        ]);
    }

    #[Route('/fiche/staff/{idStaff}', name: 'app_fiche_by_staff')]
    public function byStaff(RendezVousRepository $repo, FicheMedicaleRepository $ficheRepo, int $idStaff): Response
    {
        // Fetch all RendezVous for a specific staff member
        $rendezvous = $repo->findBy(['idStaff' => $idStaff], ['datetime' => 'DESC']);
        
        // Fetch all FicheMedicales related to RendezVous of this staff member
        $fiches = $ficheRepo->findFichesByStaffId($idStaff);
        
        return $this->render('fiche_medicale/ficheMed.html.twig', [
            'rendezvous' => $rendezvous,
            'fiches' => $fiches,
            'idStaff' => $idStaff,
        ]);
    }

    #[Route('/start/{id}', name: 'start_consultation')]
    public function startConsultation(int $id, RendezVousRepository $rendezRepo): Response
    {
        $rendez = $rendezRepo->find($id);
        if (!$rendez) {
            return $this->redirectToRoute('app_fiche_medicale');
        }

        // Do not persist yet. Pass rendezvous id and start time to the consultation view via query params.
        $start = new \DateTime();
        return $this->redirectToRoute('consultation_view', [
            'rendezvous' => $id,
            'start' => $start->format('Y-m-d H:i:s'),
        ]);
    }

    #[Route('/consultation', name: 'consultation_view', methods: ['GET','POST'])]
    public function consultation(Request $request, FicheMedicaleRepository $ficheRepo, RendezVousRepository $rendezRepo, EntityManagerInterface $em): Response
    {
        // Determine whether we're working with an existing fiche or creating a new one
        $ficheId = $request->query->get('id') ?? $request->request->get('fiche_id');
        $fiche = null;
        if ($ficheId) {
            $fiche = $ficheRepo->find((int)$ficheId);
            if (!$fiche) {
                return $this->redirectToRoute('app_fiche_medicale');
            }
        }

        // If POST, handle save/cancel
        if ($request->isMethod('POST')) {
            // Cancel -> go back to main fiche page without persisting anything
            if ($request->request->has('cancel')) {
                return $this->redirectToRoute('app_fiche_medicale');
            }

            if ($request->request->has('save')) {
                // If we don't have a fiche entity yet, create and persist now
                if (!$fiche) {
                    $rendezId = $request->request->get('rendezvous_id');
                    $rendez = $rendezId ? $rendezRepo->find((int)$rendezId) : null;
                    if (!$rendez) {
                        return $this->redirectToRoute('app_fiche_medicale');
                    }

                    $fiche = new FicheMedicale();
                    $fiche->setRendezVous($rendez);

                    // startTime may be passed as hidden input
                    $startStr = $request->request->get('startTime');
                    if ($startStr) {
                        try {
                            $startDt = new \DateTime($startStr);
                            $fiche->setStartTime($startDt);
                        } catch (\Exception $e) {
                            $fiche->setStartTime(new \DateTime());
                        }
                    } else {
                        $fiche->setStartTime(new \DateTime());
                    }
                }

                // Set fields from form
                $diagnostic = $request->request->get('diagnostic');
                $observations = $request->request->get('observations');
                $resultats = $request->request->get('resultatsExamens');

                $fiche->setDiagnostic($diagnostic ?? '');
                $fiche->setObservations($observations);
                $fiche->setResultatsExamens($resultats);

                // End time now and compute duration
                $end = new \DateTime();
                $fiche->setEndTime($end);
                $fiche->setCreatedAt(new \DateTime());

                $start = $fiche->getStartTime();
                if ($start instanceof \DateTime && $end instanceof \DateTime) {
                    $diff = $end->getTimestamp() - $start->getTimestamp();
                    $minutes = (int) round($diff / 60);
                    $fiche->setDureeMinutes($minutes);
                }

                // Set the RendezVous status to 'Confirmé'
                $rendez = $fiche->getRendezVous();
                if ($rendez) {
                    $rendez->setStatut('Confirmé');
                    $em->persist($rendez);
                }

                $em->persist($fiche);
                $em->flush();

                $staffId = $fiche->getRendezVous()?->getIdStaff();
                return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
            }
        }

        // For GET: get possible rendezvous and start from query params
        $rendezvousId = $request->query->get('rendezvous');
        $startParam = $request->query->get('start');

        // Determine idStaff for cancel button redirect
        $idStaff = null;
        if ($fiche && $fiche->getRendezVous()) {
            $idStaff = $fiche->getRendezVous()->getIdStaff();
        } elseif ($rendezvousId) {
            $rendez = $rendezRepo->find((int)$rendezvousId);
            if ($rendez) {
                $idStaff = $rendez->getIdStaff();
            }
        }

        return $this->render('fiche_medicale/consultation.html.twig', [
            'fiche' => $fiche,
            'rendezvousId' => $rendezvousId,
            'startParam' => $startParam,
            'idStaff' => $idStaff,
        ]);
    }

    #[Route('/fiche/delete/{id}', name: 'app_fiche_delete', methods: ['POST'])]
    public function deleteFiche(int $id, Request $request, FicheMedicaleRepository $ficheRepo, EntityManagerInterface $em): Response
    {
        $fiche = $ficheRepo->find($id);
        if (!$fiche) {
            return $this->redirectToRoute('app_fiche_medicale');
        }

        $token = $request->request->get('_token');
        if ($this->isCsrfTokenValid('delete'.$fiche->getId(), $token)) {
            $rendez = $fiche->getRendezVous();
            $em->remove($fiche);
            $em->flush();
            $staffId = $rendez?->getIdStaff();
            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
        }

        $staffId = $fiche->getRendezVous()?->getIdStaff();
        return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
    }
}
