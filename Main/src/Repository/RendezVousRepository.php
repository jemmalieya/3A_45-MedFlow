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
        /**
         * @return RendezVous[]
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
    /**
     * @return RendezVous[]
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
    /**
     * @param int|\App\Entity\User $doctor
     * @return RendezVous[]
     */
    public function findTodayAppointmentsForDoctor(\App\Entity\User|int $doctor, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.staff = :staff')
            ->andWhere('r.datetime >= :start')
            ->andWhere('r.datetime < :end')
            ->setParameter('staff', $doctor)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('r.datetime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
    /**
     * Find today's appointments for a given doctor (staff)
     */
    
