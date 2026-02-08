<?php

namespace App\Repository;

use App\Entity\Evenement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evenement>
 */
class EvenementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evenement::class);
    }

    //    /**
    //     * @return Evenement[] Returns an array of Evenement objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('e.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Evenement
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function findAllSortedByStatutCustom(): array
{
    // ordre métier: Publié -> En cours -> Brouillon -> Annulé
    return $this->createQueryBuilder('e')
        ->addSelect("
            CASE
                WHEN e.statutEvent = 'Publié' THEN 1
                WHEN e.statutEvent = 'En cours' THEN 2
                WHEN e.statutEvent = 'Brouillon' THEN 3
                WHEN e.statutEvent = 'Annulé' THEN 4
                ELSE 5
            END AS HIDDEN statutOrder
        ")
        ->orderBy('statutOrder', 'ASC')
        ->addOrderBy('e.dateDebutEvent', 'DESC')
        ->getQuery()
        ->getResult();
}

}
