<?php

namespace App\Controller;

use App\Entity\RendezVous;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DialogflowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class DialogflowController extends AbstractController
{
    #[Route('/dialogflow/webhook', name: 'dialogflow_webhook', methods: ['POST'])]
    public function webhook(Request $request, EntityManagerInterface $em, DialogflowService $dialogflowService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        // TEMP: Log the full Dialogflow payload for debugging
        file_put_contents(__DIR__ . '/../../dialogflow_debug.json', json_encode($data, JSON_PRETTY_PRINT));

        // Get the user's message from the payload
        $message = $data['message'] ?? null;
        if (!$message) {
            return new JsonResponse([
                'fulfillmentText' => "Aucun message reçu."
            ]);
        }

        // Call DialogflowService to get intent and parameters
        $dialogflowResponse = $dialogflowService->detectIntent($message);
        // TEMP: Log the full Dialogflow API response for debugging
        file_put_contents(__DIR__ . '/../../dialogflow_api_debug.json', json_encode($dialogflowResponse, JSON_PRETTY_PRINT));
        // Use the correct path for parameters
        $params = $dialogflowResponse['queryResult']['parameters'] ?? [];
        $intent = $dialogflowResponse['queryResult']['intent']['displayName'] ?? null;

        // Get patient from session
        $session = $request->getSession();
        $patientId = $session->get('patient_id');
        $patient = null;
        if ($patientId) {
            $patient = $em->getRepository(User::class)->find($patientId);
        }


        // Handle greeting intent: introduce the assistant
        if ($intent === 'Default Welcome Intent' || $intent === 'Greeting' || $intent === 'SayHello') {
            return new JsonResponse([
                'fulfillmentText' => "Bonjour ! Je suis votre assistante IA. Je peux vous aider à réserver un rendez-vous, à consulter vos rendez-vous, à obtenir des rappels, ou à naviguer sur le site pour faciliter votre expérience. N'hésitez pas à me demander ce dont vous avez besoin !"
            ]);
        }

        // Handle new intent: show rendezvous list
        if ($intent === 'ShowRendezVousList') {
            if (!$patient) {
                return new JsonResponse([
                    'fulfillmentText' => "Impossible de trouver le patient pour afficher la liste des rendez-vous (identifiant manquant ou invalide)."
                ]);
            }
            $rdvs = $em->getRepository(RendezVous::class)->findByPatient($patientId);
            if (!$rdvs) {
                return new JsonResponse([
                    'fulfillmentText' => "Vous n'avez aucun rendez-vous enregistré."
                ]);
            }
            $lines = [];
            foreach ($rdvs as $rdv) {
                $date = $rdv->getDatetime() ? $rdv->getDatetime()->format('d/m/Y H:i') : '';
                $doctor = $rdv->getStaff() ? ($rdv->getStaff()->getNom() . ' ' . $rdv->getStaff()->getPrenom()) : '';
                $mode = $rdv->getMode();
                $lines[] = "- $date avec Dr $doctor ($mode)";
            }
            $msg = "Voici vos rendez-vous :\n" . implode("\n", $lines);
            return new JsonResponse([
                'fulfillmentText' => $msg
            ]);
        }

        // Handle new intent: remind next upcoming appointment
        if ($intent === 'RemindUpcomingRendezVous') {
            if (!$patient) {
                return new JsonResponse([
                    'fulfillmentText' => "Impossible de trouver le patient pour le rappel de rendez-vous (identifiant manquant ou invalide)."
                ]);
            }
            $nextRdv = $em->getRepository(RendezVous::class)->findNextUpcomingByPatient($patientId);
            if (!$nextRdv) {
                return new JsonResponse([
                    'fulfillmentText' => "Vous n'avez aucun rendez-vous à venir."
                ]);
            }
            $date = $nextRdv->getDatetime() ? $nextRdv->getDatetime()->format('d/m/Y H:i') : '';
            $doctor = $nextRdv->getStaff() ? ($nextRdv->getStaff()->getNom() . ' ' . $nextRdv->getStaff()->getPrenom()) : '';
            $mode = $nextRdv->getMode();
            $msg = "Votre prochain rendez-vous est le $date avec Dr $doctor ($mode). N'oubliez pas d'y assister !";
            return new JsonResponse([
                'fulfillmentText' => $msg
            ]);
        }

        // Robust extraction for 'mode'
        $mode = $params['mode'] ?? null;

        // Robust extraction for 'person'
        $person = null;
        if (isset($params['person'])) {
            if (is_array($params['person']) && isset($params['person']['name'])) {
                $person = $params['person']['name'];
            } elseif (is_string($params['person'])) {
                $person = $params['person'];
            }
        }

        // Robust extraction for 'date-time'
        $dateTime = null;
        if (isset($params['date-time'])) {
            if (is_array($params['date-time'])) {
                if (isset($params['date-time']['date_time'])) {
                    $dateTime = $params['date-time']['date_time'];
                } elseif (isset($params['date-time']['startDateTime'])) {
                    $dateTime = $params['date-time']['startDateTime'];
                } elseif (isset($params['date-time'][0]['date_time'])) {
                    $dateTime = $params['date-time'][0]['date_time'];
                } elseif (isset($params['date-time'][0])) {
                    $dateTime = $params['date-time'][0];
                }
            } elseif (is_string($params['date-time'])) {
                $dateTime = $params['date-time'];
            }
        }

        if (!$mode || !$person || !$dateTime) {
            return new JsonResponse([
                'fulfillmentText' => "Je n'ai pas compris tous les détails pour réserver le rendez-vous. Veuillez réessayer. (Debug: mode=" . json_encode($mode) . ", person=" . json_encode($person) . ", dateTime=" . json_encode($dateTime) . ")"
            ]);
        }

        // Find doctor by name (case-insensitive, partial match)
        $doctor = $em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('LOWER(u.nom) LIKE LOWER(:name) OR LOWER(u.prenom) LIKE LOWER(:name)')
            ->setParameter('name', '%' . strtolower($person) . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$doctor) {
            return new JsonResponse([
                'fulfillmentText' => "Désolé, je n'ai pas trouvé le médecin $person."
            ]);
        }


        // Get patient from session
        $session = $request->getSession();
        $patientId = $session->get('patient_id');
        $patient = null;
        if ($patientId) {
            $patient = $em->getRepository(User::class)->find($patientId);
        }
        if (!$patient) {
            return new JsonResponse([
                'fulfillmentText' => "Impossible de trouver le patient pour la réservation (identifiant manquant ou invalide)."
            ]);
        }

        // Create and save the rendezvous
        $rdv = new RendezVous();
        $rdv->setStaff($doctor);
        $rdv->setPatient($patient);
        $rdv->setMode($mode);
        $rdv->setDatetime(new \DateTime($dateTime));
        $rdv->setCreatedAt(new \DateTime());
        $rdv->setMotif('Réservé via IA');

        $em->persist($rdv);
        $em->flush();

        return new JsonResponse([
            'fulfillmentText' => "Votre rendez-vous avec Dr $person est réservé pour le $dateTime en mode $mode."
        ]);
    }
}
