<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DebugEnvController extends AbstractController
{
    #[Route('/debug-env', name: 'debug_env')]
    public function index(): Response
    {
        $apiKey = $_ENV['BREVO_API_KEY'] ?? '(null)';
        $sender = $_ENV['BREVO_SENDER_EMAIL'] ?? '(null)';
        $name   = $_ENV['BREVO_SENDER_NAME'] ?? '(null)';
        $url    = $_ENV['APP_URL'] ?? '(null)';

        return new Response(
            "<pre>BREVO_API_KEY: ".substr($apiKey,0,12)."...\nBREVO_SENDER_EMAIL: $sender\nBREVO_SENDER_NAME: $name\nAPP_URL: $url</pre>"
        );
    }
}
