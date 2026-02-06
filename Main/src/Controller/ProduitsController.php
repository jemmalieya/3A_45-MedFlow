<?php

namespace App\Controller;

use App\Entity\Produit;
use App\Form\ProduitType;
use App\Repository\ProduitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ProduitsController extends AbstractController
{
    /**
     * FRONT - LISTE DES PRODUITS AVEC RECHERCHE ET TRI
     */
    #[Route('/produits', name: 'front_produit_index', methods: ['GET'])]
    public function frontIndex(Request $request, ProduitRepository $repo): Response
    {
        // Récupération des paramètres de recherche/filtrage
        $search = $request->query->get('search', '');
        $category = $request->query->get('category', '');
        $sortPrice = $request->query->get('sort', '');

        // Appel du repository avec les filtres
        $produits = $repo->findFiltered($search, $category, $sortPrice);
        $categories = $repo->findAllCategories();

        return $this->render('produits/index.html.twig', [
            'produits' => $produits,
            'categories' => $categories,
            'currentSearch' => $search,
            'currentCategory' => $category,
            'currentSort' => $sortPrice,
        ]);
    }

    /**
     * BACK (ADMIN) - LISTE DES PRODUITS
     */
    #[Route('/admin/produits', name: 'admin_produits_index', methods: ['GET'])]
    public function adminIndex(ProduitRepository $repo): Response
    {
        return $this->render('admin/index_produit.html.twig', [
            'produits' => $repo->findAll()
        ]);
    }

    /**
     * AJOUTER UN PRODUIT (admin)
     */
    #[Route('/admin/produits/new', name: 'admin_produit_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $produit = new Produit();
        $form = $this->createForm(ProduitType::class, $produit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($produit);
            $em->flush();

            $this->addFlash('success', 'Produit ajouté avec succès !');

            return $this->redirectToRoute('admin_produits_index');
        }

        return $this->render('admin/newProduit.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * MODIFIER UN PRODUIT (admin)
     */
    #[Route('/admin/produits/{id}/edit', name: 'admin_produit_edit', methods: ['GET', 'POST'])]
    public function edit(Produit $produit, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ProduitType::class, $produit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'Produit modifié avec succès !');

            return $this->redirectToRoute('admin_produits_index');
        }

        return $this->render('admin/editProduit.html.twig', [
            'produit' => $produit,
            'form' => $form->createView(),
        ]);
    }

    /**
     * SUPPRIMER UN PRODUIT (admin)
     */
    #[Route('/admin/produits/{id}/delete', name: 'admin_produit_delete', methods: ['POST'])]
    public function delete(Produit $produit, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$produit->getId_produit(), $request->request->get('_token'))) {
            $em->remove($produit);
            $em->flush();

            $this->addFlash('success', 'Produit supprimé avec succès !');
        }

        return $this->redirectToRoute('admin_produits_index');
    }
}