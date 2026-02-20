<?php

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CommandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commande::class);
    }

    /**
     * ✅ Best sellers : 1 produit par catégorie (diversité maximale)
     */
    public function getBestSellersNonMedicaments(int $limit = 12): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT lc, p, c.id_commande, SUM(lc.quantite_commandee) AS score
             FROM App\Entity\LigneCommande lc
             JOIN lc.commande c
             JOIN lc.produit p
             WHERE c.statut_commande != :annule
             AND p.status_produit = :st
             AND p.categorie_produit != :med
             GROUP BY p.id_produit, lc.id_ligne_commande, c.id_commande
             ORDER BY score DESC'
        )
        ->setParameter('annule', 'Annulée')
        ->setParameter('st', 'Disponible')
        ->setParameter('med', 'Médicaments')
        ->getResult();

        $produits = [];
        $categoriesVues = [];

        foreach ($rows as $item) {
            $produit = $item[0]->getProduit(); // item[0] = LigneCommande
            if (!$produit) continue;

            $categorie = $produit->getCategorieProduit();

            if (!in_array($categorie, $categoriesVues, true)) {
                $categoriesVues[] = $categorie;
                $produits[] = $produit;

                if (count($produits) >= $limit) break;
            }
        }

        return $produits;
    }

    /**
     * ✅ Best sellers global (fallback)
     */
    public function getBestSellersGlobal(int $limit = 12): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lc')
            ->innerJoin('lc.commande', 'c')
            ->innerJoin('lc.produit', 'p')
            ->select('lc, p, SUM(lc.quantite_commandee) AS HIDDEN score')
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->groupBy('p.id_produit')
            ->addGroupBy('lc.id_ligne_commande')
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $produits = [];
        $ids = [];

        foreach ($rows as $item) {
            $produit = $item->getProduit();
            if ($produit && !in_array($produit->getId_produit(), $ids, true)) {
                $ids[] = $produit->getId_produit();
                $produits[] = $produit;
            }
        }

        return $produits;
    }

    /**
     * ✅ Historique user : ids produits déjà achetés
     */
    public function getUserPurchasedProductIds(int $userId, int $limit = 200): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lc')
            ->innerJoin('lc.commande', 'c')
            ->select('DISTINCT IDENTITY(lc.produit) AS id')
            ->andWhere('c.id_user = :u')->setParameter('u', $userId)
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->orderBy('c.date_creation_commande', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    /**
     * ✅ Top catégories achetées par user
     */
    public function getUserTopCategories(int $userId, int $limit = 3): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lc')
            ->innerJoin('lc.commande', 'c')
            ->innerJoin('lc.produit', 'p')
            ->select('p.categorie_produit AS categorie, SUM(lc.quantite_commandee) AS score')
            ->andWhere('c.id_user = :u')->setParameter('u', $userId)
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->groupBy('p.categorie_produit')
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(fn($r) => (string) $r['categorie'], $rows);
    }

    /**
     * ✅ NOUVEAU : produit le plus acheté par user (id)
     */
    public function getUserTopProductId(int $userId): ?int
    {
        $row = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lc')
            ->innerJoin('lc.commande', 'c')
            ->select('IDENTITY(lc.produit) AS id, SUM(lc.quantite_commandee) AS score')
            ->andWhere('c.id_user = :u')->setParameter('u', $userId)
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->groupBy('id')
            ->orderBy('score', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row ? (int) $row['id'] : null;
    }

    /**
     * ✅ Co-occurrence : "les gens qui achètent X achètent aussi Y"
     */
    public function getCoOccurringProducts(array $seedProductIds, array $excludeIds = [], int $limit = 12): array
    {
        if (empty($seedProductIds)) return [];

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lcRec')
            ->innerJoin('lcRec.commande', 'c')
            ->innerJoin('c.ligne_commandes', 'lcSeed')
            ->innerJoin('lcRec.produit', 'pRec')
            ->select('lcRec, pRec, SUM(lcRec.quantite_commandee) AS HIDDEN score')
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->andWhere('IDENTITY(lcSeed.produit) IN (:seedIds)')->setParameter('seedIds', $seedProductIds)
            ->andWhere('pRec.status_produit = :st')->setParameter('st', 'Disponible')
            ->andWhere('pRec.categorie_produit != :med')->setParameter('med', 'Médicaments')
            ->andWhere('pRec.id_produit NOT IN (:seedIds2)')->setParameter('seedIds2', $seedProductIds)
            ->groupBy('pRec.id_produit')
            ->addGroupBy('lcRec.id_ligne_commande')
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit);

        if (!empty($excludeIds)) {
            $qb->andWhere('pRec.id_produit NOT IN (:ex)')->setParameter('ex', $excludeIds);
        }

        $rows = $qb->getQuery()->getResult();

        $produits = [];
        $ids = [];

        foreach ($rows as $item) {
            $produit = $item->getProduit();
            if ($produit && !in_array($produit->getId_produit(), $ids, true)) {
                $ids[] = $produit->getId_produit();
                $produits[] = $produit;
            }
        }

        return $produits;
    }

    
}