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
        ->andWhere('r.id_user = :uid') // ⚠️ si chez toi c'est r.user -> voir note plus bas
        ->setParameter('uid', $userId);

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


    //    /**
    //     * @return Reclamation[] Returns an array of Reclamation objects
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
