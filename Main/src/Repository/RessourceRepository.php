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

/**
 * @return array{
 *   total:int|string|null,
 *   files:int|string|null,
 *   links:int|string|null,
 *   stock:int|string|null,
 *   publiques:int|string|null,
 *   privees:int|string|null
 * }
 */
public function getKpiStats(): array
{
    /** @var array{
     *   total:int|string|null,
     *   files:int|string|null,
     *   links:int|string|null,
     *   stock:int|string|null,
     *   publiques:int|string|null,
     *   privees:int|string|null
     * } $res
     */
    $res = $this->createQueryBuilder('r')
        ->select("
            COUNT(r.id) as total,
            SUM(CASE WHEN r.type_ressource = 'file' THEN 1 ELSE 0 END) as files,
            SUM(CASE WHEN r.type_ressource = 'external_link' THEN 1 ELSE 0 END) as links,
            SUM(CASE WHEN r.type_ressource = 'stock_item' THEN 1 ELSE 0 END) as stock,
            SUM(CASE WHEN r.est_publique_ressource = true THEN 1 ELSE 0 END) as publiques,
            SUM(CASE WHEN r.est_publique_ressource = false THEN 1 ELSE 0 END) as privees
        ")
        ->getQuery()
        ->getSingleResult();

    return $res;
}

/**
 * @return list<array{label:string, value:int|string}>
 */
public function countByType(): array
{
    /** @var list<array{label:string, value:int|string}> $res */
    $res = $this->createQueryBuilder('r')
        ->select("r.type_ressource as label, COUNT(r.id) as value")
        ->groupBy('r.type_ressource')
        ->orderBy('value', 'DESC')
        ->getQuery()
        ->getArrayResult();

    return $res;
}

/**
 * @return list<array{label:string, value:int|string}>
 */
public function countByCategorie(): array
{
    /** @var list<array{label:string, value:int|string}> $res */
    $res = $this->createQueryBuilder('r')
        ->select("COALESCE(r.categorie_ressource, 'Sans catégorie') as label, COUNT(r.id) as value")
        ->groupBy('r.categorie_ressource')
        ->orderBy('value', 'DESC')
        ->getQuery()
        ->getArrayResult();

    return $res;
}

/**
 * @return list<array{label:string, value:int|string}>
 */
public function topEvenementsByRessources(int $limit = 5): array
{
    /** @var list<array{label:string, value:int|string}> $res */
    $res = $this->createQueryBuilder('r')
        ->join('r.evenement', 'e')
        ->select("e.titre_event as label, COUNT(r.id) as value")
        ->groupBy('e.id')
        ->orderBy('value', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getArrayResult();

    return $res;
}

// src/Repository/RessourceRepository.php

/**
 * @param array<string, 'ASC'|'DESC'> $orderBy
 * @return list<Ressource>
 */
public function searchAdmin(string $term, array $orderBy = []): array
{
    $term = trim($term);
    $qb = $this->createQueryBuilder('r');

    if ($term !== '') {
        $qb->andWhere('LOWER(r.nom_ressource) LIKE :t
                OR LOWER(r.categorie_ressource) LIKE :t
                OR LOWER(r.type_ressource) LIKE :t
                OR LOWER(COALESCE(r.notes_ressource, \'\')) LIKE :t
                OR LOWER(COALESCE(r.url_externe_ressource, \'\')) LIKE :t
                OR LOWER(COALESCE(r.chemin_fichier_ressource, \'\')) LIKE :t
        ')
        ->setParameter('t', '%' . mb_strtolower($term) . '%');
    }

    $allowed = [
        'date_creation_ressource' => 'r.date_creation_ressource',
        'nom_ressource'           => 'r.nom_ressource',
        'categorie_ressource'     => 'r.categorie_ressource',
        'type_ressource'          => 'r.type_ressource',
    ];

    foreach ($orderBy as $field => $dir) {
        if (!isset($allowed[$field])) continue;
        $dir = strtoupper((string) $dir) === 'ASC' ? 'ASC' : 'DESC';
        $qb->addOrderBy($allowed[$field], $dir);
    }

    if (!$orderBy) {
        $qb->addOrderBy('r.date_creation_ressource', 'DESC');
    }

    /** @var list<Ressource> $res */
    $res = $qb->getQuery()->getResult();

    return $res;
}
/**
 * @param array<string, 'ASC'|'DESC'> $orderBy
 * @return list<Ressource>
 */
public function searchAdminPaginated(string $search, array $orderBy, int $limit, int $offset): array
{
    $qb = $this->createQueryBuilder('r')
        ->leftJoin('r.evenement', 'e')
        ->addSelect('e');

    $search = trim($search);

    if ($search !== '') {
        $qb->andWhere("
            LOWER(r.nom_ressource) LIKE :q
            OR LOWER(COALESCE(r.categorie_ressource, '')) LIKE :q
            OR LOWER(COALESCE(r.type_ressource, '')) LIKE :q
            OR LOWER(COALESCE(e.titre_event, '')) LIKE :q
        ")
        ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }

    $qb->orderBy('r.date_creation_ressource', 'DESC');

    return $qb
        ->setFirstResult($offset)
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}
public function countAdminForSearch(string $search): int
{
    $qb = $this->createQueryBuilder('r')->select('COUNT(r.id)');
    $search = trim($search);

    if ($search !== '') {
        $qb->leftJoin('r.evenement', 'e')
           ->andWhere("
                LOWER(r.nom_ressource) LIKE :q
                OR LOWER(COALESCE(r.categorie_ressource, '')) LIKE :q
                OR LOWER(COALESCE(r.type_ressource, '')) LIKE :q
                OR LOWER(COALESCE(e.titre_event, '')) LIKE :q
           ")
           ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }

    return (int) $qb->getQuery()->getSingleScalarResult();
}



}

