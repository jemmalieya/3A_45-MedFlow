<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegisterUserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        LoggerInterface $logger,
        UserRepository $userRepo
    ): Response {
        $session = $request->getSession();
        $session->start();

        $google = $session->get('google_oauth');

        // ✅ 1) Si Google a déjà donné un email, on utilise soit un user existant, soit new user
        if ($google && !empty($google['email'])) {
            $user = $userRepo->findOneBy(['emailUser' => $google['email']]) ?? new User();

            // Pré-remplir email/nom/prénom (sans casser ce que l’utilisateur a déjà)
            if (method_exists($user, 'setEmailUser') && !$user->getEmailUser()) {
                $user->setEmailUser($google['email']);
            }

            $name = trim((string) ($google['name'] ?? ''));
            if ($name && (!$user->getPrenom() || !$user->getNom())) {
                $parts = preg_split('/\s+/', $name);
                $prenom = $parts[0] ?? '';
                $nom = $parts[count($parts) - 1] ?? '';

                if (!$user->getPrenom() && $prenom) $user->setPrenom($prenom);
                if (!$user->getNom() && $nom) $user->setNom($nom);
            }

            // Si tu as googleId dans ton entity (optionnel)
            if (method_exists($user, 'setGoogleId') && !empty($google['googleId'])) {
                $user->setGoogleId($google['googleId']);
            }
        } else {
            $user = new User();
        }

        $isNew = ($user->getId() === null);

        $form = $this->createForm(RegisterUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // ✅ 2) dateNaissance est mapped=false dans RegisterUserType => on la set ici
            $dateNaissance = $form->get('dateNaissance')->getData();
            $user->setDateNaissance($dateNaissance);

            // ✅ 3) Password
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword($hasher->hashPassword($user, $plainPassword));
            }

            // ✅ 4) Logique métier (garde ta logique actuelle)
            if ($isNew) {
                $user->setRoleSysteme('PATIENT');
                $user->setIsVerified(false);
            } else {
                // si user existe déjà, on ne reset pas verified si déjà true
                // mais si tu veux FORCER re-verification même pour existant, mets false ici.
                if ($user->IsVerified() === null) {
                    $user->setIsVerified(false);
                }
            }

            // ✅ 5) Token + expiration (si nouveau ou pas encore vérifié)
            if ($isNew || $user->IsVerified() === false) {
                $token = bin2hex(random_bytes(32));
                $user->setVerificationToken($token);
                $user->setTokenExpiresAt((new \DateTime())->modify('+24 hours'));
            }

            // ✅ 6) Save
            $em->persist($user);
            $em->flush();

            // ✅ 7) Envoi email Brevo (si pas vérifié)
            if ($user->IsVerified() === false) {
                try {
                    $brevoResponse = $this->sendVerificationEmail($user);
                    $logger->info('Brevo email sent', [
                        'user' => $user->getEmailUser(),
                        'response' => $brevoResponse,
                    ]);

                    $this->addFlash('success', 'Compte créé ! Vérifiez votre email pour activer votre compte.');
                } catch (\Throwable $e) {
                    $logger->error('Brevo email failed', [
                        'user' => $user->getEmailUser(),
                        'error' => $e->getMessage(),
                    ]);

                    $this->addFlash('warning', "Compte créé, mais l'email de vérification n'a pas pu être envoyé. Réessayez plus tard.");
                }
            } else {
                $this->addFlash('success', 'Profil mis à jour.');
            }

            // ✅ 8) Clear google session
            $session->remove('google_oauth');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/index.html.twig', [
            'title' => 'Inscription',
            'form' => $form->createView(),
        ]);
    }

    /**
     * @return array<string, mixed> Brevo JSON response decoded
     */
    private function sendVerificationEmail(User $user): array
    {
        $apiKey = $_ENV['BREVO_API_KEY'] ?? null;
        $appUrl = $_ENV['APP_URL'] ?? 'http://127.0.0.1:8000';

        $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? null;
        $senderName  = $_ENV['BREVO_SENDER_NAME'] ?? 'MedFlow';

        if (!$apiKey || !$senderEmail) {
            throw new \Exception("Config Brevo manquante: BREVO_API_KEY ou BREVO_SENDER_EMAIL.");
        }

        $verifyLink = rtrim($appUrl, '/') . '/verify-email?token=' . urlencode((string) $user->getVerificationToken());

        $payload = [
            'sender' => [
                'name' => $senderName,
                'email' => $senderEmail,
            ],
            'to' => [[
                'email' => $user->getEmailUser(),
                'name' => trim(($user->getPrenom() ?? '') . ' ' . ($user->getNom() ?? '')),
            ]],
            'subject' => 'Activez votre compte MedFlow',
            'htmlContent' => "
                <div style='font-family:Arial,sans-serif'>
                    <h2>Bienvenue sur MedFlow 👋</h2>
                    <p>Merci de confirmer votre adresse e-mail pour activer votre compte.</p>
                    <p>
                        <a href='{$verifyLink}' style='display:inline-block;padding:12px 18px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:8px'>
                            Vérifier mon e-mail
                        </a>
                    </p>
                    <p style='color:#666;font-size:12px'>Lien valable 24h.</p>
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

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['raw' => $response, 'httpCode' => $httpCode];
    }
}
