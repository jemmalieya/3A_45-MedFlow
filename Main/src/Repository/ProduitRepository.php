<?php

namespace App\Repository;

use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    /**
     * ✅ Recherche + filtres + tri (prix + stock)
     *
     * @param string|null $search Recherche par nom, description, ou catégorie
     * @param string|null $category Filtrage par catégorie
     * @param string|null $sortPrice Tri par prix: asc | desc
     * @param string|null $sortStock Tri par stock: asc | desc
     *
     * @return Produit[]
     */
    public function findFiltered(
        ?string $search = null,
        ?string $category = null,
        ?string $sortPrice = null,
        ?string $sortStock = null
    ): array {
        $qb = $this->createQueryBuilder('p');

        // ✅ Search
        if ($search && trim($search) !== '') {
            $s = '%' . mb_strtolower(trim($search)) . '%';
            $qb->andWhere(
                'LOWER(p.nom_produit) LIKE :s
                 OR LOWER(p.description_produit) LIKE :s
                 OR LOWER(p.categorie_produit) LIKE :s'
            )->setParameter('s', $s);
        }

        // ✅ Category filter
        if ($category && trim($category) !== '') {
            $qb->andWhere('p.categorie_produit = :cat')
               ->setParameter('cat', trim($category));
        }

        // ✅ Sort by price (or default by name)
        if ($sortPrice === 'asc') {
            $qb->orderBy('p.prix_produit', 'ASC');
        } elseif ($sortPrice === 'desc') {
            $qb->orderBy('p.prix_produit', 'DESC');
        } else {
            $qb->orderBy('p.nom_produit', 'ASC');
        }

        // ✅ Sort by stock (secondary sort)
        if ($sortStock === 'asc') {
            $qb->addOrderBy('p.quantite_produit', 'ASC');
        } elseif ($sortStock === 'desc') {
            $qb->addOrderBy('p.quantite_produit', 'DESC');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * ✅ Récupère toutes les catégories distinctes des produits
     *
     * @return string[]
     */
    public function findAllCategories(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.categorie_produit AS categorie')
            ->where('p.categorie_produit IS NOT NULL')
            ->orderBy('categorie', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(fn ($r) => (string) $r['categorie'], $rows);
    }

    /**
     * ✅ fallback "AI-like" : produits similaires (hors médicaments)
     * - privilégie catégories préférées
     * - option : proche d'un prix cible
     * - exclut certains produits
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
     * ✅ Produits non-médicaments d'une catégorie (sans exclure)
     *
     * @return Produit[]
     */
    public function findByCategoryNonMedicaments(string $category, int $limit = 12): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->andWhere('p.categorie_produit = :cat')->setParameter('cat', $category)
            ->andWhere('p.categorie_produit != :med')->setParameter('med', 'Médicaments')
            ->orderBy('p.id_produit', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * ✅ QueryBuilder pour liste admin (pagination, filtres back-office, etc.)
     * (on retourne le QB, pas un tableau)
     */
    public function qbAdminList(?string $search = null, ?string $category = null)
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