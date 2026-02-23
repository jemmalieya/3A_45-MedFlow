<?php

namespace App\Service;

use App\Repository\CommandeRepository;
use App\Repository\ProduitRepository;

class AiPharmacyRecommender
{
    public function __construct(
        private CommandeRepository $commandeRepo,
        private ProduitRepository $produitRepo
    ) {}

    /**
     * Reco STRICTE :
     * - Catégorie du produit le + acheté par l'user (si dispo)
     * - Sinon catégorie la + achetée
     * - Retourner UNIQUEMENT des produits de cette catégorie
     * - Si aucun historique => fallback global non-médicaments
     */
    public function recommendFromHistory(int $userId, int $limit = 12): array
    {
        $items = [];
        $usedIds = [];

        $category = null;

        // 1) Top produit (si exist)
        $topProductId = $this->commandeRepo->getUserTopProductId($userId);
        if ($topProductId) {
            $topProduct = $this->produitRepo->find($topProductId);

            if ($topProduct) {
                $category = (string) $topProduct->getCategorieProduit();

                // inclure le top produit
                $items[] = $topProduct;

                // ✅ ton entity Produit a getId_produit()
                $usedIds[] = (int) $topProduct->getId_produit();
            }
        }

        // 2) Si pas de top produit => top catégorie (attention format)
        if (!$category) {
            $topCats = $this->commandeRepo->getUserTopCategories($userId, 1);

            if (!empty($topCats)) {
                $first = $topCats[0];

                // ✅ gère les 2 formats possibles : string OU array
                if (is_string($first)) {
                    $category = $first;
                } elseif (is_array($first)) {
                    // adapte la clé selon ton repo : categorie / categorie_produit / category ...
                    $category = $first['categorie']
                        ?? $first['categorie_produit']
                        ?? $first['category']
                        ?? null;
                }

                $category = $category ? trim((string) $category) : null;
            }
        }

        // 3) Si on a une catégorie => uniquement cette catégorie
        if ($category) {
            $sameCat = $this->produitRepo->findByCategoryNonMedicaments($category, $limit * 2);

            foreach ($sameCat as $p) {
                if (count($items) >= $limit) break;

                $pid = (int) $p->getId_produit();

                if (!in_array($pid, $usedIds, true)) {
                    $items[] = $p;
                    $usedIds[] = $pid;
                }
            }

            return array_slice($items, 0, $limit);
        }

        // 4) Fallback (aucun historique)
        return $this->produitRepo->createQueryBuilder('p')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->andWhere('p.categorie_produit != :med')->setParameter('med', 'Médicaments')
            ->orderBy('p.id_produit', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}