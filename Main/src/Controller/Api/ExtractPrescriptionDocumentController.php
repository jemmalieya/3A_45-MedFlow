<?php
namespace App\Controller\Api;

use App\Service\PrescriptionDocumentIntelligenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class ExtractPrescriptionDocumentController extends AbstractController
{
    #[Route('/api/extract-prescription-document', name: 'api_extract_prescription_document', methods: ['POST'])]
    public function extract(Request $request, PrescriptionDocumentIntelligenceService $service): Response
    {
        $file = $request->files->get('file');
        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded.'], 400);
        }
        try {
            $result = $service->extractText($file);
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
