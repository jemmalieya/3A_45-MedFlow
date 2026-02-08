<?php

namespace App\Repository;

use App\Entity\Ressource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ressource>
 */
class RessourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ressource::class);
    }

    //    /**
    //     * @return Ressource[] Returns an array of Ressource objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Ressource
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }


    public function getKpiStats(): array
{
    $qb = $this->createQueryBuilder('r')
        ->select("
            COUNT(r.id) as total,
            SUM(CASE WHEN r.type_ressource = 'file' THEN 1 ELSE 0 END) as files,
            SUM(CASE WHEN r.type_ressource = 'external_link' THEN 1 ELSE 0 END) as links,
            SUM(CASE WHEN r.type_ressource = 'stock_item' THEN 1 ELSE 0 END) as stock,
            SUM(CASE WHEN r.est_publique_ressource = true THEN 1 ELSE 0 END) as publiques,
            SUM(CASE WHEN r.est_publique_ressource = false THEN 1 ELSE 0 END) as privees
        ");

    return $qb->getQuery()->getSingleResult();
}

public function countByType(): array
{
    return $this->createQueryBuilder('r')
        ->select("r.type_ressource as label, COUNT(r.id) as value")
        ->groupBy('r.type_ressource')
        ->orderBy('value', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

public function countByCategorie(): array
{
    return $this->createQueryBuilder('r')
        ->select("COALESCE(r.categorie_ressource, 'Sans catégorie') as label, COUNT(r.id) as value")
        ->groupBy('r.categorie_ressource')
        ->orderBy('value', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

public function topEvenementsByRessources(int $limit = 5): array
{
    return $this->createQueryBuilder('r')
        ->join('r.evenement', 'e')
        ->select("e.titre_event as label, COUNT(r.id) as value")
        ->groupBy('e.id')
        ->orderBy('value', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getArrayResult();
}

}
