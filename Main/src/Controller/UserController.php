<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegisterUserType;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserController extends AbstractController
{
    #[Route('/user', name: 'app_user')]
    public function index(): Response
    {
        return $this->render('user/index.html.twig');
    }

    
    #[Route('/user/list', name: 'app_user_list')]
    public function userList(UserRepository $userRepository): Response
    {
        $users = $userRepository->findAll();

        return $this->render('user/list.html.twig', [
            'users' => $users
        ]);
    }

    #[Route('/user/details/{id}', name: 'app_user_details')]
    public function userDetails(int $id, UserRepository $userRepository): Response
    {
        $user = $userRepository->find($id);
        if (!$user) {
            throw $this->createNotFoundException("User not found");
        }

        return $this->render('user/details.html.twig', [
            'user' => $user
        ]);
    }

    /*#[Route('/user/update/{id}', name: 'app_user_update')]
    public function updateUser(
        int $id,
        UserRepository $userRepository,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $user = $userRepository->find($id);
        if (!$user) {
            throw $this->createNotFoundException("User not found");
        }

        $form = $this->createForm(AddEditUserType::class, $user, [
            'is_edit' => true
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // If user typed a new password -> hash and replace
            if ($user->getPlainPassword()) {
                $user->setPassword(
                    $hasher->hashPassword($user, $user->getPlainPassword())
                );
                $user->setPlainPassword(null);
            }

            $em->flush();
            return $this->redirectToRoute('app_user_list');
        }

        return $this->render('user/form.html.twig', [
            'title' => 'Update User',
            'form'  => $form->createView(),
        ]);
    }*/

    #[Route('/user/delete/{id}', name: 'app_user_delete')]
    public function deleteUser(int $id, UserRepository $userRepository, EntityManagerInterface $em): Response
    {
        $user = $userRepository->find($id);
        if ($user) {
            $em->remove($user);
            $em->flush();
        }

        return $this->redirectToRoute('app_user_list');
    }

    // ✅ Search example (by email)
    #[Route('/user/search/email/{email}', name: 'app_user_search_email')]
    public function searchByEmail(string $email, UserRepository $userRepository): Response
    {
        $users = $userRepository->searchByEmail($email);

        return $this->render('user/list.html.twig', [
            'users' => $users
        ]);
    }

    // ✅ Search example (by cin)
    #[Route('/user/search/cin/{cin}', name: 'app_user_search_cin')]
    public function searchByCin(string $cin, UserRepository $userRepository): Response
    {
        $users = $userRepository->searchByCin($cin);

        return $this->render('user/list.html.twig', [
            'users' => $users
        ]);
    }
}
