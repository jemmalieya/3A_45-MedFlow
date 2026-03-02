<?php

namespace App\Repository;

use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Produit>
 */
class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    /* ============================================================
     * FRONT - Recherche / filtres / tri + PAGINATION
     * ============================================================ */

    /**
     * ✅ QueryBuilder FRONT (filtres + tri)
     * (utile si un jour tu utilises KnpPaginator côté front)
     */
    public function qbFrontFiltered(
        ?string $search = null,
        ?string $category = null,
        ?string $sortPrice = null,
        ?string $sortStock = null
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('p');

        // Search
        if ($search !== null && trim($search) !== '') {
            $s = '%' . mb_strtolower(trim($search)) . '%';
            $qb->andWhere(
                'LOWER(p.nom_produit) LIKE :s
                 OR LOWER(p.description_produit) LIKE :s
                 OR LOWER(p.categorie_produit) LIKE :s'
            )->setParameter('s', $s);
        }

        // Category filter
        if ($category !== null && trim($category) !== '') {
            $qb->andWhere('p.categorie_produit = :cat')
               ->setParameter('cat', trim($category));
        }

        // Sort by price (or default by name)
        if ($sortPrice === 'asc') {
            $qb->orderBy('p.prix_produit', 'ASC');
        } elseif ($sortPrice === 'desc') {
            $qb->orderBy('p.prix_produit', 'DESC');
        } else {
            $qb->orderBy('p.nom_produit', 'ASC');
        }

        // Secondary sort by stock
        if ($sortStock === 'asc') {
            $qb->addOrderBy('p.quantite_produit', 'ASC');
        } elseif ($sortStock === 'desc') {
            $qb->addOrderBy('p.quantite_produit', 'DESC');
        }

        return $qb;
    }

    /**
     * ✅ FRONT : liste paginée (Doctrine Doctor OK)
     *
     * @return Produit[]
     */
    public function findFiltered(
        ?string $search = null,
        ?string $category = null,
        ?string $sortPrice = null,
        ?string $sortStock = null,
        int $page = 1,
        int $pageSize = 12
    ): array {
        $qb = $this->qbFrontFiltered($search, $category, $sortPrice, $sortStock);

        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $offset = ($page - 1) * $pageSize;

        return $qb
            ->setFirstResult($offset)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
    }

    /**
     * ✅ FRONT : total (pour pagination)
     */
    public function countFiltered(?string $search = null, ?string $category = null): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id_produit)');

        if ($search !== null && trim($search) !== '') {
            $s = '%' . mb_strtolower(trim($search)) . '%';
            $qb->andWhere(
                'LOWER(p.nom_produit) LIKE :s
                 OR LOWER(p.description_produit) LIKE :s
                 OR LOWER(p.categorie_produit) LIKE :s'
            )->setParameter('s', $s);
        }

        if ($category !== null && trim($category) !== '') {
            $qb->andWhere('p.categorie_produit = :cat')
               ->setParameter('cat', trim($category));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

       /**
     * ✅ Récupère toutes les catégories distinctes (limitée)
     *
     * @return list<string>
     */
    public function findAllCategories(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        /** @var array<int, array{categorie: mixed}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.categorie_produit AS categorie')
            ->where('p.categorie_produit IS NOT NULL')
            ->orderBy('categorie', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $c = $r['categorie'] ?? null;
            if (is_string($c) && $c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * ✅ Compter produits disponibles
     */
    public function countAvailableProducts(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id_produit)')
            ->andWhere('p.status_produit = :st')
            ->setParameter('st', 'Disponible')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * ✅ Compter produits en rupture (évite findAll())
     */
    public function countRuptureProducts(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id_produit)')
            ->andWhere('LOWER(p.status_produit) = :st')
            ->setParameter('st', 'rupture')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * ✅ Compter produits stock faible (<= seuil)
     */
    public function countLowStockProducts(int $threshold = 10): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id_produit)')
            ->andWhere('p.quantite_produit IS NOT NULL')
            ->andWhere('p.quantite_produit <= :t')
            ->setParameter('t', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * ✅ Liste produits en rupture (limitée)
     *
     * @return Produit[]
     */
    public function findRuptureProducts(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->createQueryBuilder('p')
            ->andWhere('LOWER(p.status_produit) = :st')
            ->setParameter('st', 'rupture')
            ->orderBy('p.quantite_produit', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * ✅ Produits à stock faible (<= seuil) (limitée)
     *
     * @return Produit[]
     */
    public function findLowStockProducts(int $threshold = 10, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->createQueryBuilder('p')
            ->andWhere('p.quantite_produit IS NOT NULL')
            ->andWhere('p.quantite_produit <= :t')
            ->setParameter('t', $threshold)
            ->orderBy('p.quantite_produit', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /* ============================================================
     * RECO / SIMILAIRES (déjà OK car setMaxResults)
     * ============================================================ */

    /**
     * ✅ fallback "AI-like" : produits similaires (hors médicaments)
     *
     * @param string[] $preferredCategories
     * @param float|null $targetPrice
     * @param int[] $excludeIds
     * @return Produit[]
     */
    public function findSimilarNonMedicaments(
        array $preferredCategories,
        ?float $targetPrice,
        array $excludeIds = [],
        int $limit = 12
    ): array {
        $limit = max(1, min(50, $limit));

        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->andWhere('p.categorie_produit != :med')->setParameter('med', 'Médicaments');

        if (!empty($excludeIds)) {
            $qb->andWhere('p.id_produit NOT IN (:ex)')
               ->setParameter('ex', $excludeIds);
        }

        if (!empty($preferredCategories)) {
            $qb->andWhere('p.categorie_produit IN (:cats)')
               ->setParameter('cats', $preferredCategories);
        }

        if ($targetPrice !== null) {
            $qb->addSelect('ABS(p.prix_produit - :tp) AS HIDDEN priceDiff')
               ->setParameter('tp', $targetPrice)
               ->orderBy('priceDiff', 'ASC');
        } else {
            $qb->orderBy('p.id_produit', 'DESC');
        }

        return $qb->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * ✅ Produits par catégories (hors médicaments) en excluant des ids
     *
     * @param string[] $categories
     * @param int[] $excludeIds
     * @return Produit[]
     */
    public function findByCategoriesExcludingIds(
        array $categories,
        array $excludeIds = [],
        int $limit = 12
    ): array {
        $limit = max(1, min(50, $limit));

        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->andWhere('p.categorie_produit != :med')->setParameter('med', 'Médicaments');

        if (!empty($categories)) {
            $qb->andWhere('p.categorie_produit IN (:cats)')
               ->setParameter('cats', $categories);
        }

        if (!empty($excludeIds)) {
            $qb->andWhere('p.id_produit NOT IN (:ex)')
               ->setParameter('ex', $excludeIds);
        }

        return $qb->orderBy('p.id_produit', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * ✅ Produits non-médicaments d'une catégorie
     *
     * @return Produit[]
     */
    public function findByCategoryNonMedicaments(string $category, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));

        return $this->createQueryBuilder('p')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->andWhere('p.categorie_produit = :cat')->setParameter('cat', $category)
            ->andWhere('p.categorie_produit != :med')->setParameter('med', 'Médicaments')
            ->orderBy('p.id_produit', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /* ============================================================
     * ADMIN - QB déjà paginé via KNP
     * ============================================================ */

    /**
     * ✅ QueryBuilder pour liste admin (pagination, filtres back-office, etc.)
     */
    public function qbAdminList(?string $search = null, ?string $category = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.id_produit', 'DESC');

        if ($search && trim($search) !== '') {
            $q = '%' . mb_strtolower(trim($search)) . '%';
            $qb->andWhere('LOWER(p.nom_produit) LIKE :q OR LOWER(p.categorie_produit) LIKE :q')
               ->setParameter('q', $q);
        }

        if ($category && trim($category) !== '') {
            $qb->andWhere('p.categorie_produit = :cat')
               ->setParameter('cat', trim($category));
        }

        return $qb;
    }
}