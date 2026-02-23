<?php
namespace App\Controller\Api;

use App\Service\DocumentIntelligenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class DocumentIntelligenceController extends AbstractController
{
    #[Route('/api/extract-medical-document', name: 'api_extract_medical_document', methods: ['POST'])]
    public function extract(Request $request, DocumentIntelligenceService $service): Response
    {
        $file = $request->files->get('file');
        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded.'], 400);
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            return new JsonResponse(['error' => 'File too large.'], 400);
        }
        try {
            $result = $service->extractText($file);
            return new JsonResponse($result);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
