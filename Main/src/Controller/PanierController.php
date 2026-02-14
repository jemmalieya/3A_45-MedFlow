<?php

namespace App\Controller;

use App\Entity\Produit;
use App\Service\DrugInteractionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/panier')]
class PanierController extends AbstractController
{
    // ✅ Page panier
    #[Route('', name: 'panier_index', methods: ['GET'])]
    public function index(
        SessionInterface $session,
        EntityManagerInterface $em,
        DrugInteractionService $interactionService
    ): Response {
        $panier = $session->get('panier', []);

        $produitsPanier = [];
        $total = 0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) continue;

            $qty = (int)($item['quantite'] ?? 0);
            if ($qty <= 0) continue;

            $produit->quantite_panier = $qty;
            $produitsPanier[] = $produit;

            $total += ((float)$produit->getPrixProduit()) * $qty;
        }

        // ✅ Juste pour affichage dans Twig
        $interactionResult = $interactionService->checkCartInteractions($panier);

        return $this->render('panier/index.html.twig', [
            'produits' => $produitsPanier,
            'total' => $total,
            'interactionResult' => $interactionResult,
        ]);
    }

    // ✅ Endpoint AJAX : recalcul interactions
    #[Route('/check-interactions', name: 'panier_check_interactions', methods: ['GET'])]
    public function checkInteractions(
        SessionInterface $session,
        DrugInteractionService $interactionService
    ): JsonResponse {
        $panier = $session->get('panier', []);
        return new JsonResponse($interactionService->checkCartInteractions($panier));
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

    // ✅ Ajouter produit (NE BLOQUE PAS, juste ajoute)
    #[Route('/ajouter/{id}', name: 'panier_ajouter', methods: ['POST','GET'])]
    public function ajouter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        // Stock check
        $stock = (int) ($produit->getQuantiteProduit() ?? 0);
        $quantiteDansPanier = isset($panier[$id]) ? (int)($panier[$id]['quantite'] ?? 0) : 0;

        if ($stock > 0 && $quantiteDansPanier >= $stock) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Stock insuffisant !',
                'count' => $this->getCount($panier)
            ], 400);
        }

        $panier[$id]['quantite'] = ((int)($panier[$id]['quantite'] ?? 0)) + 1;
        $panier[$id]['prix'] = $produit->getPrixProduit();

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'message' => $produit->getNomProduit().' ajouté au panier',
            'count' => $this->getCount($panier),
            'quantite' => (int)$panier[$id]['quantite'],
        ]);
    }

    // ✅ Augmenter quantité (NE BLOQUE PAS)
    #[Route('/augmenter/{id}', name: 'panier_augmenter', methods: ['POST'])]
    public function augmenter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            $panier[$id]['quantite'] = 0;
        }

        $stock = (int) ($produit->getQuantiteProduit() ?? 0);
        $panier[$id]['quantite'] = ((int)($panier[$id]['quantite'] ?? 0)) + 1;

        if ($stock > 0 && $panier[$id]['quantite'] > $stock) {
            $panier[$id]['quantite'] = $stock;
            $session->set('panier', $panier);

            return new JsonResponse([
                'success' => false,
                'message' => 'Stock épuisé',
                'quantite' => (int)$panier[$id]['quantite'],
                'count' => $this->getCount($panier)
            ], 400);
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'quantite' => (int)$panier[$id]['quantite'],
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
            return new JsonResponse(['success' => false, 'message' => 'Produit absent'], 400);
        }

        $panier[$id]['quantite'] = ((int)$panier[$id]['quantite']) - 1;

        if ($panier[$id]['quantite'] <= 0) {
            unset($panier[$id]);
            $session->set('panier', $panier);

            return new JsonResponse([
                'success' => true,
                'quantite' => 0,
                'count' => $this->getCount($panier)
            ]);
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'quantite' => (int)$panier[$id]['quantite'],
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

        return new JsonResponse([
            'success' => true,
            'count' => $this->getCount($panier)
        ]);
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
        return array_sum(array_map(fn($i) => (int)($i['quantite'] ?? 0), $panier));
    }
}
