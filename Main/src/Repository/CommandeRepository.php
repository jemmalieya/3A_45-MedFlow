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
       Helpers (pour gérer les retours Doctrine qui peuvent être
       soit des entités, soit des tableaux d'entités)
       ========================================================== */

    private function extractProduitFromRow(mixed $row): ?Produit
    {
        // Cas 1: Doctrine retourne un tableau [0 => LigneCommande, 1 => Produit, ...]
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

        // Cas 2: Doctrine retourne directement une entité
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
        // selon ton naming (getId_produit vs getIdProduit)
        if (method_exists($produit, 'getId_produit')) {
            return (int) $produit->getId_produit();
        }
        if (method_exists($produit, 'getIdProduit')) {
            return (int) $produit->getId_produit();
        }

        // fallback (rare)
        if (method_exists($produit, 'getId')) {
            return (int) $produit->getId_produit();
        }

        return 0;
    }

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
     */
    public function getBestSellersNonMedicaments(int $limit = 12): array
    {
        // DQL (tel que tu l'avais) + traitement robuste
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

        foreach ($rows as $row) {
            $produit = $this->extractProduitFromRow($row);
            if (!$produit) {
                continue;
            }

            $categorie = method_exists($produit, 'getCategorieProduit')
                ? (string) $produit->getCategorieProduit()
                : '';

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
     */
    public function getBestSellersGlobal(int $limit = 12): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lc')
            ->innerJoin('lc.commande', 'c')
            ->innerJoin('lc.produit', 'p')
            ->select('lc', 'p', 'SUM(lc.quantite_commandee) AS HIDDEN score')
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->groupBy('p.id_produit')
            ->orderBy('score', 'DESC')
            ->setMaxResults($limit * 3) // marge pour dédoublonnage
            ->getQuery()
            ->getResult();

        return $this->uniqueProduitsFromRows($rows, $limit);
    }

    /* ==========================================================
       ✅ HISTORIQUE USER
       ========================================================== */

    /**
     * ✅ Historique user : ids produits déjà achetés
     */
    public function getUserPurchasedProductIds(int $userId, int $limit = 200): array
{
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

    return array_values(array_map(fn ($r) => (int) $r['id'], $rows));
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
        ->andWhere('IDENTITY(c.user) = :u')->setParameter('u', $userId)
        ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
        ->groupBy('p.categorie_produit')
        ->orderBy('score', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getArrayResult();

    return array_values(array_map(fn ($r) => (string) $r['categorie'], $rows));
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
            ->andWhere('IDENTITY(c.user) = :u')->setParameter('u', $userId)
            ->andWhere('c.statut_commande != :annule')->setParameter('annule', 'Annulée')
            ->groupBy('id')
            ->orderBy('score', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    
        return $row ? (int) $row['id'] : null;
    }

    /* ==========================================================
       ✅ CO-OCCURRENCE (reco personnalisée)
       ========================================================== */

    /**
     * ✅ Co-occurrence : "les gens qui achètent X achètent aussi Y"
     * $seedProductIds = produits "seed" (top produit, produits panier session, produits vus...)
     * $excludeIds = ids à exclure (déjà achetés, déjà seed, etc.)
     */
    public function getCoOccurringProducts(array $seedProductIds, array $excludeIds = [], int $limit = 12): array
    {
        if (empty($seedProductIds)) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->from(LigneCommande::class, 'lcRec')
            ->innerJoin('lcRec.commande', 'c')
            // ⚠️ garde ton mapping tel que tu l'avais : c.ligne_commandes
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
            ->setMaxResults($limit * 4); // marge pour exclure/dédoublonner

        if (!empty($excludeIds)) {
            $qb->andWhere('pRec.id_produit NOT IN (:ex)')->setParameter('ex', $excludeIds);
        }

        $rows = $qb->getQuery()->getResult();

        return $this->uniqueProduitsFromRows($rows, $limit);
    }
}