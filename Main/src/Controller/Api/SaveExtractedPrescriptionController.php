<?php
namespace App\Controller\Api;

use App\Entity\Prescription;
use App\Entity\FicheMedicale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class SaveExtractedPrescriptionController extends AbstractController
{
    #[Route('/api/save-extracted-prescription', name: 'api_save_extracted_prescription', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['fiche_id'], $data['nomMedicament'], $data['dose'], $data['frequence'], $data['duree'])) {
            return new JsonResponse(['error' => 'Missing fields.'], 400);
        }
        $prescription = new Prescription();
        $prescription->setNomMedicament($data['nomMedicament']);
        $prescription->setDose($data['dose']);
        $prescription->setFrequence($data['frequence']);
        $prescription->setDuree((int)$data['duree']);
        $prescription->setInstructions($data['instructions'] ?? null);
        if (!empty($data['createdAt'])) {
            $prescription->setCreatedAt(\DateTimeImmutable::createFromFormat('d/m/Y H:i', $data['createdAt']) ?: new \DateTimeImmutable($data['createdAt']));
        } else {
            $prescription->setCreatedAt(new \DateTimeImmutable());
        }
        // Link to FicheMedicale
        $ficheRepo = $em->getRepository(FicheMedicale::class);
        $fiche = $ficheRepo->find($data['fiche_id']);
        if ($fiche) {
            $prescription->setFicheMedicale($fiche);
        }
        $em->persist($prescription);
        $em->flush();
        return new JsonResponse(['success' => true, 'id' => $prescription->getId()]);
    }
}
