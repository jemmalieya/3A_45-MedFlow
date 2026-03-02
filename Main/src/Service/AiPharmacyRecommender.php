<?php

namespace App\Service;

use App\Entity\Produit;
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
     *
     * @return list<Produit>
     */
    public function recommendFromHistory(int $userId, int $limit = 12): array
    {
        /** @var list<Produit> $items */
        $items = [];

        /** @var list<int> $usedIds */
        $usedIds = [];

        $category = null;

        // 1) Top produit (si exist)
        $topProductId = $this->commandeRepo->getUserTopProductId($userId);
        if ($topProductId) {
            $topProduct = $this->produitRepo->find($topProductId);

            if ($topProduct instanceof Produit) {
                $category = (string) $topProduct->getCategorieProduit();

                // inclure le top produit
                $items[] = $topProduct;

                $usedIds[] = (int) $topProduct->getId_produit();
            }
        }

// 2) Si pas de top produit => top catégorie
if ($category === null || trim($category) === '') {
    /** @var array<int, mixed> $topCats */
    $topCats = $this->commandeRepo->getUserTopCategories($userId, 1);

    if ($topCats !== []) {
        $first = $topCats[0];

        if (is_string($first)) {
            $category = trim($first);
        } elseif (is_array($first)) {
            $cat = null;

            if (array_key_exists('categorie', $first) && is_string($first['categorie'])) {
                $cat = $first['categorie'];
            } elseif (array_key_exists('categorie_produit', $first) && is_string($first['categorie_produit'])) {
                $cat = $first['categorie_produit'];
            } elseif (array_key_exists('category', $first) && is_string($first['category'])) {
                $cat = $first['category'];
            }

            $category = $cat !== null ? trim($cat) : null;
        } else {
            $category = null;
        }

        if ($category === '') {
            $category = null;
        }
    }
}

        // 3) Si on a une catégorie => uniquement cette catégorie
        if ($category) {
            /** @var list<Produit> $sameCat */
            $sameCat = $this->produitRepo->findByCategoryNonMedicaments($category, $limit * 2);

            foreach ($sameCat as $p) {
                if (count($items) >= $limit) {
                    break;
                }

                $pid = (int) $p->getId_produit();

                if (!in_array($pid, $usedIds, true)) {
                    $items[] = $p;
                    $usedIds[] = $pid;
                }
            }

            return array_slice($items, 0, $limit);
        }

        // 4) Fallback (aucun historique)
        /** @var list<Produit> $fallback */
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