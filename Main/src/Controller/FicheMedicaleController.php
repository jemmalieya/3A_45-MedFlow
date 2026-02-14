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
        // Fetch all RendezVous for a specific staff member
        $rendezvous = $repo->findBy(['staff' => $staffUser], ['datetime' => 'DESC']);

        // Fetch all FicheMedicales related to RendezVous of this staff member
        $fiches = $ficheRepo->findFichesByStaffId($idStaff); // If this uses idStaff, update to use staffUser if needed

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
                // Generate room name as in JS: medflow-<timestamp>-<random>
                $roomName = 'medflow-' . time() . '-' . rand(1000,9999);
                $mailerService->sendJitsiLink($rendez->getPatient()->getEmailUser(), $doctorName, $roomName);
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
            // Temporary debug log to inspect incoming POST payload when validation fails
            try {
                $logger->debug('Consultation POST payload', [
                    'post' => $request->request->all(),
                    'raw' => $request->getContent(),
                ]);
            } catch (\Throwable $e) {
                // swallow logging errors to avoid interfering with flow
            }
            // Cancel -> go back to main fiche page without persisting anything
            if ($request->request->has('cancel')) {
                return $this->redirectToRoute('app_fiche_medicale');
            }

            if ($request->request->has('save')) {
                // Get fields from form
                $diagnostic = trim($request->request->get('diagnostic') ?? '');
                $observations = trim($request->request->get('observations') ?? '');
                $resultats = trim($request->request->get('resultatsExamens') ?? '');

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

                // Normalize prescription rows before validating/saving.
                // Some server configs or client payloads may present this key in unexpected shapes;
                // try to read from the parsed POST array first, then fall back to parsing the raw body.
                $prescriptionsData = [];
                try {
                    $postAll = $request->request->all();
                    if (array_key_exists('prescription_rows', $postAll)) {
                        $prescriptionsData = $postAll['prescription_rows'];
                    } else {
                        $raw = $request->getContent();
                        if (!empty($raw)) {
                            parse_str($raw, $parsed);
                            if (isset($parsed['prescription_rows'])) {
                                $prescriptionsData = $parsed['prescription_rows'];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // As a last resort try parsing raw body
                    $raw = $request->getContent();
                    $parsed = [];
                    if (!empty($raw)) parse_str($raw, $parsed);
                    $prescriptionsData = $parsed['prescription_rows'] ?? [];
                }

                if (!is_array($prescriptionsData)) {
                    $prescriptionsData = [];
                }
                $validPrescriptions = [];
                foreach ($prescriptionsData as $index => $row) {
                    $nomMedicament = isset($row['nomMedicament']) ? trim((string) $row['nomMedicament']) : '';
                    $dose = isset($row['dose']) ? trim((string) $row['dose']) : '';
                    $frequence = isset($row['frequence']) ? trim((string) $row['frequence']) : '';
                    $dureeRaw = isset($row['duree']) ? trim((string) $row['duree']) : '';
                    $instructions = isset($row['instructions']) ? trim((string) $row['instructions']) : '';

                    if ($nomMedicament === '' && $dose === '' && $frequence === '' && $dureeRaw === '' && $instructions === '') {
                        continue;
                    }

                    $prescErrors = [];
                    if (strlen($nomMedicament) === 0) {
                        $prescErrors[] = "Prescription #" . ((int)$index + 1) . ": Medication name is required.";
                    } elseif (strlen($nomMedicament) > 255) {
                        $prescErrors[] = "Prescription #" . ((int)$index + 1) . ": Medication name cannot exceed 255 characters.";
                    }
                    if (strlen($dose) === 0) {
                        $prescErrors[] = "Prescription #" . ((int)$index + 1) . ": Dose is required.";
                    } elseif (strlen($dose) > 255) {
                        $prescErrors[] = "Prescription #" . ((int)$index + 1) . ": Dose cannot exceed 255 characters.";
                    }
                    if (strlen($frequence) === 0) {
                        $prescErrors[] = "Prescription #" . ((int)$index + 1) . ": Frequency is required.";
                    } elseif (strlen($frequence) > 255) {
                        $prescErrors[] = "Prescription #" . ((int)$index + 1) . ": Frequency cannot exceed 255 characters.";
                    }
                    if ($dureeRaw === '') {
                        $prescErrors[] = "Prescription #" . ((int)$index + 1) . ": Duration is required.";
                    } else {
                        $dureeVal = filter_var($dureeRaw, FILTER_VALIDATE_INT);
                        if ($dureeVal === false || $dureeVal < 1) {
                            $prescErrors[] = "Prescription #" . ((int)$index + 1) . ": Duration must be a positive integer (e.g. days).";
                        }
                    }

                    if (!empty($prescErrors)) {
                        foreach ($prescErrors as $err) {
                            $this->addFlash('error', $err);
                        }
                        if ($fiche && $fiche->getRendezVous()) {
                            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $fiche->getRendezVous()->getStaff()->getId()]);
                        }
                        return $this->redirectToRoute('consultation_view', [
                            'rendezvous' => $request->request->get('rendezvous_id'),
                            'start' => $request->request->get('startTime'),
                            'type' => $type,
                        ]);
                    }

                    $validPrescriptions[] = [
                        'nomMedicament' => $nomMedicament,
                        'dose' => $dose,
                        'frequence' => $frequence,
                        'duree' => (int) $dureeRaw,
                        'instructions' => $instructions === '' ? null : $instructions,
                    ];
                }

                // Set validated fields into fiche object
                $fiche->setDiagnostic($diagnostic);
                $fiche->setObservations($observations);
                $fiche->setResultatsExamens($resultats);

                $end = new \DateTime();
                $fiche->setEndTime($end);
                $fiche->setCreatedAt(new \DateTime());

                $start = $fiche->getStartTime();
                if ($start instanceof \DateTime && $end instanceof \DateTime) {
                    $diff = $end->getTimestamp() - $start->getTimestamp();
                    $minutes = (int) round($diff / 60);
                    $fiche->setDureeMinutes($minutes);
                }

                $rendez = $fiche->getRendezVous();
                if ($rendez) {
                    $rendez->setStatut('Confirmé');
                    $em->persist($rendez);
                }

                $em->persist($fiche);
                $em->flush();

                foreach ($validPrescriptions as $p) {
                    $prescription = new Prescription();
                    $prescription->setNomMedicament($p['nomMedicament']);
                    $prescription->setDose($p['dose']);
                    $prescription->setFrequence($p['frequence']);
                    $prescription->setDuree($p['duree']);
                    $prescription->setInstructions($p['instructions']);
                    $prescription->setCreatedAt(new \DateTimeImmutable());
                    $prescription->setFicheMedicale($fiche);
                    $fiche->addPrescription($prescription);
                    $em->persist($prescription);
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
        if ($fiche && $fiche->getRendezVous()) {
            $idStaff = $fiche->getRendezVous()->getStaff()->getId();
        } elseif ($rendezvousId) {
            $rendez = $rendezRepo->find((int)$rendezvousId);
            if ($rendez) {
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
        if ($this->isCsrfTokenValid('delete'.$fiche->getId(), $token)) {
            $rendez = $fiche->getRendezVous();
            $em->remove($fiche);
            $em->flush();
            $staffId = $rendez?->getStaff()?->getId();
            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
        }

        $staffId = $fiche->getRendezVous()?->getStaff()?->getId();
        return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
    }
}
