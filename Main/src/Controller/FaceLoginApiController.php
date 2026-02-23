<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\FaceLoginService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class FaceLoginApiController extends AbstractController
{
    #[Route('/api/face/status', name: 'api_face_status', methods: ['GET'])]
    public function status(FaceLoginService $face): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $lockedUntil = $user->getFaceLockedUntil();
        $res = $this->json([
            'configured' => $face->isConfigured(),
            'enabled' => $user->isFaceLoginEnabled(),
            'enrolled' => $user->getFaceReferenceEmbedding() !== null,
            'failedAttempts' => $user->getFaceFailedAttempts(),
            'locked' => $user->isFaceLocked(),
            'lockedUntil' => $lockedUntil ? $lockedUntil->format(DATE_ATOM) : null,
        ]);

        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }

    #[Route('/api/face/enroll', name: 'api_face_enroll', methods: ['POST'])]
    public function enroll(
        Request $request,
        FaceLoginService $face,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Requête invalide.'], 400);
        }

        $embedding = $payload['embedding'] ?? null;
        if (!is_array($embedding)) {
            return $this->json(['error' => 'Embedding manquant.'], 400);
        }

        try {
            $face->enrollFromEmbedding($user, $embedding);
            $em->persist($user);
            $em->flush();

            $res = $this->json([
                'enabled' => $user->isFaceLoginEnabled(),
                'enrolled' => $user->getFaceReferenceEmbedding() !== null,
            ]);
            $res->headers->set('Cache-Control', 'no-store');
            return $res;
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage() ?: 'Erreur.'], 400);
        }
    }

    #[Route('/api/face/disable', name: 'api_face_disable', methods: ['POST'])]
    public function disable(
        FaceLoginService $face,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $face->disable($user);
            $em->persist($user);
            $em->flush();

            $res = $this->json([
                'enabled' => $user->isFaceLoginEnabled(),
                'enrolled' => $user->getFaceReferenceEmbedding() !== null,
            ]);
            $res->headers->set('Cache-Control', 'no-store');
            return $res;
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur lors de la désactivation.'], 500);
        }
    }
}
