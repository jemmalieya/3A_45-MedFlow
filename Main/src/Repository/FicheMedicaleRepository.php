<?php

namespace App\Repository;

use App\Entity\FicheMedicale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FicheMedicale>
 */
class FicheMedicaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FicheMedicale::class);
    }
    /**
     * Find all FicheMedicales related to RendezVous of a specific staff member
     */
    public function findFichesByStaffId(int $idStaff): array
    {
        // Fetch User entity for staff
        $entityManager = $this->getEntityManager();
        $staffUser = $entityManager->getRepository(\App\Entity\User::class)->find($idStaff);
        return $this->createQueryBuilder('f')
            ->innerJoin('f.rendezVous', 'r')
            ->andWhere('r.staff = :staff')
            ->setParameter('staff', $staffUser)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult()
        ;
    }


    //    /**
    //     * @return FicheMedicale[] Returns an array of FicheMedicale objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('f')
    //            ->andWhere('f.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('f.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?FicheMedicale
    //    {
    //        return $this->createQueryBuilder('f')
    //            ->andWhere('f.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
