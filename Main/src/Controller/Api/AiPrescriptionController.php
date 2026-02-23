<?php

namespace App\Controller\Api;

use App\Service\AiPrescriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class AiPrescriptionController extends AbstractController
{
    #[Route('/api/suggest-prescription', name: 'api_suggest_prescription', methods: ['GET', 'POST'])]
    public function suggestPrescription(Request $request, AiPrescriptionService $aiPrescriptionService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $text = $data['text'] ?? '';

        if (empty($text)) {
            return $this->json(['suggestion' => '']);
        }

        $suggestion = $aiPrescriptionService->suggestPrescription($text);

        if ($suggestion === null) {
            return $this->json(['error' => 'AI service unavailable. Please try again later.'], 503);
        }

        return $this->json(['suggestion' => $suggestion]);
    }
}
