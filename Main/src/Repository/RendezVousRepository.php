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
         * Find the next upcoming RendezVous for a given patient id
         */
        public function findNextUpcomingByPatient(int $patientId): ?RendezVous
        {
            return $this->createQueryBuilder('r')
                ->andWhere('r.patient = :patient')
                ->andWhere('r.datetime > :now')
                ->setParameter('patient', $patientId)
                ->setParameter('now', new \DateTime())
                ->orderBy('r.datetime', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        /**
         * Find all RendezVous for a given patient id
         */
        public function findByPatient(int $patientId): array
        {
            return $this->createQueryBuilder('r')
                ->andWhere('r.patient = :patient')
                ->setParameter('patient', $patientId)
                ->orderBy('r.datetime', 'DESC')
                ->getQuery()
                ->getResult();
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
