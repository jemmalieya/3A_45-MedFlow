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
    /* ========================= 
     * PAGE PANIER (FRONT)
     * ========================= */
    
    #[Route('', name: 'panier_index', methods: ['GET'])]
    public function index(SessionInterface $session, EntityManagerInterface $em): Response
    {
        $panier = $session->get('panier', []);

        $produitsPanier = [];
        $total = 0.0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            
            if ($produit) {
                // Propriété temporaire pour Twig
                $produit->quantite_panier = (int)($item['quantite'] ?? 0);
                $produitsPanier[] = $produit;
                $total += (float)$produit->getPrixProduit() * (int)($item['quantite'] ?? 0);
            }
        }

        return $this->render('panier/index.html.twig', [
            'produits' => $produitsPanier,
            'total' => $total
        ]);
    }

    /* ========================= 
     * BADGE NAVBAR (COUNT)
     * ========================= */
    
    #[Route('/count', name: 'panier_count', methods: ['GET'])]
    public function count(SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        
        return new JsonResponse([
            'count' => $this->getCount($panier)
        ]);
    }

    /* ========================= 
     * VÉRIFIER QUANTITÉ DANS PANIER
     * ========================= */
    
    #[Route('/verifier/{id}', name: 'panier_verifier', methods: ['GET'])]
    public function verifier(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        return new JsonResponse([
            'quantite' => isset($panier[$id]) ? (int)($panier[$id]['quantite'] ?? 0) : 0
        ]);
    }

    /* ========================= 
     * AJOUTER PRODUIT AU PANIER
     * ========================= */
    
    #[Route('/ajouter/{id}', name: 'panier_ajouter', methods: ['POST', 'GET'])]
    public function ajouter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        // Vérifier le stock disponible
        $stock = (int)($produit->getQuantiteProduit() ?? 0);
        $quantiteDansPanier = isset($panier[$id]) ? (int)($panier[$id]['quantite'] ?? 0) : 0;

        // Bloquer si stock insuffisant
        if ($stock > 0 && $quantiteDansPanier >= $stock) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Stock insuffisant ! Maximum disponible : ' . $stock,
                'count' => $this->getCount($panier)
            ], 400);
        }

        // Vérifier si le produit est disponible
        if ($produit->getStatusProduit() !== 'Disponible') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Ce produit n\'est plus disponible',
                'count' => $this->getCount($panier)
            ], 400);
        }

        // Ajouter au panier
        if (!isset($panier[$id])) {
            $panier[$id] = [
                'quantite' => 0,
                'prix' => (float)$produit->getPrixProduit()
            ];
        }

        $panier[$id]['quantite'] = (int)($panier[$id]['quantite'] ?? 0) + 1;
        $panier[$id]['prix'] = (float)$produit->getPrixProduit();

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'message' => $produit->getNomProduit() . ' ajouté au panier ✅',
            'count' => $this->getCount($panier)
        ]);
    }

    /* ========================= 
     * AUGMENTER QUANTITÉ
     * ========================= */
    
    #[Route('/augmenter/{id}', name: 'panier_augmenter', methods: ['POST'])]
    public function augmenter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Produit non trouvé dans le panier'
            ], 400);
        }

        // Vérifier le stock
        $stock = (int)($produit->getQuantiteProduit() ?? 0);
        $nouvelleQuantite = (int)($panier[$id]['quantite'] ?? 0) + 1;

        if ($stock > 0 && $nouvelleQuantite > $stock) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Stock épuisé ! Maximum : ' . $stock
            ], 400);
        }

        $panier[$id]['quantite'] = $nouvelleQuantite;
        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'quantite' => $panier[$id]['quantite'],
            'count' => $this->getCount($panier)
        ]);
    }

    /* ========================= 
     * DIMINUER QUANTITÉ
     * ========================= */
    
    #[Route('/diminuer/{id}', name: 'panier_diminuer', methods: ['POST'])]
    public function diminuer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Produit non trouvé dans le panier'
            ], 400);
        }

        $panier[$id]['quantite'] = (int)($panier[$id]['quantite'] ?? 0) - 1;

        // Supprimer si quantité <= 0
        if ($panier[$id]['quantite'] <= 0) {
            unset($panier[$id]);
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'quantite' => isset($panier[$id]) ? $panier[$id]['quantite'] : 0,
            'count' => $this->getCount($panier)
        ]);
    }

    /* ========================= 
     * SUPPRIMER PRODUIT DU PANIER
     * ========================= */
    
    #[Route('/supprimer/{id}', name: 'panier_supprimer', methods: ['POST'])]
    public function supprimer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (isset($panier[$id])) {
            unset($panier[$id]);
            $session->set('panier', $panier);

            return new JsonResponse([
                'success' => true,
                'message' => 'Produit supprimé du panier',
                'count' => $this->getCount($panier)
            ]);
        }

        return new JsonResponse([
            'success' => false,
            'message' => 'Produit non trouvé'
        ], 400);
    }

    /* ========================= 
     * VIDER LE PANIER
     * ========================= */
    
    #[Route('/vider', name: 'panier_vider', methods: ['POST'])]
    public function vider(SessionInterface $session): JsonResponse
    {
        $session->remove('panier');

        return new JsonResponse([
            'success' => true,
            'message' => 'Panier vidé',
            'count' => 0
        ]);
    }

    /* ========================= 
     * MÉTHODE PRIVÉE : COMPTER ARTICLES
     * ========================= */
    
    private function getCount(array $panier): int
    {
        $count = 0;
        
        foreach ($panier as $item) {
            $count += (int)($item['quantite'] ?? 0);
        }
        
        return $count;
    }
}