<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class VerifyEmailController extends AbstractController
{
    #[Route('/verify-email', name: 'app_verify_email_page', methods: ['GET'])]
    public function verifyEmail(
        UserRepository $repo,
        EntityManagerInterface $em
    ): Response {
        $token = $this->getRequestToken();

        if (!$token) {
            return $this->render('registration/verify_email.html.twig', [
                'success' => false,
                'message' => 'Token manquant.',
            ]);
        }

        $user = $repo->findOneBy(['verificationToken' => $token]);

        if (!$user) {
            return $this->render('registration/verify_email.html.twig', [
                'success' => false,
                'message' => 'Lien invalide.',
            ]);
        }

        if ($user->getTokenExpiresAt() < new \DateTime()) {
            return $this->render('registration/verify_email.html.twig', [
                'success' => false,
                'message' => 'Lien expiré.',
            ]);
        }

        $user->setIsVerified(true);
        $user->setVerificationToken(null);
        $user->updateTokenExpiresAt(null);
        $em->flush();

        $this->addFlash('success', 'Email vérifié ✅');
        return $this->redirectToRoute('app_login');
    }

   #[Route('/resend-verification', name: 'app_resend_verification', methods: ['POST'])]
    public function resendVerification(
    Request $request,
    UserRepository $repo,
    EntityManagerInterface $em,
    LoggerInterface $logger
): Response {
    $email = $request->request->get('email');

    if (!$email) {
        $this->addFlash('danger', 'Email manquant.');
        return $this->redirectToRoute('app_login');
    }

    $user = $repo->findOneBy(['emailUser' => $email]);

    if (!$user) {
        $this->addFlash('danger', 'Utilisateur introuvable.');
        return $this->redirectToRoute('app_login');
    }

    if ($user->isVerified()) {
        $this->addFlash('info', 'Compte déjà vérifié.');
        return $this->redirectToRoute('app_login');
    }

    // 🔁 Nouveau token
    $user->setVerificationToken(bin2hex(random_bytes(32)));
    $user->updateTokenExpiresAt((new \DateTime())->modify('+24 hours'));
    $em->flush();

    $this->sendVerificationEmail($user, $logger);

    $this->addFlash('success', 'Email de vérification renvoyé ✅');
    return $this->redirectToRoute('app_login');
}


    // ===============================
    // 🔒 MÉTHODE PRIVÉE (IMPORTANT)
    // ===============================
    private function sendVerificationEmail(User $user, LoggerInterface $logger): void
    {
        $apiKey = $_ENV['BREVO_API_KEY'];
        $sender = $_ENV['BREVO_SENDER_EMAIL'];
        $appUrl = $_ENV['APP_URL'];

        $link = $appUrl . '/verify-email?token=' . $user->getVerificationToken();

        $payload = [
            'sender' => [
                'email' => $sender,
                'name' => 'MedFlow',
            ],
            'to' => [[
                'email' => $user->getEmailUser(),
            ]],
            'subject' => 'Vérification de votre compte',
            'htmlContent' => "<p><a href='$link'>Vérifier mon email</a></p>",
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $logger->info('Resend verification email', [
            'email' => $user->getEmailUser(),
            'response' => $response,
        ]);
    }

    private function getRequestToken(): ?string
    {
        return $_GET['token'] ?? null;
    }
}