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
     * - Choisir la catégorie du produit le + acheté par l'user
     * - Afficher des produits UNIQUEMENT de cette catégorie (achetés ou non)
     * - Si pas de top produit => catégorie la + achetée (topCategories[0])
     */
    public function recommendFromHistory(int $userId, int $limit = 12): array
    {
        $items = [];
        $usedIds = [];

        // 1) Trouver la catégorie "cible"
        $category = null;

        // 1.a) Top produit user
        $topProductId = $this->commandeRepo->getUserTopProductId($userId);
        if ($topProductId) {
            $topProduct = $this->produitRepo->find($topProductId);
            if ($topProduct) {
                $category = $topProduct->getCategorieProduit();

                // ✅ on inclut le top produit dans la reco (même s'il est déjà acheté)
                $items[] = $topProduct;
                $usedIds[] = $topProduct->getId_produit();
            }
        }

        // 1.b) Si pas de top produit => top catégorie user
        if (!$category) {
            $topCats = $this->commandeRepo->getUserTopCategories($userId, 1);
            if (!empty($topCats)) {
                $category = $topCats[0];
            }
        }

        // 2) Si on a une catégorie => retourner seulement cette catégorie
        if ($category) {
            $sameCat = $this->produitRepo->findByCategoryNonMedicaments($category, $limit * 2);

            foreach ($sameCat as $p) {
                if (count($items) >= $limit) break;
                $pid = $p->getId_produit();

                if (!in_array($pid, $usedIds, true)) {
                    $items[] = $p;
                    $usedIds[] = $pid;
                }
            }

            // ✅ Important : on STOP ici => on ne mélange pas d’autres catégories
            return array_slice($items, 0, $limit);
        }

        // 3) Si aucun historique du tout (pas de catégorie) => fallback global non-médicaments
        $fallback = $this->produitRepo->createQueryBuilder('p')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->andWhere('p.categorie_produit != :med')->setParameter('med', 'Médicaments')
            ->orderBy('p.id_produit', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $fallback;
    }
}