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
     * 
     * Cette fonction gère la liste des produits côté front-office avec des filtres de recherche, 
     * de tri par prix et stock, et des catégories.
     */
    #[Route('/produits', name: 'front_produit_index', methods: ['GET'])]
    public function frontIndex(Request $request, ProduitRepository $repo): Response
    {
        // Récupération des paramètres de recherche, catégorie, et tri
        $search = $request->query->get('search', '');  // Recherche par nom, catégorie ou description
        $category = $request->query->get('category', '');  // Filtre par catégorie
        $sortPrice = $request->query->get('sort', '');  // Tri par prix (croissant ou décroissant)
        $sortStock = $request->query->get('sortStock', '');  // Tri par stock (croissant ou décroissant)

        // Appel au repository pour récupérer les produits filtrés
// Ajout du tri pour les prix et le stock dans la requête
$produits = $repo->findFiltered($search, $category, $sortPrice, $sortStock);
        $categories = $repo->findAllCategories();  // Récupération de toutes les catégories distinctes

        // Retourner les produits à la vue
        return $this->render('produits/index.html.twig', [
            'produits' => $produits,
            'categories' => $categories,
            'currentSearch' => $search,
            'currentCategory' => $category,
            'currentSort' => $sortPrice,
            'currentSortStock' => $sortStock,  // Passer la valeur du tri par stock
        ]);
    }

    /**
     * BACK (ADMIN) - LISTE DES PRODUITS
     * 
     * Cette fonction affiche tous les produits dans l'interface d'administration.
     */
    #[Route('/admin/produits', name: 'admin_produits_index', methods: ['GET'])]
    public function adminIndex(ProduitRepository $repo): Response
    {
        // Récupération de tous les produits pour l'admin
        return $this->render('admin/index_produit.html.twig', [
            'produits' => $repo->findAll()  // Récupère tous les produits
        ]);
    }

    /**
     * AJOUTER UN PRODUIT (admin)
     * 
     * Cette fonction permet à l'administrateur d'ajouter un nouveau produit via un formulaire.
     */
    #[Route('/admin/produits/new', name: 'admin_produit_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        // Création d'un nouvel objet Produit
        $produit = new Produit();
        $form = $this->createForm(ProduitType::class, $produit);  // Formulaire de produit
        $form->handleRequest($request);  // Gestion de la requête HTTP

        // Si le formulaire est soumis et valide
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($produit);  // Persister le produit
            $em->flush();  // Sauvegarder en base de données

            $this->addFlash('success', 'Produit ajouté avec succès !');  // Message flash de succès

            return $this->redirectToRoute('admin_produits_index');  // Redirection vers la liste des produits
        }

        // Retourner la vue avec le formulaire
        return $this->render('admin/newProduit.html.twig', [
            'form' => $form->createView(),  // Passer le formulaire à la vue
        ]);
    }

    /**
     * MODIFIER UN PRODUIT (admin)
     * 
     * Cette fonction permet à l'administrateur de modifier un produit existant.
     */
    #[Route('/admin/produits/{id}/edit', name: 'admin_produit_edit', methods: ['GET', 'POST'])]
    public function edit(Produit $produit, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ProduitType::class, $produit);  // Formulaire pour modifier le produit
        $form->handleRequest($request);

        // Si le formulaire est soumis et valide
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();  // Sauvegarder les modifications en base de données

            $this->addFlash('success', 'Produit modifié avec succès !');  // Message flash de succès

            return $this->redirectToRoute('admin_produits_index');  // Redirection vers la liste des produits
        }

        // Retourner la vue avec le formulaire de modification
        return $this->render('admin/editProduit.html.twig', [
            'produit' => $produit,
            'form' => $form->createView(),
        ]);
    }

    /**
     * SUPPRIMER UN PRODUIT (admin)
     * 
     * Cette fonction permet à l'administrateur de supprimer un produit de la base de données.
     */
    #[Route('/admin/produits/{id}/delete', name: 'admin_produit_delete', methods: ['POST'])]
    public function delete(Produit $produit, EntityManagerInterface $em): Response
    {
        // Supprimer le produit de la base de données sans la validation CSRF
        $em->remove($produit);  
        $em->flush();  // Sauvegarder les changements
    
        $this->addFlash('success', 'Produit supprimé avec succès !');  // Message flash de succès
    
        // Retourner à la liste des produits après suppression
        return $this->redirectToRoute('admin_produits_index');
    }
    
}
