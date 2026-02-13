<?php

namespace App\Controller;
use App\Repository\UserRepository;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
//#[IsGranted('ROLE_ADMIN')]
final class AdController extends AbstractController
{
    #[Route('/ad', name: 'app_ad')]
    public function index(UserRepository $userRepo): Response
    {
        return $this->render('dashboard_ad/index.html.twig', [
            'controller_name' => 'AdController',
        ]);
        
    }
    
}