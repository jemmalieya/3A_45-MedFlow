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
     *
     * @param string|null $search Recherche par nom, description, ou catégorie
     * @param string|null $category Filtrage par catégorie
     * @param string|null $sortPrice Tri par prix (ascendant ou descendant)
     * @param string|null $sortStock Tri par stock (ascendant ou descendant)
     *
     * @return array Liste des produits filtrés et triés
     */
    public function findFiltered(?string $search = null, ?string $category = null, ?string $sortPrice = null, ?string $sortStock = null): array
    {
        // Création du QueryBuilder
        $qb = $this->createQueryBuilder('p');
    
        // Recherche (nom, description, catégorie)
        if ($search && trim($search) !== '') {
            $qb->andWhere('LOWER(p.nom_produit) LIKE :search 
                        OR LOWER(p.description_produit) LIKE :search 
                        OR LOWER(p.categorie_produit) LIKE :search')
               ->setParameter('search', '%' . strtolower(trim($search)) . '%');
        }
    
        // Filtrage par catégorie
        if ($category && trim($category) !== '') {
            $qb->andWhere('p.categorie_produit = :category')
               ->setParameter('category', $category);
        }
    
        // Tri par prix (ascendant ou descendant)
        if ($sortPrice === 'asc') {
            $qb->orderBy('p.prix_produit', 'ASC');
        } elseif ($sortPrice === 'desc') {
            $qb->orderBy('p.prix_produit', 'DESC');
        } else {
            // Tri par nom par défaut
            $qb->orderBy('p.nom_produit', 'ASC');
        }
    
        // Tri par stock (ascendant ou descendant)
        if ($sortStock === 'asc') {
            $qb->addOrderBy('p.quantite_produit', 'ASC');
        } elseif ($sortStock === 'desc') {
            $qb->addOrderBy('p.quantite_produit', 'DESC');
        }
    
        // Exécuter la requête et retourner les résultats
        return $qb->getQuery()->getResult();
    }
    

    /**
     * Récupère toutes les catégories distinctes des produits
     *
     * @return array Liste des catégories distinctes
     */
    public function findAllCategories(): array
    {
        // Création du QueryBuilder pour récupérer toutes les catégories distinctes
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.categorie_produit')  // Sélectionner les catégories distinctes
            ->where('p.categorie_produit IS NOT NULL')  // Ignorer les catégories nulles
            ->orderBy('p.categorie_produit', 'ASC')  // Trier les catégories par ordre croissant
            ->getQuery()
            ->getResult();

        // Retourner les catégories sous forme de tableau
        return array_column($result, 'categorie_produit');
    }
}
