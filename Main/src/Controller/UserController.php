<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\RecaptchaService;
use App\Form\RegisterUserType;
use App\Form\ProfileType;
use App\Form\ProfilePasswordType;
use App\Form\ResetPasswordType;
use App\Service\UserService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UserController extends AbstractController
{
    private UserService $userService;
    private UserPasswordHasherInterface $hasher;

    // Injection du service UserService
    public function __construct(
        UserService $userService,
        UserPasswordHasherInterface $hasher
    ) {
        $this->userService = $userService;
        $this->hasher = $hasher;
    }

    #[Route('/admin/users', name: 'admin_users_index')]
    public function adminIndex(Request $request, UserRepository $repo): Response
    {
        $users = $repo->findPatientsWithFilters([
            'q' => $request->query->get('q'),
        ]);

        return $this->render('admin/index_user.html.twig', [
            'users' => $users,
        ]);
    }

    // Ajouter un utilisateur (admin)
    #[Route('/admin/users/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $user = new User();
        $form = $this->createForm(RegisterUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Retrieve form fields
            $cin = $form->get('cin')->getData();
            $telephoneUser = $form->get('telephoneUser')->getData();

            // Check if CIN and telephone are provided
            if (!$cin || !$telephoneUser) {
                $this->addFlash('error', 'Le CIN et le téléphone sont requis.');
                return $this->redirectToRoute('admin_user_new');
            }

            // ✅ Fix: replace undefined UserService::createUser() call
            $plainPassword = (string) $form->get('plainPassword')->getData();
            $user->setPassword($this->hasher->hashPassword($user, $plainPassword));

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Utilisateur ajouté avec succès !');
            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('admin/newUser.html.twig', [
            'form' => $form->createView(),
        ]);
    }


    // Modifier un utilisateur (admin)
    #[Route('/admin/users/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $em, UserRepository $userRepo): Response
    {
        $user = $userRepo->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        $form = $this->createForm(RegisterUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userService->updateUser($user, [
                'prenom' => $user->getPrenom(),
                'nom' => $user->getNom(),
                // Ajouter d'autres champs si nécessaire
            ]);

            $em->flush();

            $this->addFlash('success', 'Utilisateur modifié avec succès !');
            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('admin/editUser.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
        ]);
    }

    // Supprimer un utilisateur (admin)
    #[Route('/admin/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em, UserRepository $userRepo): Response
    {
        $user = $userRepo->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        if ($this->isCsrfTokenValid('delete' . $user->getId(), (string) $request->request->get('_token'))) {
            $em->remove($user);
            $em->flush();
            $this->addFlash('success', 'Utilisateur supprimé avec succès !');
        }

        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/profile', name: 'profile_show', methods: ['GET'])]
    public function showProfile(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('Profile/_modal_show.html.twig');
    }

    // ✅ (SUPPRIME le "<?php" illégal au milieu - aucune fonctionnalité supprimée)
    #[Route('/profile/modal/edit', name: 'profile_modal_edit_submit', methods: ['POST'])]
    public function modalEditSubmit(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401);
        }

        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            if ($form->has('profilePictureFile')) {
                /** @var UploadedFile|null $file */
                $file = $form->get('profilePictureFile')->getData();

                if ($file instanceof UploadedFile) {
                    $ext = $file->guessExtension() ?: 'jpg';
                    $newFilename = uniqid('pp_', true) . '.' . $ext;

                    $dirParam = $this->getParameter('profile_pictures_directory');
                    if (!is_string($dirParam)) {
                        throw new \RuntimeException('profile_pictures_directory doit être une chaîne.');
                    }
                    $dir = $dirParam;
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0777, true);
                    }

                    $file->move($dir, $newFilename);
                    $user->setProfilePicture($newFilename);
                }
            }

            $em->flush();

            $avatarHtml = $this->renderView('Profile/_avatar_slot.html.twig');

            return $this->json([
                'success' => true,
                'message' => 'Profil mis à jour ✅',
                'avatarHtml' => $avatarHtml,
            ]);
        }

        $formHtml = $this->renderView('Profile/_modal_edit.html.twig', [
            'form' => $form->createView(),
        ]);

        return $this->json([
            'success' => false,
            'formHtml' => $formHtml,
        ], 422);
    }

    #[Route('/profile/password', name: 'profile_password', methods: ['GET', 'POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $plainPassword = (string) $form->get('plainPassword')->getData();

            $user->setPassword(
                $hasher->hashPassword($user, $plainPassword)
            );

            $em->flush();

            $this->addFlash('success', 'Mot de passe mis à jour');

            return $this->redirectToRoute('profile_show');
        }

        return $this->render('profile/password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/profile/modal/edit', name: 'profile_modal_edit', methods: ['GET'])]
    public function modalEdit(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        $form = $this->createForm(ProfileType::class, $user);

        return $this->render('Profile/_modal_edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/profile/modal/show', name: 'profile_modal_show', methods: ['GET'])]
    public function modalShow(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('Profile/_modal_show.html.twig');
    }

    #[Route('/profile/modal/password', name: 'profile_modal_password', methods: ['GET'])]
    public function modalPassword(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $form = $this->createForm(ProfilePasswordType::class);

        return $this->render('Profile/_modal_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/profile/modal/password/submit', name: 'profile_modal_password_submit', methods: ['POST'])]
    public function modalPasswordSubmit(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401);
        }

        $form = $this->createForm(ProfilePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $currentPassword = (string) $form->get('currentPassword')->getData();
            if (!$hasher->isPasswordValid($user, $currentPassword)) {
                $form->get('currentPassword')->addError(new FormError('Mot de passe actuel incorrect.'));
            } else {

                $plainPassword = (string) $form->get('plainPassword')->getData();
                $user->setPassword($hasher->hashPassword($user, $plainPassword));

                $em->flush();

                return $this->json([
                    'success' => true,
                    'message' => 'Mot de passe mis à jour ✅',
                ]);
            }
        }

        $formHtml = $this->renderView('Profile/_modal_password.html.twig', [
            'form' => $form->createView(),
        ]);

        return $this->json([
            'success' => false,
            'formHtml' => $formHtml,
        ], 422);
    }

    #[Route('/profile/modal/staff-request', name: 'profile_modal_staff_request', methods: ['GET'])]
    public function modalStaffRequest(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('Profile/_modal_staff_request.html.twig');
    }

    #[Route('/profile/modal/staff-request/submit', name: 'profile_modal_staff_request_submit', methods: ['POST'])]
    public function modalStaffRequestSubmit(
        Request $request,
        EntityManagerInterface $em,
        RecaptchaService $recaptcha
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // CSRF protection
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('staff_request', $token)) {
            return $this->json(['success' => false, 'error' => 'Session expirée, veuillez recharger la page.'], 419);
        }

        if ($recaptcha->isEnabled() && !$recaptcha->verifyRequest($request)) {
            return $this->json(['success' => false, 'error' => 'Veuillez valider le reCAPTCHA.'], 422);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Utilisateur non authentifié.'], 401);
        }

        $roleRaw = $request->request->get('role_systeme', '');
        $role = strtoupper(is_scalar($roleRaw) ? (string) $roleRaw : '');

        $typeRaw = $request->request->get('type_staff', '');
        $type = strtoupper(is_scalar($typeRaw) ? (string) $typeRaw : '');

        $messageRaw = $request->request->get('message', '');
        $message = trim(is_scalar($messageRaw) ? (string) $messageRaw : '');

        if ($role === '') {
            return $this->json(['success' => false, 'error' => 'Rôle demandé manquant'], 422);
        }

        if ($role === 'STAFF' && $type === '') {

            // Essayer d'inférer à partir de la spécialité professionnelle choisie
            $docSpecialite = trim((string) $request->request->get('doc_specialite', ''));
            if ($docSpecialite !== '') {
                $s = mb_strtolower($docSpecialite);
                if (str_contains($s, 'pharm')) {
                    $type = 'PHARMACIEN';
                } else {
                    $type = 'MEDECIN';
                }
            } else {
                return $this->json(['success' => false, 'error' => 'Veuillez choisir une spécialité Staff.'], 422);
            }
        }

        // Collect professional info
        $meta = [
            'specialite' => trim((string) ($request->request->get('doc_specialite', '') ?? '')),
            'experience' => (int) (is_numeric($request->request->get('doc_experience', 0)) ? $request->request->get('doc_experience', 0) : 0),
            'etablissement' => trim((string) ($request->request->get('doc_etablissement', '') ?? '')),
            'numero' => trim((string) ($request->request->get('doc_numero', '') ?? '')),
            'roleWanted' => $role,
            'typeStaffWanted' => $type ?: null,
        ];

        // Upload documents (optional, multiple) with validation
        $docs = [];
        $projectDirRaw = $this->getParameter('kernel.project_dir');
        $projectDir = is_string($projectDirRaw) ? $projectDirRaw : '';
        if ($projectDir === '') {
            return $this->json(['success' => false, 'error' => 'Configuration du serveur invalide.'], 500);
        }
        $baseDir = $projectDir . '/var/staff_requests';
        if (!is_dir($baseDir)) {
            if (@mkdir($baseDir, 0775, true) === false && !is_dir($baseDir)) {
                return $this->json(['success' => false, 'error' => 'Stockage indisponible. Réessayez plus tard.'], 500);
            }
        }

        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        $max = 5 * 1024 * 1024;
        $requiredFiles = [
            'id_doc' => 'Carte d\'identité',
            'diploma' => 'Diplôme médical',
            'attestation' => 'Attestation d\'ordre professionnel',
            'pro_photo' => 'Photo professionnelle',
        ];

        foreach ($requiredFiles as $field => $label) {
            $file = $request->files->get($field);
            if (!$file instanceof UploadedFile) {
                return $this->json(['success' => false, 'error' => $label . ' manquant.'], 422);
            }

            $size = (int) $file->getSize();
            $mime = (string) ($file->getMimeType() ?? '');

            $fieldAllowed = $field === 'pro_photo' ? ['image/jpeg', 'image/png'] : $allowed;

            if ($size > $max) {
                return $this->json(['success' => false, 'error' => $label . ' trop volumineux (max 5MB).'], 422);
            }
            if ($mime === '' || !in_array($mime, $fieldAllowed, true)) {
                return $this->json(['success' => false, 'error' => $label . ' : type non autorisé.'], 422);
            }

            try {
                $safeName = uniqid($field . '_') . '.' . ($file->guessExtension() ?: 'bin');
                $file->move($baseDir, $safeName);
            } catch (\Throwable $e) {
                return $this->json(['success' => false, 'error' => 'Erreur lors du stockage du document requis.'], 500);
            }

            $docs[] = [
                'original' => $file->getClientOriginalName(),
                'stored' => 'staff_requests/' . $safeName,
                'mime' => $mime,
                'size' => $size,
                'kind' => $field,
            ];
        }

        // Optional proofs[]
        $files = $request->files->all('proofs'); // ✅ toujours un tableau
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $size = (int) $file->getSize();
            $mime = (string) ($file->getMimeType() ?? '');

            if ($size > $max) {
                return $this->json(['success' => false, 'error' => 'Un document est trop volumineux (max 5MB).'], 422);
            }
            if ($mime === '' || !in_array($mime, $allowed, true)) {
                return $this->json(['success' => false, 'error' => 'Un document a un type non autorisé (PDF/JPG/PNG).'], 422);
            }

            try {
                $safeName = uniqid('doc_') . '.' . ($file->guessExtension() ?: 'bin');
                $file->move($baseDir, $safeName);
            } catch (\Throwable $e) {
                return $this->json(['success' => false, 'error' => 'Erreur lors du stockage d\'un document optionnel.'], 500);
            }

            $docs[] = [
                'original' => $file->getClientOriginalName(),
                'stored' => 'staff_requests/' . $safeName,
                'mime' => $mime,
                'size' => $size,
                'kind' => 'optional',
            ];
        }

        $user->setStaffRequestStatus('PENDING');
        $user->setStaffRequestType($role === 'STAFF' ? ($type ?: null) : null);
        $user->setStaffRequestMessage($message ?: null);
        $user->markStaffRequestedAt();
        $user->setStaffDocuments([
            'meta' => $meta,
            'files' => $docs,
        ]);
        $user->clearStaffReviewedAt();
        $user->setStaffReviewedBy(null);

        $em->flush();

        $message = 'Demande envoyée. Un administrateur va la traiter.';
        $accept = strtolower((string) $request->headers->get('accept'));
        if ($request->isXmlHttpRequest() || strpos($accept, 'application/json') !== false) {
            return $this->json(['success' => true, 'message' => $message]);
        }

        return $this->render('Profile/submit_result.html.twig', [
            'success' => true,
            'message' => $message,
        ]);
    }
    
}