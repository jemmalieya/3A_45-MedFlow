<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
    // src/Repository/UserRepository.php
public function findPatientsWithFilters(array $filters = []): array
{
    $qb = $this->createQueryBuilder('u')
        ->andWhere('u.roleSysteme = :role')
        ->setParameter('role', 'PATIENT');

    if (!empty($filters['q'])) {
        $qb->andWhere('u.cin LIKE :q OR u.nom LIKE :q OR u.prenom LIKE :q OR u.emailUser LIKE :q')
           ->setParameter('q', '%'.$filters['q'].'%');
    }

    if (isset($filters['verified'])) {
        $qb->andWhere('u.isVerified = :v')
           ->setParameter('v', $filters['verified']);
    }

    return $qb->orderBy('u.id', 'DESC')->getQuery()->getResult();
}

}
