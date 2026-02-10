<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ForgotPasswordType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        
        return $this->render('security/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error' => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Symfony intercepte automatiquement
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    // ===== FORGOT PASSWORD =====
    #[Route('/forgot-password', name: 'forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): Response {
        $form = $this->createForm(ForgotPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $user = $userRepo->findOneBy(['emailUser' => $email]);

            // ✅ Don't reveal if email exists (security)
            if ($user) {
                // Generate random token
                $resetToken = bin2hex(random_bytes(32));
                $resetTokenExpiresAt = new \DateTime('+24 hours');

                // Save token to DB
                $user->setResetToken($resetToken);
                $user->setResetTokenExpiresAt($resetTokenExpiresAt);
                $em->flush();

                // Send reset email via Brevo API (direct, like verification)
                try {
                    $this->sendPasswordResetEmail($user, $resetToken, $logger);
                    $logger->info('Password reset email queued for: ' . $user->getEmailUser());
                } catch (\Throwable $e) {
                    $logger->error('Failed to send password reset email: ' . $e->getMessage());
                }
            }

            // ✅ Show same message regardless (don't leak user existence)
            $this->addFlash('success', 'Si cette adresse email existe, un lien de réinitialisation a été envoyé.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    // ===== RESET PASSWORD =====
    #[Route('/reset-password/{token}', name: 'reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(
        string $token,
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        // ✅ Validate token
        $user = $userRepo->findOneBy(['resetToken' => $token]);

        if (!$user) {
            $this->addFlash('danger', 'Token invalide.');
            return $this->redirectToRoute('forgot_password');
        }

        // ✅ Check expiration
        if ($user->getResetTokenExpiresAt() && $user->getResetTokenExpiresAt() < new \DateTime()) {
            $this->addFlash('danger', 'Le lien a expiré. Veuillez demander une nouvelle réinitialisation.');
            return $this->redirectToRoute('forgot_password');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();

            // ✅ Hash password
            $hashedPassword = $hasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);

            // ✅ Clear reset token
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);

            $em->flush();

            $this->addFlash('success', 'Votre mot de passe a été réinitialisé avec succès! ✅');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'form' => $form->createView(),
            'token' => $token,
        ]);
    }

    // ===== SEND PASSWORD RESET EMAIL VIA BREVO API =====
    private function sendPasswordResetEmail(User $user, string $resetToken, LoggerInterface $logger): void
    {
        $apiKey = $_ENV['BREVO_API_KEY'] ?? null;
        $appUrl = $_ENV['APP_URL'] ?? 'http://127.0.0.1:8000';
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? null;
        $senderName = $_ENV['BREVO_SENDER_NAME'] ?? 'MedFlow';

        if (!$apiKey || !$senderEmail) {
            throw new \Exception("Config Brevo manquante: BREVO_API_KEY ou BREVO_SENDER_EMAIL.");
        }

        $resetLink = rtrim($appUrl, '/') . '/reset-password/' . urlencode($resetToken);

        $payload = [
            'sender' => [
                'name' => $senderName,
                'email' => $senderEmail,
            ],
            'to' => [[
                'email' => $user->getEmailUser(),
                'name' => trim(($user->getPrenom() ?? '') . ' ' . ($user->getNom() ?? '')),
            ]],
            'subject' => 'Réinitialiser votre mot de passe MedFlow',
            'htmlContent' => "
                <div style='font-family:Arial,sans-serif'>
                    <h2>Réinitialiser votre mot de passe</h2>
                    <p>Cliquez sur le lien ci-dessous pour réinitialiser votre mot de passe:</p>
                    <p>
                        <a href='{$resetLink}' style='display:inline-block;padding:12px 18px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:8px'>
                            Réinitialiser le mot de passe
                        </a>
                    </p>
                    <p style='color:#666;font-size:12px'>Le lien expire dans 24 heures.</p>
                    <p style='color:#666;font-size:12px'>Si vous n'avez pas demandé cette réinitialisation, ignorez cet email.</p>
                </div>
            ",
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
            throw new \Exception('Erreur CURL: ' . $err);
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \Exception("Brevo error ($httpCode): " . $response);
        }

        $logger->info('Password reset email sent to: ' . $user->getEmailUser());
    }
}
