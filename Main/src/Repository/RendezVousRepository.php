<?php

namespace App\Repository;

use App\Entity\RendezVous;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RendezVous>
 */
class RendezVousRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RendezVous::class);
    }

    /**
     * Find all active RendezVous for a given staff (doctor) id
     */
    public function findActiveByStaff(int $idStaff): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.staff = :staff')
            ->andWhere('r.statut != :statut')
            ->setParameter('staff', $idStaff)
            ->setParameter('statut', 'Terminé')
            ->orderBy('r.datetime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
