<?php

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Commande>
 */
class CommandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commande::class);
    }

    /* ==========================================================
       Helpers (gérer les retours Doctrine: entités ou tableaux)
       ========================================================== */

    private function extractProduitFromRow(mixed $row): ?Produit
    {
        if (is_array($row)) {
            foreach ($row as $v) {
                if ($v instanceof Produit) {
                    return $v;
                }
                if ($v instanceof LigneCommande) {
                    return $v->getProduit();
                }
            }
            return null;
        }

        if ($row instanceof Produit) {
            return $row;
        }
        if ($row instanceof LigneCommande) {
            return $row->getProduit();
        }

        return null;
    }

    private function getProduitId(Produit $produit): int
    {
        return (int) $produit->getId_produit();
    }

    /**
     * @param array<int, mixed> $rows
     * @return Produit[]
     */
    private function uniqueProduitsFromRows(array $rows, int $limit): array
    {
        $produits = [];
        $ids = [];

        foreach ($rows as $row) {
            $produit = $this->extractProduitFromRow($row);
            if (!$produit) {
                continue;
            }

            $pid = $this->getProduitId($produit);
            if ($pid <= 0) {
                continue;
            }

            if (!in_array($pid, $ids, true)) {
                $ids[] = $pid;
                $produits[] = $produit;

                if (count($produits) >= $limit) {
                    break;
                }
            }
        }

        return $produits;
    }

    /* ==========================================================
       ✅ BEST SELLERS / TENDANCES
       ========================================================== */

    /**
     * ✅ Best sellers : 1 produit par catégorie (diversité maximale)
     * (hors médicaments)
     *
     * ⚠️ Fix perf : LIMIT pour éviter ORDER BY sur énorme dataset
     *
     * @return Produit[]
     */
    public function getBestSellersNonMedicaments(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));

        // On récupère plus large que $limit (car on fait diversité par catégorie)
        $fetch = $limit * 20;

        /** @var array<int, mixed> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT lc, p, SUM(lc.quantite_commandee) AS score
             FROM App\Entity\LigneCommande lc
             JOIN lc.commande c
             JOIN lc.produit p
             WHERE c.statut_commande != :annule
               AND p.status_produit = :st
               AND p.categorie_produit != :med
             GROUP BY p.id_produit
             ORDER BY score DESC'
        )
            ->setParameter('annule', 'Annulée')
            ->setParameter('st', 'Disponible')
            ->setParameter('med', 'Médicaments')
            ->setMaxResults($fetch) // ✅ FIX
            ->getResult();

        $produits = [];
        $categoriesVues = [];

        foreach ($rows as $row) {
            $produit = $this->extractProduitFromRow($row);
            if (!$produit) {
                continue;
            }

            $categorie = trim((string) $produit->getCategorieProduit());
            if ($categorie === '') {
                continue;
            }

            if (!in_array($categorie, $categoriesVues, true)) {
                $categoriesVues[] = $categorie;
                $produits[] = $produit;

                if (count($produits) >= $limit) {
                    break;
                }
            }
        }

        return $produits;
    }

    /**
     * ✅ Best sellers global (fallback)
     *
     * @return Produit[]
     */
    public function getBestSellersGlobal(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));

        /** @var array<int, mixed> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lc')
            ->innerJoin('lc.commande', 'c')
            ->innerJoin('lc.produit', 'p')
            ->select('lc', 'p', 'SUM(lc.quantite_commandee) AS HIDDEN score')
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->groupBy('p.id_produit')
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit * 3)
            ->getQuery()
            ->getResult();

        return $this->uniqueProduitsFromRows($rows, $limit);
    }

    /* ==========================================================
       ✅ HISTORIQUE USER
       ========================================================== */

    /**
     * ✅ Historique user : ids produits déjà achetés
     *
     * @return int[]
     */
    public function getUserPurchasedProductIds(int $userId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        /** @var array<int, array{id:mixed}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lc')
            ->innerJoin('lc.commande', 'c')
            ->select('DISTINCT IDENTITY(lc.produit) AS id')
            ->andWhere('IDENTITY(c.user) = :u')->setParameter('u', $userId)
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->orderBy('c.date_creation_commande', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn(array $r): int => (int) $r['id'], $rows));
    }

    /**
     * ✅ Top catégories achetées par user
     *
     * @return string[]
     */
    public function getUserTopCategories(int $userId, int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));

        /** @var array<int, array{categorie:mixed, score:mixed}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lc')
            ->innerJoin('lc.commande', 'c')
            ->innerJoin('lc.produit', 'p')
            ->select('p.categorie_produit AS categorie, SUM(lc.quantite_commandee) AS score')
            ->andWhere('IDENTITY(c.user) = :u')->setParameter('u', $userId)
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->groupBy('p.categorie_produit')
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn(array $r): string => (string) $r['categorie'], $rows));
    }

    /**
     * ✅ Produit le plus acheté par user (id)
     */
    public function getUserTopProductId(int $userId): ?int
    {
        $row = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lc')
            ->innerJoin('lc.commande', 'c')
            ->select('IDENTITY(lc.produit) AS id, SUM(lc.quantite_commandee) AS score')
            ->andWhere('IDENTITY(c.user) = :u')->setParameter('u', $userId)
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->groupBy('id')
            ->orderBy('score', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
    }

    /* ==========================================================
       ✅ CO-OCCURRENCE (reco personnalisée)
       ========================================================== */

    /**
     * ✅ Co-occurrence : "les gens qui achètent X achètent aussi Y"
     *
     * @param int[] $seedProductIds
     * @param int[] $excludeIds
     * @return Produit[]
     */
    public function getCoOccurringProducts(array $seedProductIds, array $excludeIds = [], int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));

        if ($seedProductIds === []) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lcRec')
            ->innerJoin('lcRec.commande', 'c')
            ->innerJoin('c.ligne_commandes', 'lcSeed')
            ->innerJoin('lcRec.produit', 'pRec')
            ->select('lcRec', 'pRec', 'SUM(lcRec.quantite_commandee) AS HIDDEN score')
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->andWhere('IDENTITY(lcSeed.produit) IN (:seedIds)')->setParameter('seedIds', $seedProductIds)
            ->andWhere('pRec.status_produit = :st')->setParameter('st', 'Disponible')
            ->andWhere('pRec.categorie_produit != :med')->setParameter('med', 'Médicaments')
            ->andWhere('pRec.id_produit NOT IN (:seedIds2)')->setParameter('seedIds2', $seedProductIds)
            ->groupBy('pRec.id_produit')
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit * 4);

        if ($excludeIds !== []) {
            $qb->andWhere('pRec.id_produit NOT IN (:ex)')->setParameter('ex', $excludeIds);
        }

        /** @var array<int, mixed> $rows */
        $rows = $qb->getQuery()->getResult();

        return $this->uniqueProduitsFromRows($rows, $limit);
    }

    /* ==========================================================
       ✅ FIX WARNING : ORDER BY sans LIMIT (historique commandes)
       ========================================================== */

    /**
     * ✅ Commandes d’un user (paginées)
     *
     * @return Commande[]
     */
    public function findUserOrdersPaginated(int $userId, int $page = 1, int $pageSize = 10): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));

        return $this->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.user) = :u')
            ->setParameter('u', $userId)
            ->orderBy('c.date_creation_commande', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
    }

    public function countUserOrders(int $userId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id_commande)')
            ->andWhere('IDENTITY(c.user) = :u')
            ->setParameter('u', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }




    
}

