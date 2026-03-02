<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegisterUserType;
use App\Repository\UserRepository;
use App\Service\GeminiService;
use App\Service\RecaptchaService;
use App\Service\TesseractOcrService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RegistrationController extends AbstractController
{
    public function __construct(private UserService $userService) {}

    private function normalizePhoneNumber(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, '+')) {
            $digits = preg_replace('/\D+/', '', $raw);
            return is_string($digits) ? '+' . $digits : '';
        }

        if (str_starts_with($raw, '00')) {
            $digits = preg_replace('/\D+/', '', substr($raw, 2));
            if (!is_string($digits) || $digits === '') {
                return '';
            }
            return '+' . $digits;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        return is_string($digits) ? $digits : '';
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        LoggerInterface $logger,
        UserRepository $userRepo,
        HttpClientInterface $httpClient,
        Security $security,
        RecaptchaService $recaptcha
    ): Response {
        $session = $request->getSession();
        $session->start();

        /** @var mixed $google */
        $google = $session->get('google_oauth');

        // ✅ bool strict + PHPStan friendly
        $isGoogleSignup = is_array($google)
            && isset($google['email'])
            && is_string($google['email'])
            && trim($google['email']) !== '';

        $isSocialSignup = $isGoogleSignup;

        /** @var mixed $oauthData */
        $oauthData = $isGoogleSignup ? $google : null;

        $signupProvider = $isGoogleSignup ? 'google' : null;
        $googlePicture = null;

        if ($isSocialSignup && is_array($oauthData)) {
            $email = (string) ($oauthData['email'] ?? '');
            $email = trim($email);

            $user = $email !== ''
                ? ($userRepo->findOneBy(['emailUser' => $email]) ?? new User())
                : new User();

            // ✅ method_exists() supprimé (PHPStan disait "toujours true")
            if (!$user->getEmailUser() && $email !== '') {
                $user->setEmailUser($email);
            }

            $givenName  = trim((string) ($oauthData['given_name'] ?? ''));
            $familyName = trim((string) ($oauthData['family_name'] ?? ''));
            $fullName   = trim((string) ($oauthData['name'] ?? ''));

            if (!$user->getPrenom()) {
                if ($givenName !== '') {
                    $user->setPrenom($givenName);
                } elseif ($fullName !== '') {
                    $parts = preg_split('/\s+/', $fullName) ?: [];
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
                    $parts = preg_split('/\s+/', $fullName) ?: [];
                    $nom = $parts !== [] ? ($parts[count($parts) - 1] ?? '') : '';
                    if ($nom !== '') {
                        $user->setNom($nom);
                    }
                }
            }

            // googleId (optionnel)
            if ($signupProvider === 'google' && !empty($oauthData['googleId'])) {
                $user->setGoogleId((string) $oauthData['googleId']);
            }

            $googlePicture = $oauthData['picture'] ?? null;
            if (is_string($googlePicture) && $googlePicture !== '' && !$user->getProfilePicture()) {
                $user->setProfilePicture($googlePicture); // URL fallback
            }

            // Prefill téléphone / naissance si présents
            if ($signupProvider === 'google') {
                $googlePhone = $this->normalizePhoneNumber((string) ($oauthData['phone'] ?? ''));
                if ($googlePhone !== '' && !$user->getTelephoneUser()) {
                    $user->setTelephoneUser($googlePhone);
                }

                $googleBirth = trim((string) ($oauthData['birthdate'] ?? ''));
                if ($googleBirth !== '' && !$user->getDateNaissance()) {
                    try {
                        $dt = new \DateTimeImmutable($googleBirth);
                        $user->setDateNaissance($dt);
                    } catch (\Throwable) {
                        // ignore
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
            if ($user->getEmailUser()) $readonlyFields[] = 'emailUser';
            if ($user->getNom())       $readonlyFields[] = 'nom';
            if ($user->getPrenom())    $readonlyFields[] = 'prenom';
        }

        if ($isGoogleSignup && trim((string) ($user->getTelephoneUser() ?? '')) === '') {
            $this->addFlash('info', "Google n'a pas fourni de numéro de téléphone. Merci de le saisir.");
        }

        // ✅ On cache l'upload profil pendant registration si tu veux
        $hideFields[] = 'profilePictureFile';

        $form = $this->createForm(RegisterUserType::class, $user, [
            'readonly_fields' => $readonlyFields,
            'hide_fields' => $hideFields,
            'google_signup' => $isSocialSignup,
        ]);
        $form->handleRequest($request);

        // ✅ reCAPTCHA seulement après submit
        if ($form->isSubmitted() && $form->isValid()) {
            if ($recaptcha->isEnabled() && !$recaptcha->verifyRequest($request)) {
                $this->addFlash('error', 'Veuillez valider le reCAPTCHA.');
                return $this->redirectToRoute('app_register');
            }

            $cin = $form->get('cin')->getData();
            $dateNaissance = $form->get('dateNaissance')->getData();
            $user->setDateNaissance($dateNaissance);

            $telephoneUser = $this->normalizePhoneNumber((string) $form->get('telephoneUser')->getData());
            if ($telephoneUser === '') {
                $this->addFlash('error', 'Le téléphone est requis.');
                return $this->redirectToRoute('app_register');
            }
            $user->setTelephoneUser($telephoneUser);

            if (!$cin || !$dateNaissance) {
                $this->addFlash('error', 'Le CIN, le téléphone et la date de naissance sont requis.');
                return $this->redirectToRoute('app_register');
            }

            // Password
            $plainPassword = $form->has('plainPassword') ? $form->get('plainPassword')->getData() : null;

            if (is_string($plainPassword) && $plainPassword !== '') {
                $user->setPassword($hasher->hashPassword($user, $plainPassword));
            } elseif ($isSocialSignup) {
                $generated = bin2hex(random_bytes(24));
                $user->setPassword($hasher->hashPassword($user, $generated));
            } else {
                $this->addFlash('error', 'Le mot de passe est requis.');
                return $this->redirectToRoute('app_register');
            }

            // Photo profil upload (si field existe dans le form)
            $uploadedProfilePicture = $form->has('profilePictureFile') ? $form->get('profilePictureFile')->getData() : null;

            if ($uploadedProfilePicture) {
                try {
                    $ext = $uploadedProfilePicture->guessExtension() ?: 'jpg';
                    $newFilename = uniqid('pp_', true) . '.' . $ext;
                    $dirParam = $this->getParameter('profile_pictures_directory');
                    if (!is_string($dirParam)) {
                        throw new \RuntimeException('profile_pictures_directory doit être une chaîne.');
                    }
                    $dir = $dirParam;

                    if (!is_dir($dir)) {
                        @mkdir($dir, 0777, true);
                    }

                    $uploadedProfilePicture->move($dir, $newFilename);
                    $user->setProfilePicture($newFilename);
                } catch (\Throwable $e) {
                    $logger->warning('Profile picture upload failed', ['error' => $e->getMessage()]);
                }
            } elseif (is_string($googlePicture) && $googlePicture !== '') {
                // téléchargement optionnel de la photo Google en local
                try {
                    $currentPic = (string) ($user->getProfilePicture() ?? '');
                    $shouldAttemptDownload = ($currentPic === '' || str_starts_with($currentPic, 'http://') || str_starts_with($currentPic, 'https://'));

                    if ($shouldAttemptDownload) {
                        $resp = $httpClient->request('GET', $googlePicture, [
                            'max_redirects' => 10,
                            'headers' => [
                                'Accept' => 'image/*',
                                'User-Agent' => 'Mozilla/5.0 (MedFlow; Symfony HttpClient)',
                            ],
                        ]);

                        $code = $resp->getStatusCode();
                        if ($code >= 200 && $code < 300) {
                            $headers = $resp->getHeaders(false);
                            $contentType = (string) ($headers['content-type'][0] ?? '');
                            $contentType = trim(explode(';', $contentType, 2)[0]);

                            if ($contentType !== '' && str_starts_with($contentType, 'image/')) {
                                $ext = match ($contentType) {
                                    'image/png' => 'png',
                                    'image/webp' => 'webp',
                                    'image/gif' => 'gif',
                                    default => 'jpg',
                                };

                                $dirParam = $this->getParameter('profile_pictures_directory');
                                if (!is_string($dirParam)) {
                                    throw new \RuntimeException('profile_pictures_directory doit être une chaîne.');
                                }
                                $dir = $dirParam;
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

            // Rôle / vérif
            if ($isNew) {
                $user->setRoleSysteme('PATIENT');
                $user->setIsVerified(false);
            }

            if ($isSocialSignup) {
                $user->setIsVerified(true);
                $user->setVerificationToken(null);
                $user->updateTokenExpiresAt(null);
            } else {
                // token email (24h)
                if ($isNew || $user->isVerified() === false) {
                    $token = bin2hex(random_bytes(32));
                    $user->setVerificationToken($token);
                    $user->updateTokenExpiresAt((new \DateTime())->modify('+24 hours'));
                }
            }

            $this->userService->saveUser($user);

            if (!$isSocialSignup) {
                try {
                    $this->sendVerificationEmail($user);
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

            // Social: auto login
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

            // duplicates
            if ($user->getEmailUser() && $userRepo->findOneBy(['emailUser' => $user->getEmailUser()])) {
                $this->addFlash('error', "Cet email est déjà utilisé.");
                return $this->redirectToRoute('app_register_staff');
            }
            if ($user->getCin() && $userRepo->findOneBy(['cin' => $user->getCin()])) {
                $this->addFlash('error', "Ce CIN est déjà utilisé.");
                return $this->redirectToRoute('app_register_staff');
            }

            $plainPassword = $form->get('plainPassword')->getData();
            if (!is_string($plainPassword) || trim($plainPassword) === '') {
                $this->addFlash('error', 'Le mot de passe est requis.');
                return $this->redirectToRoute('app_register_staff');
            }
            $user->setPassword($hasher->hashPassword($user, $plainPassword));

            // staff fields
            $type = strtoupper(trim((string) $request->request->get('type_staff', '')));
            $message = trim((string) $request->request->get('message', ''));
            $docSpecialite = trim((string) $request->request->get('doc_specialite', ''));
            $docExperience = (int) $request->request->get('doc_experience', 0);
            $docEtablissement = trim((string) $request->request->get('doc_etablissement', ''));
            $docNumero = trim((string) $request->request->get('doc_numero', ''));

            if ($type === '' && $docSpecialite !== '') {
                $s = mb_strtolower($docSpecialite);
                $type = str_contains($s, 'pharm') ? 'PHARMACIEN' : 'MEDECIN';
            }

            if ($type === '') {
                $this->addFlash('error', 'Veuillez choisir une spécialité Staff.');
                return $this->redirectToRoute('app_register_staff');
            }
            if ($docNumero === '') {
                $this->addFlash('error', "Le numéro d'autorisation d'exercice est requis.");
                return $this->redirectToRoute('app_register_staff');
            }

            // Storage
            $docs = [];
            $projectDir = $this->getParameter('kernel.project_dir');
            if (!is_string($projectDir)) {
                $this->addFlash('error', 'Configuration kernel.project_dir invalide.');
                return $this->redirectToRoute('app_register_staff');
            }
            $baseDir = $projectDir . '/var/staff_requests';
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
                'id_doc' => "Carte d'identité",
                'diploma' => 'Diplôme médical',
                'attestation' => "Attestation d'ordre professionnel",
                'pro_photo' => 'Photo professionnelle',
            ];

            $ocrCombined = [];
            foreach ($requiredFiles as $field => $label) {
                /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $file */
                $file = $request->files->get($field);

                if (!$file) {
                    $this->addFlash('error', $label . ' manquant.');
                    return $this->redirectToRoute('app_register_staff');
                }

                $size = (int) $file->getSize();
                $mime = (string) $file->getMimeType();

                $fieldAllowed = $field === 'pro_photo' ? ['image/jpeg', 'image/png'] : $allowed;

                if ($size > $max) {
                    $this->addFlash('error', $label . ' trop volumineux (max 5MB).');
                    return $this->redirectToRoute('app_register_staff');
                }
                if (!in_array($mime, $fieldAllowed, true)) {
                    $this->addFlash('error', $label . ' : type non autorisé.');
                    return $this->redirectToRoute('app_register_staff');
                }

                $safeName = uniqid($field . '_', true) . '.' . ($file->guessExtension() ?: 'bin');
                try {
                    $file->move($baseDir, $safeName);
                } catch (\Throwable) {
                    $this->addFlash('error', 'Erreur lors du stockage du document requis.');
                    return $this->redirectToRoute('app_register_staff');
                }

                $storedRel = 'staff_requests/' . $safeName;
                $projectDir2 = $this->getParameter('kernel.project_dir');
                if (!is_string($projectDir2)) {
                    $this->addFlash('error', 'Configuration kernel.project_dir invalide.');
                    return $this->redirectToRoute('app_register_staff');
                }
                $fullPath = $projectDir2 . '/var/' . $storedRel;

                $ocrText = null;
               $ocrText = null;

// OCR seulement sur images (après validation du mime)
if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
    $res = $ocr->extractText($fullPath);
    $ocrText = is_string($res['text'] ?? null) ? trim((string) $res['text']) : null;

    if ($ocrText !== null && $ocrText !== '') {
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

/** @var array<int, \Symfony\Component\HttpFoundation\File\UploadedFile|null> $files */
foreach ($files as $file) {
    if ($file === null) {
        continue;
    }

    $size = $file->getSize();
    $mime = $file->getMimeType();

    if ($size > $max) {
        $this->addFlash('error', 'Un document est trop volumineux (max 5MB).');
        return $this->redirectToRoute('app_register_staff');
    }
    if ($mime === null || !in_array($mime, $allowed, true)) {
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
            if ($ocrCombined !== []) {
                $prompt = "Tu es un assistant pour un administrateur MedFlow.\n"
                    ."Objectif: aider à décider d'approuver/refuser une demande de création de compte Staff.\n"
                    ."Réponds en français, très court, format:\n"
                    ."- Décision: APPROUVER/REFUSER\n- Motif: ...\n- Vérifications: ...\n\n"
                    ."Informations demandeur:\n"
                    ."Spécialité: {$docSpecialite}\n"
                    ."Type staff demandé: {$type}\n"
                    ."Établissement: {$docEtablissement}\n"
                    ."N° autorisation: {$docNumero}\n"
                    ."Message: {$message}\n\n"
                    ."OCR:\n" . implode("\n\n---\n\n", $ocrCombined);

                try {
                    $aiSuggestion = trim($gemini->generate($prompt));
                    if ($aiSuggestion === '') {
                        $aiSuggestion = null;
                    }
                } catch (\Throwable) {
                    $aiSuggestion = null;
                }
            }

            $user->setRoleSysteme('PATIENT');
            $user->setTypeStaff(null);
            $user->setIsVerified(false);
            $user->setStatutCompte('EN_ATTENTE_ADMIN');

            $user->setStaffRequestStatus('PENDING');
            $user->setStaffRequestType($type);
            $user->setStaffRequestMessage($message ?: null);
            $user->markStaffRequestedAt();
            $user->setStaffDocuments([
                'meta' => $meta,
                'files' => $docs,
                'ocrEnabled' => true,
                'aiSuggestion' => $aiSuggestion,
            ]);
            $user->clearStaffReviewedAt();
            $user->setStaffReviewedBy(null);

            $token = bin2hex(random_bytes(32));
            $user->setVerificationToken($token);
            $user->updateTokenExpiresAt((new \DateTime())->modify('+24 hours'));

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

    /**
     * @return array<string, mixed>
     */
    private function sendVerificationEmail(User $user): array
    {
        $apiKey = $_ENV['BREVO_API_KEY'] ?? null;
        $appUrl = $_ENV['APP_URL'] ?? 'http://127.0.0.1:8000';
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? null;
        $senderName  = $_ENV['BREVO_SENDER_NAME'] ?? 'MedFlow';

        if (!$apiKey || !$senderEmail) {
            throw new \RuntimeException("Config Brevo manquante: BREVO_API_KEY ou BREVO_SENDER_EMAIL.");
        }

        $verifyLink = rtrim($appUrl, '/') . '/verify-email?token=' . urlencode((string) $user->getVerificationToken());

        $payload = [
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to' => [[
                'email' => $user->getEmailUser(),
                'name' => trim((string) ($user->getPrenom() ?? '') . ' ' . (string) ($user->getNom() ?? '')),
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

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Erreur CURL: ' . $err);
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("Brevo error ($httpCode): " . $response);
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        return is_array($decoded) ? $decoded : ['raw' => $response, 'httpCode' => $httpCode];
    }
}