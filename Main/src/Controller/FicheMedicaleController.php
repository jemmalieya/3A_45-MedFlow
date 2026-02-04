<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FicheMedicaleController extends AbstractController
{
    #[Route('/fiche/medicale', name: 'app_fiche_medicale')]
    public function index(): Response
    {
        return $this->render('fiche_medicale/index.html.twig', [
            'controller_name' => 'FicheMedicaleController',
        ]);
    }
}
