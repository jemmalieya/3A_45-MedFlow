<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegisterUserType;
use App\Repository\UserRepository;
use App\Service\UserService;  // Importation du service UserService
use App\Service\GeminiService;
use App\Service\TesseractOcrService;
use App\Service\RecaptchaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;


class RegistrationController extends AbstractController
{
    private $userService;

    private function normalizePhoneNumber(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, '+')) {
            return '+' . preg_replace('/\D+/', '', $raw);
        }
        if (str_starts_with($raw, '00')) {
            $digits = preg_replace('/\D+/', '', substr($raw, 2));
            return $digits !== '' ? ('+' . $digits) : '';
        }

        return preg_replace('/\D+/', '', $raw);
    }

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
        HttpClientInterface $httpClient,
        Security $security,
        RecaptchaService $recaptcha
    ): Response {
        $session = $request->getSession();
        $session->start();

        $google = $session->get('google_oauth');
        $googlePicture = null;
        $isGoogleSignup = ($google && !empty($google['email']));
        $isSocialSignup = $isGoogleSignup;

        $oauthData = $isGoogleSignup ? $google : null;
        $signupProvider = $isGoogleSignup ? 'google' : null;

        if ($isSocialSignup && is_array($oauthData)) {
            $user = $userRepo->findOneBy(['emailUser' => $oauthData['email']]) ?? new User();

            // Pré-remplir email/nom/prénom (sans casser ce que l’utilisateur a déjà)
            if (method_exists($user, 'setEmailUser') && !$user->getEmailUser()) {
                $user->setEmailUser($oauthData['email']);
            }

            $givenName = trim((string) ($oauthData['given_name'] ?? ''));
            $familyName = trim((string) ($oauthData['family_name'] ?? ''));
            $fullName = trim((string) ($oauthData['name'] ?? ''));

            if (!$user->getPrenom()) {
                if ($givenName !== '') {
                    $user->setPrenom($givenName);
                } elseif ($fullName !== '') {
                    $parts = preg_split('/\s+/', $fullName);
                    $prenom = $parts[0] ?? '';
                    if ($prenom !== '') {
                        $user->setPrenom($prenom);
                    }
                }
            }
            if (!$user->getNom()) {
                if ($familyName !== '') {
                    $user->setNom($familyName);
                } elseif ($fullName !== '') {
                    $parts = preg_split('/\s+/', $fullName);
                    $nom = $parts[count($parts) - 1] ?? '';
                    if ($nom !== '') {
                        $user->setNom($nom);
                    }
                }
            }

            // Si tu as googleId dans ton entity (optionnel)
            if ($signupProvider === 'google' && !empty($oauthData['googleId'])) {
                $user->setGoogleId($oauthData['googleId']);
            }

            $googlePicture = $oauthData['picture'] ?? null;

            // If we have a Google picture URL and nothing stored yet, keep URL as a fallback.
            if ($googlePicture && !$user->getProfilePicture()) {
                $user->setProfilePicture((string) $googlePicture);
            }

            // People API prefill (optional)
            if ($signupProvider === 'google') {
                $googlePhoneRaw = isset($oauthData['phone']) && is_string($oauthData['phone']) ? $oauthData['phone'] : '';
                $googlePhone = $this->normalizePhoneNumber((string) $googlePhoneRaw);
                if ($googlePhone !== '' && !$user->getTelephoneUser()) {
                    $user->setTelephoneUser($googlePhone);
                }

                $googleBirth = isset($oauthData['birthdate']) && is_string($oauthData['birthdate']) ? trim($oauthData['birthdate']) : '';
                if ($googleBirth !== '' && !$user->getDateNaissance()) {
                    try {
                        $dt = new \DateTimeImmutable($googleBirth);
                        $user->setDateNaissance($dt);
                    } catch (\Throwable $e) {
                        // Ignore; user will fill it manually.
                    }
                }
            }
        } else {
            $user = new User();
        }

        $isNew = ($user->getId() === null);

        $readonlyFields = [];
        $hideFields = [];
        if ($isSocialSignup) {
            if ($user->getEmailUser()) {
                $readonlyFields[] = 'emailUser';
            }
            if ($user->getNom()) {
                $readonlyFields[] = 'nom';
            }
            if ($user->getPrenom()) {
                $readonlyFields[] = 'prenom';
            }

            // Keep password field visible (optional) for Google signup
        }

        if ($isGoogleSignup && (trim((string) ($user->getTelephoneUser() ?? '')) === '')) {
            $this->addFlash('info', "Google n'a pas fourni de numéro de téléphone. Merci de le saisir.");
        }

        // Do not show profile picture upload during registration
        $hideFields[] = 'profilePictureFile';

        $form = $this->createForm(RegisterUserType::class, $user, [
            'readonly_fields' => $readonlyFields,
            'hide_fields' => $hideFields,
            'google_signup' => $isSocialSignup,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($recaptcha->isEnabled() && !$recaptcha->verifyRequest($request)) {
                $this->addFlash('error', 'Veuillez valider le reCAPTCHA.');
                return $this->redirectToRoute('app_register');
            }

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
            // Mot de passe: requis pour inscription classique, auto-généré pour Google
            $plainPassword = null;
            if ($form->has('plainPassword')) {
                $plainPassword = $form->get('plainPassword')->getData();
            }

            if ($plainPassword) {
                $user->setPassword($hasher->hashPassword($user, $plainPassword));
            } elseif ($isSocialSignup) {
                $generated = bin2hex(random_bytes(24));
                $user->setPassword($hasher->hashPassword($user, $generated));
            } else {
                $this->addFlash('error', 'Le mot de passe est requis.');
                return $this->redirectToRoute('app_register');
            }

            // Photo de profil: priorité à l'upload, sinon récupération depuis Google
            $uploadedProfilePicture = null;
            if ($form->has('profilePictureFile')) {
                $uploadedProfilePicture = $form->get('profilePictureFile')->getData();
            }
            if ($uploadedProfilePicture) {
                try {
                    $ext = $uploadedProfilePicture->guessExtension() ?: 'jpg';
                    $newFilename = uniqid('pp_', true) . '.' . $ext;
                    $dir = $this->getParameter('profile_pictures_directory');
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0777, true);
                    }
                    $uploadedProfilePicture->move($dir, $newFilename);
                    $user->setProfilePicture($newFilename);
                } catch (\Throwable $e) {
                    $logger->warning('Profile picture upload failed', ['error' => $e->getMessage()]);
                }
            } elseif ($googlePicture) {
                try {
                    $currentPic = (string) ($user->getProfilePicture() ?? '');
                    $shouldAttemptDownload = ($currentPic === '' || str_starts_with($currentPic, 'http://') || str_starts_with($currentPic, 'https://'));
                    if (!$shouldAttemptDownload) {
                        // Already a local filename
                        $shouldAttemptDownload = false;
                    }

                    if (!$shouldAttemptDownload) {
                        // Nothing to do
                    } else {
                    $resp = $httpClient->request('GET', (string) $googlePicture, [
                        'max_redirects' => 10,
                        'headers' => [
                            'Accept' => 'image/*',
                            'User-Agent' => 'Mozilla/5.0 (MedFlow; Symfony HttpClient)',
                        ],
                    ]);

                    if ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300) {
                        $headers = $resp->getHeaders(false);
                        $contentType = $headers['content-type'][0] ?? '';
                        if (is_string($contentType)) {
                            $contentType = trim(explode(';', $contentType, 2)[0]);
                        }
                        if (is_string($contentType) && str_starts_with($contentType, 'image/')) {
                            $ext = match ($contentType) {
                                'image/png' => 'png',
                                'image/webp' => 'webp',
                                'image/gif' => 'gif',
                                default => 'jpg',
                            };

                            $dir = $this->getParameter('profile_pictures_directory');
                            if (!is_dir($dir)) {
                                @mkdir($dir, 0777, true);
                            }
                            if (is_dir($dir)) {
                                $newFilename = uniqid('pp_', true) . '.' . $ext;
                                file_put_contents(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $newFilename, $resp->getContent(false));
                                $user->setProfilePicture($newFilename);
                            }
                        }
                    }
                    }
                } catch (\Throwable $e) {
                    $logger->warning('Google profile picture download failed', ['error' => $e->getMessage()]);
                }
            }

            // Définir rôle et token si nécessaire
            if ($isNew) {
                $user->setRoleSysteme('PATIENT');
                $user->setIsVerified(false);
            }

            // Social signup: trust provider identity and skip email verification
            if ($isSocialSignup) {
                $user->setIsVerified(true);
                $user->setVerificationToken(null);
                $user->setTokenExpiresAt(null);
            } else {
                // Verification token for classic signup (24h)
                $user->setTokenExpiresAt((new \DateTime())->modify('+24 hours'));
                if ($isNew || $user->IsVerified() === false) {
                    $token = bin2hex(random_bytes(32));
                    $user->setVerificationToken($token);
                    $user->setTokenExpiresAt((new \DateTime())->modify('+24 hours'));
                }
            }

            // Utiliser UserService pour créer l'utilisateur
            $this->userService->saveUser($user);

            if (!$isSocialSignup) {
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

            // Social: auto-login and go to home (or success handler)
            $session->remove('google_oauth');
            $loginResponse = $security->login($user, 'form_login', 'main');
            return $loginResponse ?? $this->redirectToRoute('app_home');
        }

        return $this->render('registration/index.html.twig', [
            'form' => $form->createView(),
            'isGoogleSignup' => $isGoogleSignup,
        ]);
    }

    #[Route('/register/staff', name: 'app_register_staff', methods: ['GET', 'POST'])]
    public function registerStaff(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        LoggerInterface $logger,
        UserRepository $userRepo,
        TesseractOcrService $ocr,
        GeminiService $gemini,
        RecaptchaService $recaptcha,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegisterUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($recaptcha->isEnabled() && !$recaptcha->verifyRequest($request)) {
                $this->addFlash('error', 'Veuillez valider le reCAPTCHA.');
                return $this->redirectToRoute('app_register_staff');
            }

            // Prevent duplicates
            if ($user->getEmailUser() && $userRepo->findOneBy(['emailUser' => $user->getEmailUser()])) {
                $this->addFlash('error', "Cet email est déjà utilisé.");
                return $this->redirectToRoute('app_register_staff');
            }
            if ($user->getCin() && $userRepo->findOneBy(['cin' => $user->getCin()])) {
                $this->addFlash('error', "Ce CIN est déjà utilisé.");
                return $this->redirectToRoute('app_register_staff');
            }

            // Password
            $plainPassword = $form->get('plainPassword')->getData();
            if (!$plainPassword) {
                $this->addFlash('error', 'Le mot de passe est requis.');
                return $this->redirectToRoute('app_register_staff');
            }
            $user->setPassword($hasher->hashPassword($user, $plainPassword));

            // Staff request fields (same names/logic as the modal)
            $type = strtoupper((string) $request->request->get('type_staff', ''));
            $message = trim((string) $request->request->get('message', ''));
            $docSpecialite = trim((string) $request->request->get('doc_specialite', ''));
            $docExperience = (int) $request->request->get('doc_experience', 0);
            $docEtablissement = trim((string) $request->request->get('doc_etablissement', ''));
            $docNumero = trim((string) $request->request->get('doc_numero', ''));

            if ($type === '') {
                // Infer type from speciality as fallback
                if ($docSpecialite !== '') {
                    $s = mb_strtolower($docSpecialite);
                    $type = str_contains($s, 'pharm') ? 'PHARMACIEN' : 'MEDECIN';
                }
            }
            if ($type === '') {
                $this->addFlash('error', 'Veuillez choisir une spécialité Staff.');
                return $this->redirectToRoute('app_register_staff');
            }
            if ($docNumero === '') {
                $this->addFlash('error', "Le numéro d'autorisation d'exercice est requis.");
                return $this->redirectToRoute('app_register_staff');
            }

            // Upload documents (same constraints as modal)
            $docs = [];
            $baseDir = $this->getParameter('kernel.project_dir') . '/var/staff_requests';
            if (!is_dir($baseDir)) {
                @mkdir($baseDir, 0775, true);
            }
            if (!is_dir($baseDir)) {
                $this->addFlash('error', 'Stockage indisponible. Réessayez plus tard.');
                return $this->redirectToRoute('app_register_staff');
            }

            $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
            $max = 5 * 1024 * 1024;
            $requiredFiles = [
                'id_doc' => 'Carte d\'identité',
                'diploma' => 'Diplôme médical',
                'attestation' => 'Attestation d\'ordre professionnel',
                'pro_photo' => 'Photo professionnelle',
            ];

            $ocrCombined = [];
            foreach ($requiredFiles as $field => $label) {
                /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $file */
                $file = $request->files->get($field);
                if (!$file) {
                    $this->addFlash('error', $label.' manquant.');
                    return $this->redirectToRoute('app_register_staff');
                }
                $size = $file->getSize();
                $mime = $file->getMimeType();
                $fieldAllowed = $field === 'pro_photo' ? ['image/jpeg', 'image/png'] : $allowed;
                if ($size > $max) {
                    $this->addFlash('error', $label.' trop volumineux (max 5MB).');
                    return $this->redirectToRoute('app_register_staff');
                }
                if (!in_array($mime, $fieldAllowed, true)) {
                    $this->addFlash('error', $label.' : type non autorisé.');
                    return $this->redirectToRoute('app_register_staff');
                }

                $safeName = uniqid($field . '_') . '.' . ($file->guessExtension() ?: 'bin');
                try {
                    $file->move($baseDir, $safeName);
                } catch (\Throwable) {
                    $this->addFlash('error', 'Erreur lors du stockage du document requis.');
                    return $this->redirectToRoute('app_register_staff');
                }

                $storedRel = 'staff_requests/' . $safeName;
                $fullPath = $this->getParameter('kernel.project_dir') . '/var/' . $storedRel;

                $ocrText = null;
                if ($mime && str_starts_with($mime, 'image/')) {
                    $res = $ocr->extractText($fullPath);
                    $ocrText = is_string($res['text'] ?? null) ? trim((string) $res['text']) : null;
                    if (is_string($ocrText) && $ocrText !== '') {
                        $ocrCombined[] = $label . "\n" . $ocrText;
                    }
                }

                $docs[] = [
                    'original' => $file->getClientOriginalName(),
                    'stored' => $storedRel,
                    'mime' => $mime,
                    'size' => $size,
                    'kind' => $field,
                    'ocrText' => $ocrText ? mb_substr($ocrText, 0, 4000) : null,
                ];
            }

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile[] $files */
            $files = $request->files->all('proofs');
            foreach ($files as $file) {
                if (!$file) { continue; }
                $size = $file->getSize();
                $mime = $file->getMimeType();
                if ($size > $max) {
                    $this->addFlash('error', 'Un document est trop volumineux (max 5MB).');
                    return $this->redirectToRoute('app_register_staff');
                }
                if (!in_array($mime, $allowed, true)) {
                    $this->addFlash('error', 'Un document a un type non autorisé (PDF/JPG/PNG).');
                    return $this->redirectToRoute('app_register_staff');
                }
                $safeName = uniqid('doc_') . '.' . ($file->guessExtension() ?: 'bin');
                try {
                    $file->move($baseDir, $safeName);
                } catch (\Throwable) {
                    $this->addFlash('error', "Erreur lors du stockage d'un document optionnel.");
                    return $this->redirectToRoute('app_register_staff');
                }
                $docs[] = [
                    'original' => $file->getClientOriginalName(),
                    'stored' => 'staff_requests/' . $safeName,
                    'mime' => $mime,
                    'size' => $size,
                    'kind' => 'optional',
                ];
            }

            $meta = [
                'specialite' => $docSpecialite,
                'experience' => $docExperience,
                'etablissement' => $docEtablissement,
                'numero' => $docNumero,
                'roleWanted' => 'STAFF',
                'typeStaffWanted' => $type,
            ];

            $aiSuggestion = null;
            if (count($ocrCombined) > 0) {
                $prompt = "Tu es un assistant pour un administrateur MedFlow. "
                    ."Objectif: aider à décider d'approuver/refuser une demande de création de compte Staff Medical. "
                    ."Réponds en français, très court, format:\n"
                    ."- Décision: APPROUVER/REFUSER\n- Motif: ...\n- Vérifications: ...\n";
                $prompt .= "\nInformations demandeur:\n".
                    "Spécialité: {$docSpecialite}\n".
                    "Type staff demandé: {$type}\n".
                    "Établissement: {$docEtablissement}\n".
                    "N° autorisation: {$docNumero}\n".
                    "Message: {$message}\n";
                $prompt .= "\nOCR (documents):\n" . implode("\n\n---\n\n", $ocrCombined);

                try {
                    $aiSuggestion = trim($gemini->generate($prompt));
                    if ($aiSuggestion === '') {
                        $aiSuggestion = null;
                    }
                } catch (\Throwable) {
                    $aiSuggestion = null;
                }
            }

            // Create as PATIENT but blocked; admin will promote to STAFF on approval
            $user->setRoleSysteme('PATIENT');
            $user->setTypeStaff(null);
            $user->setIsVerified(false);
            $user->setStatutCompte('EN_ATTENTE_ADMIN');

            $user->setStaffRequestStatus('PENDING');
            $user->setStaffRequestType($type);
            $user->setStaffRequestMessage($message ?: null);
            $user->setStaffRequestedAt(new \DateTime());
            $user->setStaffDocuments([
                'meta' => $meta,
                'files' => $docs,
                'ocrEnabled' => true,
                'aiSuggestion' => $aiSuggestion,
            ]);
            $user->setStaffReviewedAt(null);
            $user->setStaffReviewedBy(null);

            // Email verification token (same as patient)
            $user->setTokenExpiresAt((new \DateTime())->modify('+24 hours'));
            $token = bin2hex(random_bytes(32));
            $user->setVerificationToken($token);

            $this->userService->saveUser($user);

            try {
                $this->sendVerificationEmail($user);
            } catch (\Throwable $e) {
                $logger->error('Brevo email failed (staff registration)', [
                    'user' => $user->getEmailUser(),
                    'error' => $e->getMessage(),
                ]);
            }

            $this->addFlash('success', "Demande envoyée ✅ Vérifiez votre email, puis attendez la validation admin.");
            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/staff.html.twig', [
            'form' => $form->createView(),
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