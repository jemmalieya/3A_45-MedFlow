<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PasswordSuggestionApiController extends AbstractController
{
    #[Route('/api/password/suggest', name: 'api_password_suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $length = (int) $request->query->get('length', 20);
        if ($length < 12) {
            $length = 12;
        }
        if ($length > 64) {
            $length = 64;
        }

        $password = $this->generatePassword($length);

        $res = $this->json(['password' => $password]);
        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }

    private function generatePassword(int $length): string
    {
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '!@#$%*-_?';

        $all = $lower.$upper.$digits.$symbols;

        $chars = [];
        $chars[] = $lower[random_int(0, strlen($lower) - 1)];
        $chars[] = $upper[random_int(0, strlen($upper) - 1)];
        $chars[] = $digits[random_int(0, strlen($digits) - 1)];
        $chars[] = $symbols[random_int(0, strlen($symbols) - 1)];

        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
