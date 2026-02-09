<?php

namespace App\Repository;

use App\Entity\Reclamation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reclamation>
 */
class ReclamationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reclamation::class);
    }
    public function searchFront(
    int $userId,
    ?string $q,
    ?string $type,
    ?string $statut,
    ?string $sort,
    ?string $dir
): array {
   $qb = $this->createQueryBuilder('r')
    ->andWhere('r.user = :user')
    ->setParameter('user', $userId); // objet User


    // ✅ Recherche texte (contenu / description)
    if ($q && trim($q) !== '') {
        $q = mb_strtolower(trim($q));
        $qb->andWhere('LOWER(r.contenu) LIKE :q OR LOWER(r.description) LIKE :q')
           ->setParameter('q', "%$q%");
    }

    // ✅ Filtre type
    if ($type && trim($type) !== '') {
        $qb->andWhere('r.type = :type')
           ->setParameter('type', trim($type));
    }

    // ✅ Filtre statut
    if ($statut && trim($statut) !== '') {
        $qb->andWhere('r.statut = :statut')
           ->setParameter('statut', trim($statut));
    }

    // ✅ Tri sécurisé (whitelist)
    $allowedSort = ['date_creation_r', 'type', 'statut', 'contenu'];
    $sort = in_array($sort, $allowedSort, true) ? $sort : 'date_creation_r';

    $dir = strtoupper($dir ?? 'DESC');
    $dir = in_array($dir, ['ASC', 'DESC'], true) ? $dir : 'DESC';

    return $qb->orderBy('r.' . $sort, $dir)
              ->getQuery()
              ->getResult();
}
public function getReclamKpis(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    $row = $this->createQueryBuilder('r')
        ->select('COUNT(r.id_reclamation) AS total')
        ->addSelect("SUM(CASE WHEN r.statutReclamation = 'TRAITEE' THEN 1 ELSE 0 END) AS traitees")
        ->addSelect("SUM(CASE WHEN r.statutReclamation <> 'TRAITEE' THEN 1 ELSE 0 END) AS enAttente")
        ->andWhere('r.date_creation_r BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->getQuery()
        ->getSingleResult();

    return [
        'total' => (int) $row['total'],
        'traitees' => (int) $row['traitees'],
        'enAttente' => (int) $row['enAttente'],
    ];
}

public function countReclamByType(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    return $this->createQueryBuilder('r')
        ->select("COALESCE(r.type, 'Non défini') AS label, COUNT(r.id_reclamation) AS total")
        ->andWhere('r.date_creation_r BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->groupBy('label')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

public function countReclamByStatut(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    return $this->createQueryBuilder('r')
        ->select("COALESCE(r.statutReclamation, 'Non défini') AS label, COUNT(r.id_reclamation) AS total")
        ->andWhere('r.date_creation_r BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->groupBy('label')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

public function countReclamByPriorite(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    return $this->createQueryBuilder('r')
        ->select("COALESCE(r.priorite, 'Non défini') AS label, COUNT(r.id_reclamation) AS total")
        ->andWhere('r.date_creation_r BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->groupBy('label')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();
}
public function countReclamByDay(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    $rows = $this->createQueryBuilder('r')
        ->select('r.date_creation_r AS d')
        ->andWhere('r.date_creation_r BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->getQuery()
        ->getArrayResult();

    $map = [];
    foreach ($rows as $row) {
        $dt = $row['d'];
        $day = ($dt instanceof \DateTimeInterface)
            ? $dt->format('Y-m-d')
            : (new \DateTimeImmutable((string)$dt))->format('Y-m-d');

        $map[$day] = ($map[$day] ?? 0) + 1;
    }

    ksort($map);

    $result = [];
    foreach ($map as $day => $total) {
        $result[] = ['day' => $day, 'total' => $total];
    }
    return $result;
}
public function findFiltered(array $filters = []): array
{
    $q    = trim((string)($filters['q'] ?? ''));
    $sort = (string)($filters['sort'] ?? 'date_creation_r');
    $dir  = strtoupper((string)($filters['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    // ✅ champs autorisés
    $allowedSort = [
        'date_creation_r' => 'r.date_creation_r',
        'contenu'         => 'r.contenu',
        'type'            => 'r.type',
        'statut_reclamation' => 'r.statutReclamation',
        'priorite'        => 'r.priorite',
    ];

    $orderBy = $allowedSort[$sort] ?? 'r.date_creation_r';

    $qb = $this->createQueryBuilder('r');

    // 🔎 recherche
    if ($q !== '') {
        $qb->andWhere('LOWER(r.contenu) LIKE :q OR LOWER(r.description) LIKE :q OR LOWER(r.referenceReclamation) LIKE :q')
           ->setParameter('q', '%'.mb_strtolower($q).'%');
    }

     return $qb
        ->orderBy($orderBy, $dir)
        ->getQuery()
        ->getResult();
}


    //    /**
    //     * @return Reclamation[] Returns an array of Reclamation objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id_reclamation_reclamation', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Reclamation
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
