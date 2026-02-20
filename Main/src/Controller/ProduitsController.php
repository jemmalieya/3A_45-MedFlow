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
use App\Repository\CommandeRepository;
use App\Service\AiPharmacyRecommender;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

final class ProduitsController extends AbstractController
{
    /**
     * FRONT - LISTE DES PRODUITS AVEC RECHERCHE ET TRI
     */
    #[Route('/produits', name: 'front_produit_index', methods: ['GET'])]
    public function frontIndex(Request $request, ProduitRepository $repo): Response
    {
        $search = $request->query->get('search', '');
        $category = $request->query->get('category', '');
        $sortPrice = $request->query->get('sort', '');

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
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $produit = new Produit();
    
        $form = $this->createForm(ProduitType::class, $produit, [
            'mode' => 'create'
        ]);
        $form->handleRequest($request);
    
        if ($form->isSubmitted() && $form->isValid()) {
    
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('imageFile')->getData();
    
            if ($imageFile) {
                $original = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeName = $slugger->slug($original);
                $newFilename = $safeName.'-'.uniqid().'.'.$imageFile->guessExtension();
    
                $imageFile->move(
                    $this->getParameter('produits_images_dir'),
                    $newFilename
                );
    
                $produit->setImageProduit($newFilename);
            } else {
                $this->addFlash('error', "Veuillez choisir une image.");
                return $this->render('admin/newProduit.html.twig', [
                    'form' => $form->createView(),
                ]);
            }
    
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
    public function edit(Produit $produit, Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $oldImage = $produit->getImageProduit();
    
        $form = $this->createForm(ProduitType::class, $produit, [
            'mode' => 'edit'
        ]);
        $form->handleRequest($request);
    
        if ($form->isSubmitted() && $form->isValid()) {
    
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('imageFile')->getData();
    
            if ($imageFile) {
                $original = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeName = $slugger->slug($original);
                $newFilename = $safeName.'-'.uniqid().'.'.$imageFile->guessExtension();
    
                $imageFile->move(
                    $this->getParameter('produits_images_dir'),
                    $newFilename
                );
    
                $produit->setImageProduit($newFilename);
    
                // ✅ supprimer ancienne image (optionnel mais propre)
                if ($oldImage) {
                    $oldPath = $this->getParameter('produits_images_dir').'/'.$oldImage;
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }
            } else {
                // garde l'ancienne
                $produit->setImageProduit($oldImage);
            }
    
            $em->flush();
            $this->addFlash('success', 'Produit modifié avec succès !');
            return $this->redirectToRoute('admin_produits_index');
        }
    
        return $this->render('admin/editProduit.html.twig', [
            'produit' => $produit,
            'form' => $form->createView(),
            'oldImage' => $oldImage
        ]);
    }
    /**
     * SUPPRIMER UN PRODUIT (admin)
     */
    #[Route('/admin/produits/{id}/delete', name: 'admin_produit_delete', methods: ['POST'])]
    public function delete(Produit $produit, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $produit->getId_produit(), $request->request->get('_token'))) {
    
            $img = $produit->getImageProduit();
    
            $em->remove($produit);
            $em->flush();
    
            if ($img) {
                $path = $this->getParameter('produits_images_dir').'/'.$img;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
    
            $this->addFlash('success', 'Produit supprimé avec succès !');
        }
    
        return $this->redirectToRoute('admin_produits_index');
    }

    /**
     * API - Best Sellers (hors médicaments)
     */
    #[Route('/produits/api/best-sellers', name: 'produits_api_best_sellers', methods: ['GET'])]
    public function apiBestSellers(CommandeRepository $cr): JsonResponse
    {
        $produits = $cr->getBestSellersNonMedicaments(12);
        $fallback = null;

        if (empty($produits)) {
            $produits = $cr->getBestSellersGlobal(12);
            $fallback = 'global';
        }

        $items = array_map(fn($p) => [
            'id' => $p->getId_produit(),
            'nom' => $p->getNomProduit(),
            'prix' => (float) $p->getPrixProduit(),
'image' => $p->getImageProduit() ? '/uploads/produits/' . $p->getImageProduit() : null,            'categorie' => $p->getCategorieProduit(),
        ], $produits);

        return new JsonResponse([
            'success' => true,
            'fallback' => $fallback,
            'items' => $items,
        ]);
    }

    #[Route('/produits/api/reco-ai', name: 'produits_api_reco_ai', methods: ['GET'])]
public function apiRecoAi(AiPharmacyRecommender $reco, CommandeRepository $cr, ProduitRepository $pr): JsonResponse
{
    $userId = 1;

    // 1) reco "normale" (ta logique existante)
    $items = $reco->recommendFromHistory($userId, 12);

    // 2) basé sur (déjà chez toi)
    $topId = $cr->getUserTopProductId($userId);
    $basedOn = null;
    $mode = 'personalized';
    $explainText = "Basé sur votre historique d’achat (hors médicaments).";

    if ($topId) {
        $p = $pr->find($topId);
        if ($p) {
            $basedOn = [
                'topProduct' => $p->getNomProduit(),
                'category' => $p->getCategorieProduit(),
            ];
            $explainText = "Basé sur votre produit le plus acheté : {$basedOn['topProduct']} (catégorie : {$basedOn['category']}).";
        }
    } else {
        // si pas de top produit (donc souvent pas d'historique) => message plus clair
        $explainText = "Pas assez d’historique : suggestions basées sur les tendances du moment.";
    }

    // 3) Cold start "Tendances" (sans changer ta logique interne)
    // Si items vides OU si on n’a pas de top produit et que la reco vient d'un fallback global,
    // on bascule sur best-sellers comme "tendances du moment".
    if (empty($items)) {
        $trending = $cr->getBestSellersNonMedicaments(12);
        if (!empty($trending)) {
            $items = $trending;
            $mode = 'trending';
            $explainText = "Tendances du moment (hors médicaments) — en attendant plus d’historique.";
        } else {
            $mode = 'fallback_global';
            $explainText = "Suggestions disponibles (hors médicaments).";
        }
    }

    // 4) construire items + badges (Explainable AI côté UI)
    $targetCategory = $basedOn['category'] ?? null;

    $payloadItems = array_map(function($p) use ($topId, $targetCategory, $mode) {
        $badges = [];

        if ($mode === 'trending') $badges[] = '🔥 Tendance';
        if ($topId && $p->getId_produit() === $topId) $badges[] = '⭐ Votre favori';
        if ($targetCategory && $p->getCategorieProduit() === $targetCategory) $badges[] = '🏷️ Même catégorie';

        return [
            'id' => $p->getId_produit(),
            'nom' => $p->getNomProduit(),
            'prix' => (float) $p->getPrixProduit(),
'image' => $p->getImageProduit() ? '/uploads/produits/' . $p->getImageProduit() : null,            'categorie' => $p->getCategorieProduit(),
            'badges' => $badges,
        ];
    }, $items);

    return new JsonResponse([
        'success' => true,
        'mode' => $mode,
        'basedOn' => $basedOn,
        'explainText' => $explainText,
        'items' => $payloadItems,
    ]);
}
}