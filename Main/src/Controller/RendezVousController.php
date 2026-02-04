<?php

namespace App\Controller;

use App\Entity\RendezVous;
use App\Form\RendezVousType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class RendezVousController extends AbstractController
{
    #[Route('/homeC', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('rendez_vous/index.html.twig', [
            'controller_name' => 'RendezVousController',
        ]);
    }
    #[Route('/appointment/{idStaff}', name: 'appointment', requirements: ['idStaff' => '\d+'])]
    public function appointment(Request $request, EntityManagerInterface $em, ?int $idStaff = null): Response
    {
        $rendezVous = new RendezVous();
        
        // Set idPatient to 1 (always)
        $rendezVous->setIdPatient(1);
        
        // Set idStaff from URL parameter if provided
        if ($idStaff !== null) {
            $rendezVous->setIdStaff($idStaff);
        }
        
        $form = $this->createForm(RendezVousType::class, $rendezVous);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $rendezVous->setCreatedAt(new \DateTime());
                $em->persist($rendezVous);
                $em->flush();

                $this->addFlash('success', 'Rendez-vous créé avec succès.');
                return $this->redirectToRoute('rendezvous_list');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur: ' . $e->getMessage());
            }
        }

        return $this->render('rendez_vous/appointement.html.twig', [
            'form' => $form->createView(),
            'idStaff' => $idStaff,
        ]);
    }

    #[Route('/rendezvous', name: 'rendezvous_list')]
    public function list(EntityManagerInterface $em): Response
    {
        // Static patient id (change here if you want to view another patient's appointments)
        $patientId = 1;

        $repo = $em->getRepository(RendezVous::class);
        $rendezvous = $repo->findBy(['idPatient' => $patientId], ['createdAt' => 'DESC']);

        return $this->render('rendez_vous/rendezvous_list.html.twig', [
            'rendezvous' => $rendezvous,
            'patientId' => $patientId,
        ]);
    }

    #[Route('/rendezvous/{id}/delete', name: 'rendezvous_delete', methods: ['POST'])]
    public function delete(Request $request, EntityManagerInterface $em, int $id): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('rendezvous_list');
        }

        $repo = $em->getRepository(RendezVous::class);
        $r = $repo->find($id);
        if (!$r) {
            $this->addFlash('error', 'Rendez-vous introuvable.');
            return $this->redirectToRoute('rendezvous_list');
        }

        try {
            $em->remove($r);
            $em->flush();
            $this->addFlash('success', 'Rendez-vous supprimé.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }

        return $this->redirectToRoute('rendezvous_list');
    }

    #[Route('/rendezvous/{id}/edit', name: 'rendezvous_edit', methods: ['POST'])]
    public function edit(Request $request, EntityManagerInterface $em, int $id): Response
    {
        if (!$this->isCsrfTokenValid('edit'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('rendezvous_list');
        }

        $repo = $em->getRepository(RendezVous::class);
        $r = $repo->find($id);
        if (!$r) {
            $this->addFlash('error', 'Rendez-vous introuvable.');
            return $this->redirectToRoute('rendezvous_list');
        }

        $datetime = $request->request->get('datetime'); // expecting HTML datetime-local value
        $mode = $request->request->get('mode');
        $motif = $request->request->get('motif');

        try {
            if ($datetime) {
                $r->setDatetime(new \DateTime($datetime));
            }
            if ($mode !== null) {
                $r->setMode($mode);
            }
            if ($motif !== null) {
                $r->setMotif($motif);
            }

            $em->persist($r);
            $em->flush();
            $this->addFlash('success', 'Rendez-vous mis à jour.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la mise à jour: ' . $e->getMessage());
        }

        return $this->redirectToRoute('rendezvous_list');
    }
}
