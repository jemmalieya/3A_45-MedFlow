<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegisterUserType;
use App\Service\UserService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class UserController extends AbstractController
{
    private $userService;

    // Injection du service UserService
    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
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

            // Create the user via the service
            $this->userService->createUser([
                'email' => $user->getEmailUser(),
                'prenom' => $user->getPrenom(),
                'nom' => $user->getNom(),
                'cin' => $cin,
                'telephoneUser' => $telephoneUser,
                'dateNaissance' => $user->getDateNaissance(),
                'plainPassword' => $form->get('plainPassword')->getData(),
            ]);

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
        
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $em->remove($user);
            $em->flush();
            $this->addFlash('success', 'Utilisateur supprimé avec succès !');
        }

        return $this->redirectToRoute('admin_users_index');
    }
}