<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Ressource;
use App\Form\RessourceType;
use App\Repository\RessourceRepository;
use Symfony\Component\HttpFoundation\Request;

#[Route('/admin/ressource')]
final class RessourceController extends AbstractController

{
   
    #[Route('/', name: 'admin_ressource_index', methods: ['GET'])]
    public function index(RessourceRepository $repo): Response
    {
        return $this->render('ressource/index.html.twig', [
            'ressources' => $repo->findAll(),
        ]);
    }

    
    #[Route('/new', name: 'admin_ressource_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $ressource = new Ressource();
        $form = $this->createForm(RessourceType::class, $ressource);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($ressource);
            $em->flush();

            $this->addFlash('success', 'Ressource ajoutée avec succès');

            return $this->redirectToRoute('admin_ressource_index');
        }

        return $this->render('admin/new_Ressource.html.twig', [
            'form' => $form->createView(),
        ]);
    }

   
    #[Route('/{id}', name: 'admin_ressource_show', methods: ['GET'])]
    public function show(Ressource $ressource): Response
    {
        return $this->render('admin/show_Ressource.html.twig', [
            'ressource' => $ressource,
        ]);
    }

   
    #[Route('/{id}/edit', name: 'admin_ressource_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Ressource $ressource, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(RessourceType::class, $ressource);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'Ressource modifiée');

            return $this->redirectToRoute('admin_ressource_index');
        }

        return $this->render('admin/edit_Ressource.html.twig', [
            'form' => $form->createView(),
            'ressource' => $ressource,
        ]);
    }

    
    #[Route('/{id}/delete', name: 'admin_ressource_delete', methods: ['POST'])]
    public function delete(Request $request, Ressource $ressource, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$ressource->getId(), $request->request->get('_token'))) {
            $em->remove($ressource);
            $em->flush();

            $this->addFlash('success', 'Ressource supprimée');
        }

        return $this->redirectToRoute('admin_ressource_index');
    }
    

   



    
}