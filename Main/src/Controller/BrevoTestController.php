<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BrevoTestController extends AbstractController
{
    #[Route('/test-brevo', name: 'test_brevo')]
    public function testBrevo(): Response
    {
        $apiKey = $_ENV['BREVO_API_KEY'] ?? null;
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? null;
        $senderName  = $_ENV['BREVO_SENDER_NAME'] ?? 'MedFlow';

        if (!$apiKey || !$senderEmail) {
            return new Response("CONFIG MANQUANTE: BREVO_API_KEY ou BREVO_SENDER_EMAIL", 500);
        }

        $payload = [
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to' => [[ 'email' => 'TON_EMAIL_ICI@gmail.com', 'name' => 'Test' ]],
            'subject' => 'Test Brevo Symfony',
            'htmlContent' => '<h3>Test OK ✅</h3><p>Brevo est bien appelé depuis Symfony.</p>',
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return new Response("CURL ERROR: $err", 500);
        }

        curl_close($ch);

        return new Response("HTTP=$httpCode\n\n$response", $httpCode);
    }
}
