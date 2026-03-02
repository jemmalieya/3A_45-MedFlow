<?php

namespace App\Controller;

use App\Entity\FicheMedicale;
use App\Entity\Prescription;
use App\Repository\FicheMedicaleRepository;
use App\Repository\RendezVousRepository;
use App\Repository\PrescriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\MailerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Dompdf\Dompdf;
use Dompdf\Options;

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
    public function byStaff(RendezVousRepository $repo, FicheMedicaleRepository $ficheRepo, PrescriptionRepository $prescRepo, int $idStaff, EntityManagerInterface $entityManager): Response
    {
        // Fetch User entity for staff
        // Use injected EntityManagerInterface
        $staffUser = $entityManager->getRepository(\App\Entity\User::class)->find($idStaff);
        // Fetch all RendezVous for a specific staff member, eager load 'mode' to avoid N+1
        $qb = $repo->createQueryBuilder('r')
            ->leftJoin('r.mode', 'm')
            ->addSelect('m')
            ->where('r.staff = :staff')
            ->setParameter('staff', $staffUser)
            ->orderBy('r.datetime', 'DESC');
        $rendezvous = $qb->getQuery()->getResult();

        // Fetch all FicheMedicales related to RendezVous of this staff member, eager load rendezVous to avoid N+1
        $qbFiche = $ficheRepo->createQueryBuilder('f')
            ->leftJoin('f.rendezVous', 'r')
            ->addSelect('r')
            ->where('r.staff = :staff')
            ->setParameter('staff', $staffUser);
        $fiches = $qbFiche->getQuery()->getResult();

        // Also fetch prescriptions related to this staff's fiches (via fiche -> rendez_vous -> staff)
            $prescriptions = $prescRepo->createQueryBuilder('p')
                ->innerJoin('p.ficheMedicale', 'f')
                ->innerJoin('f.rendezVous', 'r')
                ->andWhere('r.staff = :staff')
                ->setParameter('staff', $staffUser)
                ->orderBy('p.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

        return $this->render('fiche_medicale/ficheMed.html.twig', [
            'rendezvous' => $rendezvous,
            'fiches' => $fiches,
            'prescriptions' => $prescriptions,
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
        
        // Check appointment mode and redirect to appropriate consultation template
        $type = 'Présentiel'; // Default
        if ($rendez->getMode() === 'Distanciel') {
            $type = 'Distanciel';
        }
        
        return $this->redirectToRoute('consultation_view', [
            'rendezvous' => $id,
            'start' => $start->format('Y-m-d H:i:s'),
            'type' => $type,
        ]);
    }

    #[Route('/consultation', name: 'consultation_view', methods: ['GET','POST'])]
    public function consultation(Request $request, FicheMedicaleRepository $ficheRepo, RendezVousRepository $rendezRepo, EntityManagerInterface $em, LoggerInterface $logger, MailerService $mailerService): Response
    {
        // Determine consultation type (presentiel or distanciel) - check POST first, then query params
        $type = $request->request->get('type') ?? $request->query->get('type', 'Présentiel');
        $templateName = $type === 'Distanciel' ? 'consultationOnline.html.twig' : 'consultation.html.twig';
        // Send Jitsi link to patient if Distanciel and not already sent
        if ($type === 'Distanciel') {
            $rendezvousId = $request->query->get('rendezvous');
            $rendez = $rendezRepo->find((int)$rendezvousId);
            if ($rendez && $rendez->getPatient()) {
                $doctorName = $rendez->getStaff() ? $rendez->getStaff()->getNom() . ' ' . $rendez->getStaff()->getPrenom() : 'Médecin';
                $roomName = 'medflow-' . time() . '-' . rand(1000,9999);
                $email = $rendez->getPatient()->getEmailUser();
                if (is_string($email) && $email !== '') {
                    $mailerService->sendJitsiLink($email, $doctorName, $roomName);
                }
            }
        }

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
            // Remove debug error flashes; only add error flashes when validation fails
            // Cancel -> go back to main fiche page without persisting anything
            if ($request->request->has('cancel')) {
                return $this->redirectToRoute('app_fiche_medicale');
            }

            if ($request->request->has('save')) {
                // Get fields from form
                $diagnostic = trim((string)($request->request->get('diagnostic') ?? ''));
                $observations = trim((string)($request->request->get('observations') ?? ''));
                $resultats = trim((string)($request->request->get('resultatsExamens') ?? ''));
                $sigVal = $request->request->get('signature');
                $signature = is_string($sigVal) || is_null($sigVal) ? $sigVal : (string)$sigVal;
   
                // PHP Server-side validation (controle de saisie)
                $validationErrors = [];
                $fieldErrors = [
                    'diagnostic' => [],
                    'observations' => [],
                    'resultatsExamens' => [],
                ];

                // Validate Diagnostic (required, min 5, max 150)
                if (empty($diagnostic)) {
                    $fieldErrors['diagnostic'][] = 'Diagnostic field is required.';
                } elseif (strlen($diagnostic) < 5) {
                    $fieldErrors['diagnostic'][] = 'Diagnostic must be at least 5 characters.';
                } elseif (strlen($diagnostic) > 150) {
                    $fieldErrors['diagnostic'][] = 'Diagnostic cannot exceed 150 characters.';
                }

                // Validate Observations (required, min 5, max 150)
                if (empty($observations)) {
                    $fieldErrors['observations'][] = 'Observations field is required.';
                } elseif (strlen($observations) < 5) {
                    $fieldErrors['observations'][] = 'Observations must be at least 5 characters.';
                } elseif (strlen($observations) > 150) {
                    $fieldErrors['observations'][] = 'Observations cannot exceed 150 characters.';
                }

                // Validate Exam Results (required, min 5, max 150)
                if (empty($resultats)) {
                    $fieldErrors['resultatsExamens'][] = 'Exam Results field is required.';
                } elseif (strlen($resultats) < 5) {
                    $fieldErrors['resultatsExamens'][] = 'Exam Results must be at least 5 characters.';
                } elseif (strlen($resultats) > 150) {
                    $fieldErrors['resultatsExamens'][] = 'Exam Results cannot exceed 150 characters.';
                }

                // Collect all errors for flash/global display if needed
                foreach ($fieldErrors as $field => $errs) {
                    foreach ($errs as $err) {
                        $validationErrors[] = $err;
                    }
                }

                // If validation errors exist, return with error message and field errors
                if (!empty($validationErrors)) {
                    $this->addFlash('error', 'Validation failed.');
                    $this->addFlash('fieldErrors', $fieldErrors);
                    // If editing existing fiche (modal), redirect back to fiche by staff page
                    if ($fiche && $fiche->getRendezVous() && $fiche->getRendezVous()->getStaff()) {
                        $staffId = $fiche->getRendezVous()->getStaff()->getId();
                        return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
                    }
                    // If creating new fiche, redirect to consultation form with same type
                    else {
                        $rendezId = $request->request->get('rendezvous_id');
                        $startStr = $request->request->get('startTime');
                        return $this->redirectToRoute('consultation_view', [
                            'rendezvous' => $rendezId,
                            'start' => $startStr,
                            'type' => $type,
                        ]);
                    }
                }

                // If we don't have a fiche entity yet, create and persist now
                if (!$fiche) {
                    $rendezId = $request->request->get('rendezvous_id');
                    if (!$rendezId) {
                        return $this->redirectToRoute('app_fiche_medicale');
                    }
                    // Use getReference to avoid SELECT query
                    $rendez = $em->getReference(\App\Entity\RendezVous::class, (int)$rendezId);
                    $fiche = new FicheMedicale();
                    $fiche->setRendezVous($rendez);

                    // startTime may be passed as hidden input
                    $startStr = $request->request->get('startTime');
                    if (is_string($startStr) && $startStr !== '') {
                        try {
                            $startDt = new \DateTimeImmutable($startStr);
                            $fiche->setStartTime($startDt);
                        } catch (\Exception $e) {
                            $fiche->setStartTime(new \DateTimeImmutable());
                        }
                    } else {
                        $fiche->setStartTime(new \DateTimeImmutable());
                    }
                }

                $fiche->setDiagnostic($diagnostic);
                $fiche->setObservations($observations);
                $fiche->setResultatsExamens($resultats);
                $fiche->setSignature($signature);

                $end = new \DateTimeImmutable();
                $fiche->setEndTime($end);
                $fiche->setCreatedAt(new \DateTimeImmutable());

                $start = $fiche->getStartTime();
                if ($start instanceof \DateTime) {
                    $diff = $end->getTimestamp() - $start->getTimestamp();
                    $minutes = (int) round($diff / 60);
                    $fiche->setDureeMinutes($minutes);
                }

                $rendez = $fiche->getRendezVous();
                if ($rendez) {
                    $rendez->setStatut('Terminée');
                    $em->persist($rendez);
                }

                $em->persist($fiche);
                    // Handle prescriptions (zero or more)
                    $all = $request->request->all();
                    $prescriptionRows = $all['prescription_rows'] ?? [];
                    if (is_array($prescriptionRows)) {
                        foreach ($prescriptionRows as $row) {
                            // Skip empty rows (all fields empty)
                            if (
                                empty($row['medicament']) &&
                                empty($row['dosage']) &&
                                empty($row['frequence']) &&
                                empty($row['duree'])
                            ) {
                                continue;
                            }
                            $prescription = new Prescription();
                            $prescription->setFicheMedicale($fiche);
                            $prescription->setNomMedicament($row['medicament'] ?? '');
                            $prescription->setDose($row['dosage'] ?? '');
                            $prescription->setFrequence($row['frequence'] ?? '');
                            // Duree is int, handle empty/null
                            $dureeVal = isset($row['duree']) && $row['duree'] !== '' ? (int)$row['duree'] : null;
                            if ($dureeVal !== null) {
                                $prescription->setDuree($dureeVal);
                            }
                            $prescription->setCreatedAt(new \DateTimeImmutable());
                            $em->persist($prescription);
                        }
                    }

                    $em->flush();

                    $staffId = $fiche->getRendezVous()?->getStaff()?->getId();
                    return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
            }
        }

        // For GET: get possible rendezvous and start from query params
        $rendezvousId = $request->query->get('rendezvous');
        $startParam = $request->query->get('start');

        // Determine idStaff for cancel button redirect
        $idStaff = null;
        if ($fiche && $fiche->getRendezVous() && $fiche->getRendezVous()->getStaff()) {
            $idStaff = $fiche->getRendezVous()->getStaff()->getId();
        } elseif ($rendezvousId) {
            $rendez = $rendezRepo->find((int)$rendezvousId);
            if ($rendez && $rendez->getStaff()) {
                $idStaff = $rendez->getStaff()->getId();
            }
        }

        return $this->render('fiche_medicale/' . $templateName, [
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
        if ($this->isCsrfTokenValid('delete'.$fiche->getId(), is_string($token) ? $token : (string)$token)) {
            $rendez = $fiche->getRendezVous();
            $em->remove($fiche);
            $em->flush();
            $staffId = $rendez?->getStaff()?->getId();
            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
        }

        $staffId = $fiche->getRendezVous()?->getStaff()?->getId();
        return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
    }
    #[Route('/fiche/{id}/pdf', name: 'fiche_pdf', methods: ['GET'])]
public function fichePdf(int $id, FicheMedicaleRepository $ficheRepo): Response
{
    $fiche = $ficheRepo->find($id);
    if (!$fiche) {
        throw $this->createNotFoundException('Fiche médicale non trouvée');
    }

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isPhpEnabled', true);

    $dompdf = new Dompdf($options);

    $html = $this->renderView('pdf/fiche_medicale.html.twig', [
        'fiche' => $fiche,
    ]);

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfOutput = $dompdf->output();

    return new Response($pdfOutput, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="fiche_medicale_' . $fiche->getId() . '.pdf"',
    ]);
}
}
