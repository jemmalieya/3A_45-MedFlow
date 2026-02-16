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
    /* =========================
     * PAGE PANIER (FRONT)
     * ========================= */

    #[Route('', name: 'panier_index', methods: ['GET'])]
    public function index(
        SessionInterface $session,
        EntityManagerInterface $em,
        DrugInteractionService $interactionService
    ): Response {
        $panier = $session->get('panier', []);

        $produitsPanier = [];
        $total = 0.0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) continue;

            $qty = (int)($item['quantite'] ?? 0);
            if ($qty <= 0) continue;

            // Propriété temporaire pour Twig (OK même sans setter)
            $produit->quantite_panier = $qty;

            $produitsPanier[] = $produit;
            $total += (float)$produit->getPrixProduit() * $qty;
        }

        // Résultat interactions (affichage Twig)
        $interactionResult = $interactionService->checkCartInteractions($panier);

        return $this->render('panier/index.html.twig', [
            'produits' => $produitsPanier,
            'total' => $total,
            'interactionResult' => $interactionResult,
        ]);
    }

    /* =========================
     * AJAX : recalcul interactions
     * ========================= */

    #[Route('/check-interactions', name: 'panier_check_interactions', methods: ['GET'])]
    public function checkInteractions(
        SessionInterface $session,
        DrugInteractionService $interactionService
    ): JsonResponse {
        $panier = $session->get('panier', []);
        return new JsonResponse($interactionService->checkCartInteractions($panier));
    }

    /* =========================
     * BADGE NAVBAR (COUNT)
     * ========================= */

    #[Route('/count', name: 'panier_count', methods: ['GET'])]
    public function count(SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        return new JsonResponse(['count' => $this->getCount($panier)]);
    }

    /* =========================
     * Vérifier quantité déjà ajoutée
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
     * Ajouter produit
     * ========================= */

    #[Route('/ajouter/{id}', name: 'panier_ajouter', methods: ['POST', 'GET'])]
    public function ajouter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        // ✅ Vérifier statut
        if ($produit->getStatusProduit() !== 'Disponible') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Ce produit n\'est plus disponible.',
                'count' => $this->getCount($panier),
                'quantite' => isset($panier[$id]) ? (int)($panier[$id]['quantite'] ?? 0) : 0,
            ], 400);
        }

        // ✅ Vérifier stock
        $stock = (int)($produit->getQuantiteProduit() ?? 0);
        $quantiteDansPanier = isset($panier[$id]) ? (int)($panier[$id]['quantite'] ?? 0) : 0;

        if ($stock > 0 && $quantiteDansPanier >= $stock) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Stock insuffisant ! Maximum disponible : ' . $stock,
                'count' => $this->getCount($panier),
                'quantite' => $quantiteDansPanier,
            ], 400);
        }

        // ✅ Ajouter
        if (!isset($panier[$id])) {
            $panier[$id] = [
                'quantite' => 0,
                'prix' => (float)$produit->getPrixProduit(),
            ];
        }

        $panier[$id]['quantite'] = (int)($panier[$id]['quantite'] ?? 0) + 1;
        $panier[$id]['prix'] = (float)$produit->getPrixProduit();

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'message' => $produit->getNomProduit() . ' ajouté au panier ✅',
            'count' => $this->getCount($panier),
            'quantite' => (int)$panier[$id]['quantite'],
        ]);
    }

    /* =========================
     * Augmenter quantité
     * ========================= */

    #[Route('/augmenter/{id}', name: 'panier_augmenter', methods: ['POST'])]
    public function augmenter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Produit non trouvé dans le panier.',
                'count' => $this->getCount($panier),
                'quantite' => 0,
            ], 400);
        }

        // ✅ Stock check
        $stock = (int)($produit->getQuantiteProduit() ?? 0);
        $nouvelleQuantite = (int)($panier[$id]['quantite'] ?? 0) + 1;

        if ($stock > 0 && $nouvelleQuantite > $stock) {
            // on "cap" sur stock (optionnel) OU on refuse
            return new JsonResponse([
                'success' => false,
                'message' => 'Stock épuisé ! Maximum : ' . $stock,
                'count' => $this->getCount($panier),
                'quantite' => (int)($panier[$id]['quantite'] ?? 0),
            ], 400);
        }

        $panier[$id]['quantite'] = $nouvelleQuantite;
        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'count' => $this->getCount($panier),
            'quantite' => (int)$panier[$id]['quantite'],
        ]);
    }

    /* =========================
     * Diminuer quantité
     * ========================= */

    #[Route('/diminuer/{id}', name: 'panier_diminuer', methods: ['POST'])]
    public function diminuer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Produit non trouvé dans le panier.',
                'count' => $this->getCount($panier),
                'quantite' => 0,
            ], 400);
        }

        $panier[$id]['quantite'] = (int)($panier[$id]['quantite'] ?? 0) - 1;

        if ($panier[$id]['quantite'] <= 0) {
            unset($panier[$id]);
            $session->set('panier', $panier);

            return new JsonResponse([
                'success' => true,
                'message' => 'Produit retiré du panier.',
                'count' => $this->getCount($panier),
                'quantite' => 0,
            ]);
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'count' => $this->getCount($panier),
            'quantite' => (int)$panier[$id]['quantite'],
        ]);
    }

    /* =========================
     * Supprimer un produit
     * ========================= */

    #[Route('/supprimer/{id}', name: 'panier_supprimer', methods: ['POST'])]
    public function supprimer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Produit non trouvé.',
                'count' => $this->getCount($panier),
            ], 400);
        }

        unset($panier[$id]);
        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'message' => 'Produit supprimé du panier.',
            'count' => $this->getCount($panier),
        ]);
    }

    /* =========================
     * Vider le panier
     * ========================= */

    #[Route('/vider', name: 'panier_vider', methods: ['POST'])]
    public function vider(SessionInterface $session): JsonResponse
    {
        $session->remove('panier');
        return new JsonResponse([
            'success' => true,
            'message' => 'Panier vidé.',
            'count' => 0
        ]);
    }

    /* =========================
     * Utils
     * ========================= */

    private function getCount(array $panier): int
    {
        return array_sum(array_map(fn($i) => (int)($i['quantite'] ?? 0), $panier));
    }
}
