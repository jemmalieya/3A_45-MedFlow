<?php
namespace App\Controller\Api;

use App\Entity\FicheMedicale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class SaveExtractedFicheController extends AbstractController
{
    #[Route('/api/save-extracted-fiche', name: 'api_save_extracted_fiche', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['diagnostic'], $data['observations'], $data['resultatsExamens'], $data['rendez_vous_id'])) {
            return new JsonResponse(['error' => 'Missing fields.'], 400);
        }
        $fiche = new FicheMedicale();
        $fiche->setDiagnostic($data['diagnostic']);
        $fiche->setObservations($data['observations']);
        $fiche->setResultatsExamens($data['resultatsExamens']);
        if (!empty($data['startTime'])) {
            $dt = \DateTimeImmutable::createFromFormat('d/m/Y H:i', $data['startTime']);
            if (!$dt) {
                $dt = new \DateTimeImmutable($data['startTime']);
            }
            $fiche->setStartTime($dt);
        }
        if (!empty($data['endTime'])) {
            $dt = \DateTimeImmutable::createFromFormat('d/m/Y H:i', $data['endTime']);
            if (!$dt) {
                $dt = new \DateTimeImmutable($data['endTime']);
            }
            $fiche->setEndTime($dt);
        }
        if (!empty($data['dureeMinutes'])) {
            $fiche->setDureeMinutes((int)$data['dureeMinutes']);
        }
        if (!empty($data['createdAt'])) {
            $dt = \DateTimeImmutable::createFromFormat('d/m/Y H:i', $data['createdAt']);
            if (!$dt) {
                $dt = new \DateTimeImmutable($data['createdAt']);
            }
            $fiche->setCreatedAt($dt);
        } else {
            $fiche->setCreatedAt(new \DateTimeImmutable());
        }
        // Link to RendezVous entity if possible
        if (!empty($data['rendez_vous_id'])) {
            $rendezVousRepo = $em->getRepository('App\\Entity\\RendezVous');
            $rendezVous = $rendezVousRepo->find($data['rendez_vous_id']);
            if ($rendezVous) {
                $fiche->setRendezVous($rendezVous);
            }
        }
        $em->persist($fiche);
        $em->flush();
        return new JsonResponse(['success' => true, 'id' => $fiche->getId()]);
    }
}
