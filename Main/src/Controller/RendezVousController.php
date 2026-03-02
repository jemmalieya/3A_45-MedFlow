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
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('confirm_appointment' . $id, is_string($token) ? $token : (string)$token)) {
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
            $dateTime = $appointment->getDatetime();
            if ($dateTime instanceof \DateTime) {
                $mailerService->sendRendezVousConfirmed(
                    $patient->getEmailUser(),
                    $patient->getNom() . ' ' . $patient->getPrenom(),
                    $dateTime
                );
            }
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
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        return $this->render('rendez_vous/index.html.twig', [
            'controller_name' => 'RendezVousController',
            'staffList' => $staffList,
        ]);
    }
    #[Route('/appointment/{idStaff}', name: 'appointment', requirements: ['idStaff' => '\d+'])]
    public function appointment(Request $request, EntityManagerInterface $em, \App\Service\UrgencyDetectionService $urgencyService, ?int $idStaff = null): Response
    {
        $rendezVous = new RendezVous();
        $session = $request->getSession();
        $patientId = $session->get('patient_id');
        if ($patientId) {
            $patient = $em->getRepository(\App\Entity\User::class)->find($patientId);
            if ($patient) {
                $rendezVous->setPatient($patient);
            }
        }
        if ($idStaff !== null) {
            $staff = $em->getRepository(\App\Entity\User::class)->find($idStaff);
            if ($staff) {
                $rendezVous->setStaff($staff);
            }
        }
        $form = $this->createForm(RendezVousType::class, $rendezVous, ['validation_groups' => ['Default']]);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                try {
                    $rendezVous->createdAt = new \DateTimeImmutable();
                    // AI urgency detection
                    $urgency = 'Normal';
                    try {
                        $motif = $rendezVous->getMotif();
                        $urgency = $motif !== null ? $urgencyService->detectUrgency($motif) : 'Normal';
                    } catch (\Exception $e) {
                        $urgency = 'Normal';
                    }
                    $rendezVous->setUrgencyLevel($urgency);
                    $em->persist($rendezVous);
                    $em->flush();
                    $this->addFlash('success', 'Rendez-vous créé avec succès.');
                    return $this->redirectToRoute('rendezvous_list');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur: ' . $e->getMessage());
                }
            } else {
                $this->addFlash('error', 'Erreur de Soumettre.');
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
        $rendezvous = $patient ? $repo->findBy(['patient' => $patient], ['createdAt' => 'DESC'], 50) : [];

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
            $rdvIds = array_map(fn($rdv) => $rdv->getId(), $rendezvous);
            if ($rdvIds) {
                $qb = $em->createQueryBuilder();
                $qb->select('f, r')
                    ->from('App\\Entity\\FicheMedicale', 'f')
                    ->leftJoin('f.rendezVous', 'r')
                    ->where($qb->expr()->in('r.id', ':rdvIds'))
                    ->setParameter('rdvIds', $rdvIds);
                $fiches_medicales = $qb->getQuery()->getResult();
            }
        }

        // Get all prescriptions related to the user's fiche médicales (optimized with JOIN)
        if ($fiches_medicales) {
            $ficheIds = array_map(fn($fiche) => $fiche->getId(), $fiches_medicales);
            if ($ficheIds) {
                $qb = $em->createQueryBuilder();
                $qb->select('p')
                    ->from('App\\Entity\\Prescription', 'p')
                    ->leftJoin('p.ficheMedicale', 'f')
                    ->where($qb->expr()->in('f.id', ':ficheIds'))
                    ->setParameter('ficheIds', $ficheIds);
                $prescriptions = $qb->getQuery()->getResult();
            }
        }

        return $this->render('rendez_vous/rendezvous_list.html.twig', [
            'rendezvous' => $rendezvous,
            'patientId' => $patientId,
            'fiches_medicales' => $fiches_medicales,
            'prescriptions' => $prescriptions,
            'editForm' => $editForm ? $editForm->createView() : null,
            'openEdit' => $openEdit,
        ]);
    }

    #[Route('/rendezvous/{id}/delete', name: 'rendezvous_delete', methods: ['POST'])]
    public function delete(Request $request, EntityManagerInterface $em, int $id): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete'.$id, is_string($token) ? $token : (string)$token)) {
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


    #[Route('/rendezvous/{id}/edit', name: 'modify_rendezvous', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, int $id): Response
    {
        $repo = $em->getRepository(RendezVous::class);
        $r = $repo->find($id);
        if (!$r) {
            $this->addFlash('error', 'Rendez-vous introuvable.');
            return $this->redirectToRoute('rendezvous_list');
        }
        $form = $this->createForm(RendezVousType::class, $r, ['validation_groups' => ['edit']]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $em->persist($r);
                $em->flush();
                $this->addFlash('success', 'Rendez-vous mis à jour.');
                return $this->redirectToRoute('rendezvous_list');
            } catch (\Exception $e) {
                $form->addError(new \Symfony\Component\Form\FormError('Erreur lors de la mise à jour: ' . $e->getMessage()));
            }
        }
        return $this->render('rendez_vous/modifyRendezvous.html.twig', [
            'form' => $form->createView(),
            'rendezvous' => $r,
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
