<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegisterUserType;
use App\Repository\UserRepository;
use App\Service\UserService;  // Importation du service UserService
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use App\Security\OAuthAuthenticator;


class RegistrationController extends AbstractController
{
    private $userService;

    // Injection du service UserService dans le constructeur
    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

#[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        LoggerInterface $logger,
        UserRepository $userRepo,
        UserAuthenticatorInterface $userAuthenticator,
        OAuthAuthenticator $oauthAuthenticator
    ): Response {
        $session = $request->getSession();
        $session->start();

        $google = $session->get('google_oauth');
        $isGoogleFlow = (is_array($google) && !empty($google['email']));
        if ($isGoogleFlow) {
            $user = $userRepo->findOneBy(['emailUser' => $google['email']]) ?? new User();

            // Pré-remplir email/nom/prénom (sans casser ce que l’utilisateur a déjà)
            if (method_exists($user, 'setEmailUser') && !$user->getEmailUser()) {
                $user->setEmailUser($google['email']);
            }

            // Prefer structured names from Google when available
            $givenName = trim((string) ($google['givenName'] ?? ''));
            $familyName = trim((string) ($google['familyName'] ?? ''));
            if ($givenName && !$user->getPrenom()) {
                $user->setPrenom($givenName);
            }
            if ($familyName && !$user->getNom()) {
                $user->setNom($familyName);
            }

            // Fallback: split display name
            $name = trim((string) ($google['name'] ?? ''));
            if ($name && (!$user->getPrenom() || !$user->getNom())) {
                $parts = preg_split('/\s+/', $name);
                $prenom = $parts[0] ?? '';
                $nom = $parts[count($parts) - 1] ?? '';

                if (!$user->getPrenom() && $prenom) {
                    $user->setPrenom($prenom);
                }
                if (!$user->getNom() && $nom) {
                    $user->setNom($nom);
                }
            }

            // Si tu as googleId dans ton entity (optionnel)
            if (method_exists($user, 'setGoogleId') && !empty($google['googleId'])) {
                $user->setGoogleId($google['googleId']);
            }
        } else {
            $user = new User();
        }

        $isNew = ($user->getId() === null);

        $form = $this->createForm(RegisterUserType::class, $user, [
            'is_google' => $isGoogleFlow,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cin = $form->get('cin')->getData();
        $telephoneUser = $form->get('telephoneUser')->getData();
        $adresseUser = $form->get('adresseUser')->getData();
        $dateNaissance = $form->get('dateNaissance')->getData();
        $user->setDateNaissance($dateNaissance);


            if (!$telephoneUser) {
                $this->addFlash('error', 'Le téléphone est requis.');
                return $this->redirectToRoute('app_register');
            }


            if (!$cin || !$telephoneUser || !$dateNaissance) {
            $this->addFlash('error', 'Le CIN, le téléphone et la date de naissance sont requis.');
            return $this->redirectToRoute('app_register');
        }
            // Password:
            // - Normal registration: required
            // - Google registration: not required; generate one if missing (DB column is not-null)
            $plainPassword = null;
            if ($form->has('plainPassword')) {
                $plainPassword = $form->get('plainPassword')->getData();
            }
            if ($plainPassword) {
                $user->setPassword($hasher->hashPassword($user, $plainPassword));
            } elseif (!$user->getPassword()) {
                $generated = bin2hex(random_bytes(24));
                $user->setPassword($hasher->hashPassword($user, $generated));
            }

            // Définir rôle et vérification
            if ($isNew) {
                $user->setRoleSysteme('PATIENT');
            }

            if ($isGoogleFlow) {
                // Google OAuth prouve l'email => pas de vérification email
                $user->setIsVerified(true);
                $user->setVerificationToken(null);
                $user->setTokenExpiresAt(null);
            } else {
                $user->setIsVerified(false);
                if ($isNew || $user->IsVerified() === false) {
                    $token = bin2hex(random_bytes(32));
                    $user->setVerificationToken($token);
                    $user->setTokenExpiresAt((new \DateTime())->modify('+24 hours'));
                }
            }

            // Utiliser UserService pour créer l'utilisateur
            $this->userService->saveUser($user);

            if (!$isGoogleFlow) {
                // Envoi d'email de vérification via Brevo
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
                    $this->addFlash('warning', "Compte créé, mais l'email de vérification n'a pas pu être envoyé.");
                }

                $session->remove('google_oauth');
                return $this->redirectToRoute('app_login');
            }

            // Google flow: clear session and login immediately
            $session->remove('google_oauth');
            $resp = $userAuthenticator->authenticateUser($user, $oauthAuthenticator, $request);
            return $resp ?? $this->redirectToRoute('app_home');
        }

        return $this->render('registration/index.html.twig', [
            'form' => $form->createView(),
            'is_google' => $isGoogleFlow,
        ]);
    }


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
                'name' => trim(($user->getPrenom() ?? '') . ' ' . ($user->getNom() ?? ''))],
            ],
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