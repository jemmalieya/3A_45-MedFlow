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
     * Recherche et tri des produits
     */
    public function findFiltered(?string $search = null, ?string $category = null, ?string $sortPrice = null): array
    {
        $qb = $this->createQueryBuilder('p');

        // Recherche (utilise les noms de propriétés, pas les noms de colonnes)
        if ($search && trim($search) !== '') {
            $qb->andWhere('LOWER(p.nom_produit) LIKE :search 
                        OR LOWER(p.description_produit) LIKE :search 
                        OR LOWER(p.categorie_produit) LIKE :search')
               ->setParameter('search', '%' . strtolower(trim($search)) . '%');
        }

        // Filtre par catégorie
        if ($category && trim($category) !== '') {
            $qb->andWhere('p.categorie_produit = :category')
               ->setParameter('category', $category);
        }

        // Tri par prix
        if ($sortPrice === 'asc') {
            $qb->orderBy('p.prix_produit', 'ASC');
        } elseif ($sortPrice === 'desc') {
            $qb->orderBy('p.prix_produit', 'DESC');
        } else {
            $qb->orderBy('p.nom_produit', 'ASC');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère toutes les catégories distinctes
     */
    public function findAllCategories(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.categorie_produit')
            ->where('p.categorie_produit IS NOT NULL')
            ->orderBy('p.categorie_produit', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'categorie_produit');
    }
}