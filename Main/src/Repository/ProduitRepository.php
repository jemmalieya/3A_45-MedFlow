<?php

namespace App\Repository;

use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    public function findFiltered(?string $search = null, ?string $category = null, ?string $sortPrice = null): array
    {
        $qb = $this->createQueryBuilder('p');

        if ($search && trim($search) !== '') {
            $s = '%' . mb_strtolower(trim($search)) . '%';
            $qb->andWhere('LOWER(p.nom_produit) LIKE :s OR LOWER(p.description_produit) LIKE :s OR LOWER(p.categorie_produit) LIKE :s')
               ->setParameter('s', $s);
        }

        if ($category && trim($category) !== '') {
            $qb->andWhere('p.categorie_produit = :cat')
               ->setParameter('cat', trim($category));
        }

        if ($sortPrice === 'asc') {
            $qb->orderBy('p.prix_produit', 'ASC');
        } elseif ($sortPrice === 'desc') {
            $qb->orderBy('p.prix_produit', 'DESC');
        } else {
            $qb->orderBy('p.nom_produit', 'ASC');
        }

        return $qb->getQuery()->getResult();
    }

    public function findAllCategories(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.categorie_produit AS categorie')
            ->where('p.categorie_produit IS NOT NULL')
            ->orderBy('categorie', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(fn($r) => (string)$r['categorie'], $rows);
    }

    /**
     * ✅ fallback "AI-like" : produits similaires (hors médicaments)
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
            $qb->andWhere('p.id_produit NOT IN (:ex)')->setParameter('ex', $excludeIds);
        }

        if (!empty($preferredCategories)) {
            $qb->andWhere('p.categorie_produit IN (:cats)')->setParameter('cats', $preferredCategories);
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

    public function findByCategoriesExcludingIds(array $categories, array $excludeIds = [], int $limit = 12): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.status_produit = :st')->setParameter('st', 'Disponible')
            ->andWhere('p.categorie_produit != :med')->setParameter('med', 'Médicaments');

        if (!empty($categories)) {
            $qb->andWhere('p.categorie_produit IN (:cats)')->setParameter('cats', $categories);
        }

        if (!empty($excludeIds)) {
            $qb->andWhere('p.id_produit NOT IN (:ex)')->setParameter('ex', $excludeIds);
        }

        return $qb->orderBy('p.id_produit', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * ✅ NOUVEAU : produits non-médicaments d'une catégorie (sans exclure)
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
}