<?php

namespace App\Controller;

use App\Entity\Produit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

#[Route('/panier')]
class PanierController extends AbstractController
{
    // ✅ Page panier (front)
    #[Route('', name: 'panier_index', methods: ['GET'])]
    public function index(SessionInterface $session, EntityManagerInterface $em): Response
    {
        $panier = $session->get('panier', []);

        $produitsPanier = [];
        $total = 0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if ($produit) {
                $produit->quantite_panier = $item['quantite'];
                $produitsPanier[] = $produit;
                $total += $produit->getPrixProduit() * $item['quantite'];
            }
        }

        return $this->render('panier/index.html.twig', [
            'produits' => $produitsPanier,
            'total' => $total
        ]);
    }

    // ✅ Badge navbar
    #[Route('/count', name: 'panier_count', methods: ['GET'])]
    public function count(SessionInterface $session): JsonResponse
    {
        return new JsonResponse(['count' => $this->getCount($session->get('panier', []))]);
    }

    // ✅ Vérifier quantité déjà ajoutée
    #[Route('/verifier/{id}', name: 'panier_verifier', methods: ['GET'])]
    public function verifier(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        return new JsonResponse([
            'quantite' => isset($panier[$id]) ? (int)($panier[$id]['quantite'] ?? 0) : 0
        ]);
    }

    // ✅ Ajouter produit
    #[Route('/ajouter/{id}', name: 'panier_ajouter', methods: ['POST', 'GET'])]
    public function ajouter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        $stock = (int) ($produit->getQuantiteProduit() ?? 0);
        $quantiteDansPanier = isset($panier[$id]) ? (int)($panier[$id]['quantite'] ?? 0) : 0;

        if ($stock > 0 && $quantiteDansPanier >= $stock) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Stock insuffisant !',
                'count' => $this->getCount($panier)
            ], 400);
        }

        $panier[$id]['quantite'] = ($panier[$id]['quantite'] ?? 0) + 1;
        $panier[$id]['prix'] = $produit->getPrixProduit();

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'message' => $produit->getNomProduit() . ' ajouté au panier',
            'count' => $this->getCount($panier)
        ]);
    }

    // ✅ Augmenter quantité
    #[Route('/augmenter/{id}', name: 'panier_augmenter', methods: ['POST'])]
    public function augmenter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        $stock = (int) ($produit->getQuantiteProduit() ?? 0);
        $panier[$id]['quantite'] = ($panier[$id]['quantite'] ?? 0) + 1;

        if ($stock > 0 && $panier[$id]['quantite'] > $stock) {
            return new JsonResponse(['success' => false, 'message' => 'Stock épuisé'], 400);
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'quantite' => $panier[$id]['quantite'],
            'count' => $this->getCount($panier)
        ]);
    }

    // ✅ Diminuer quantité
    #[Route('/diminuer/{id}', name: 'panier_diminuer', methods: ['POST'])]
    public function diminuer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            return new JsonResponse(['success' => false], 400);
        }

        $panier[$id]['quantite']--;

        if ($panier[$id]['quantite'] <= 0) {
            unset($panier[$id]);
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'count' => $this->getCount($panier)
        ]);
    }

    // ✅ Supprimer produit
    #[Route('/supprimer/{id}', name: 'panier_supprimer', methods: ['POST'])]
    public function supprimer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        unset($panier[$produit->getId_produit()]);
        $session->set('panier', $panier);

        return new JsonResponse(['success' => true, 'count' => $this->getCount($panier)]);
    }

    // ✅ Vider panier
    #[Route('/vider', name: 'panier_vider', methods: ['POST'])]
    public function vider(SessionInterface $session): JsonResponse
    {
        $session->remove('panier');
        return new JsonResponse(['success' => true, 'count' => 0]);
    }

    private function getCount(array $panier): int
    {
        return array_sum(array_column($panier, 'quantite'));
    }
}
