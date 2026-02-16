<?php

namespace App\Controller;

use App\Entity\RendezVous;
use App\Form\RendezVousType;
use App\Repository\UserRepository;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RendezVousController extends AbstractController
{
    #[Route('/rendezvous/{id}/confirm', name: 'confirm_appointment', methods: ['POST'])]
    public function confirmAppointment(Request $request, EntityManagerInterface $em, int $id, MailerService $mailerService): Response
    {
        if (!$this->isCsrfTokenValid('confirm_appointment' . $id, $request->request->get('_token'))) {
            // Invalid CSRF, just redirect back to staff fiche list (no message)
            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $request->query->get('idStaff')]);
        }
        $repo = $em->getRepository(RendezVous::class);
        $appointment = $repo->find($id);
        if (!$appointment) {
            // Not found, just redirect back to staff fiche list (no message)
            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $request->query->get('idStaff')]);
        }
        $appointment->setStatut('Confirmé');
        $em->persist($appointment);
        $em->flush();
        // Send confirmation email to patient
        $patient = $appointment->getPatient();
        if ($patient && $patient->getEmailUser()) {
            $mailerService->sendRendezVousConfirmed(
                $patient->getEmailUser(),
                $patient->getNom() . ' ' . $patient->getPrenom(),
                $appointment->getDatetime()
            );
        }
        // Redirect back to staff fiche list for the appointment's staff
        $staff = $appointment->getStaff();
        $staffId = $staff ? $staff->getId() : null;
        if ($staffId) {
            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
        } else {
            // fallback: redirect to fiche list
            return $this->redirectToRoute('app_fiche_medicale');
        }
    }
    #[Route('/homeC', name: 'rendezvous_home')]
    public function index(EntityManagerInterface $em): Response
    {
        // Fetch all users with roleSysteme = 'STAFF' or 'ADMIN'
        $staffList = $em->getRepository(\App\Entity\User::class)
            ->createQueryBuilder('u')
            ->andWhere('u.roleSysteme IN (:roles)')
            ->setParameter('roles', ['STAFF', 'ADMIN'])
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('rendez_vous/index.html.twig', [
            'controller_name' => 'RendezVousController',
            'staffList' => $staffList,
        ]);
    }
    #[Route('/appointment/{idStaff}', name: 'appointment', requirements: ['idStaff' => '\d+'])]
    public function appointment(Request $request, EntityManagerInterface $em, ?int $idStaff = null): Response
    {
        $rendezVous = new RendezVous();
        // Set patient from session patient_id
        $session = $request->getSession();
        $patientId = $session->get('patient_id');
        if ($patientId) {
            $patient = $em->getRepository(\App\Entity\User::class)->find($patientId);
            if ($patient) {
                $rendezVous->setPatient($patient);
            }
        }
        // Set staff to User with idStaff if provided
        if ($idStaff !== null) {
            $staff = $em->getRepository(\App\Entity\User::class)->find($idStaff);
            if ($staff) {
                $rendezVous->setStaff($staff);
            }
        }
        
        $form = $this->createForm(RendezVousType::class, $rendezVous);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $this->addFlash('success', 'DEBUG: Form is valid and submitted.');
                try {
                    $rendezVous->setCreatedAt(new \DateTime());
                    $this->addFlash('success', 'DEBUG: Before persist.');
                    $em->persist($rendezVous);
                    $this->addFlash('success', 'DEBUG: Before flush.');
                    $em->flush();
                    $this->addFlash('success', 'Rendez-vous créé avec succès.');
                    return $this->redirectToRoute('rendezvous_list');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur: ' . $e->getMessage());
                }
            } else {
                $this->addFlash('error', 'DEBUG: Form is submitted but NOT valid.');
            }
        }

        return $this->render('rendez_vous/appointement.html.twig', [
            'form' => $form->createView(),
            'idStaff' => $idStaff,
        ]);
    }

    #[Route('/rendezvous', name: 'rendezvous_list')]
    public function list(EntityManagerInterface $em, Request $request): Response
    {
        $openEdit = $request->query->get('openEdit');
        $requestStack = $this->container->get('request_stack');
        $session = $requestStack->getCurrentRequest()->getSession();
        $patientId = $session->get('patient_id');
        $patient = null;
        $fiches_medicales = [];
        $prescriptions = [];
        if ($patientId) {
            $patient = $em->getRepository(\App\Entity\User::class)->find($patientId);
        }
        $repo = $em->getRepository(RendezVous::class);
        $ficheRepo = $em->getRepository(\App\Entity\FicheMedicale::class);
        $prescRepo = $em->getRepository(\App\Entity\Prescription::class);
        $rendezvous = $patient ? $repo->findBy(['patient' => $patient], ['createdAt' => 'DESC']) : [];

        // Inline edit form for the open row
        $editForm = null;
        if ($openEdit) {
            $editRdv = $repo->find($openEdit);
            if ($editRdv) {
                $editForm = $this->createForm(\App\Form\RendezVousType::class, $editRdv);
                $editForm->handleRequest($request);
            }
        }

        // Get all fiche médicales related to the user's rendezvous
        if ($rendezvous) {
            $fiche_med_ids = [];
            foreach ($rendezvous as $rdv) {
                $fiche = $ficheRepo->findOneBy(['rendezVous' => $rdv]);
                if ($fiche) {
                    $fiches_medicales[] = $fiche;
                }
            }
        }

        // Get all prescriptions related to the user's fiche médicales
        if ($fiches_medicales) {
            foreach ($fiches_medicales as $fiche) {
                $fichePrescriptions = $prescRepo->findBy(['ficheMedicale' => $fiche]);
                foreach ($fichePrescriptions as $presc) {
                    $prescriptions[] = $presc;
                }
            }
        }

        return $this->render('rendez_vous/rendezvous_list.html.twig', [
            'rendezvous' => $rendezvous,
            'patientId' => $patientId,
            'fiches_medicales' => $fiches_medicales,
            'prescriptions' => $prescriptions,
            'editForm' => isset($editForm) && $editForm ? $editForm->createView() : null,
            'openEdit' => $openEdit,
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

        // Create a form for editing, bind to the entity
        $form = $this->createForm(RendezVousType::class, $r);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $em->persist($r);
                $em->flush();
                $this->addFlash('success', 'Rendez-vous mis à jour.');
                return $this->redirectToRoute('rendezvous_list');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de la mise à jour: ' . $e->getMessage());
                // Render list with edit form and errors
                return $this->renderListWithEditForm($em, $request, $r, $form);
            }
        }

        // If not valid, show errors and open the edit row
        $this->addFlash('error', 'Erreur de validation.');
        return $this->renderListWithEditForm($em, $request, $r, $form);

    }

    // Helper to render list page with edit form and errors
    private function renderListWithEditForm(EntityManagerInterface $em, Request $request, $editRdv, $editForm)
    {
        $requestStack = $this->container->get('request_stack');
        $session = $requestStack->getCurrentRequest()->getSession();
        $patientId = $session->get('patient_id');
        $patient = null;
        $fiches_medicales = [];
        $prescriptions = [];
        if ($patientId) {
            $patient = $em->getRepository(\App\Entity\User::class)->find($patientId);
        }
        $repo = $em->getRepository(RendezVous::class);
        $ficheRepo = $em->getRepository(\App\Entity\FicheMedicale::class);
        $prescRepo = $em->getRepository(\App\Entity\Prescription::class);
        $rendezvous = $patient ? $repo->findBy(['patient' => $patient], ['createdAt' => 'DESC']) : [];
        if ($rendezvous) {
            foreach ($rendezvous as $rdv) {
                $fiche = $ficheRepo->findOneBy(['rendezVous' => $rdv]);
                if ($fiche) {
                    $fiches_medicales[] = $fiche;
                }
            }
        }
        if ($fiches_medicales) {
            foreach ($fiches_medicales as $fiche) {
                $fichePrescriptions = $prescRepo->findBy(['ficheMedicale' => $fiche]);
                foreach ($fichePrescriptions as $presc) {
                    $prescriptions[] = $presc;
                }
            }
        }
        return $this->render('rendez_vous/rendezvous_list.html.twig', [
            'rendezvous' => $rendezvous,
            'patientId' => $patientId,
            'fiches_medicales' => $fiches_medicales,
            'prescriptions' => $prescriptions,
            'editForm' => $editForm->createView(),
            'openEdit' => $editRdv->getId(),
        ]);
    }
    #[Route('/iaasssistante', name: 'app_ia_assistante')]
    public function iaAssistante(UserRepository $userRepo): Response
    {
        return $this->render('rendez_vous/ia.html.twig', [
            'controller_name' => 'RendezVousController',
        ]);
        
    }
    }
