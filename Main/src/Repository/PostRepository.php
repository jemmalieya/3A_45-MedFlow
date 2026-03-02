<?php

namespace App\Repository;

use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;
/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

/**
 * @return Post[]
 */
public function search(string $q): array
{
    $q = trim($q);

    $qb = $this->createQueryBuilder('p')
        ->leftJoin('p.user', 'u')
        ->addSelect('u')
        ->leftJoin('p.commentaires', 'c')
        ->addSelect('c')
        ->leftJoin('c.user', 'cu')
        ->addSelect('cu')
        ->orderBy('p.date_creation', 'DESC');

    if ($q !== '') {
        $qb->andWhere('
            LOWER(COALESCE(p.titre, \'\')) LIKE :q OR
            LOWER(COALESCE(p.contenu, \'\')) LIKE :q OR
            LOWER(COALESCE(p.localisation, \'\')) LIKE :q OR
            LOWER(COALESCE(p.categorie, \'\')) LIKE :q OR
            LOWER(COALESCE(p.hashtags, \'\')) LIKE :q
        ')
        ->setParameter('q', '%'.mb_strtolower($q).'%');
    }

    return $qb->getQuery()->getResult();
}

/**
 * @return Post[]
 */
public function findAllSorted(string $sort): array
{
    $qb = $this->createQueryBuilder('p')
        ->leftJoin('p.user', 'u')
        ->addSelect('u')
        ->leftJoin('p.commentaires', 'c')
        ->addSelect('c')
        ->leftJoin('c.user', 'cu')
        ->addSelect('cu');

    $this->applySort($qb, $sort);

    return $qb->getQuery()->getResult();
}

/**
 * @return Post[]
 */
public function searchWithSort(string $q, string $sort): array
{
    $qb = $this->createQueryBuilder('p')
        ->leftJoin('p.user', 'u')
        ->addSelect('u')
        ->leftJoin('p.commentaires', 'c')
        ->addSelect('c')
        ->leftJoin('c.user', 'cu')
        ->addSelect('cu');

    $qb->andWhere('p.titre LIKE :q OR p.contenu LIKE :q OR p.categorie LIKE :q')
       ->setParameter('q', '%' . $q . '%');

    $this->applySort($qb, $sort);

    return $qb->getQuery()->getResult();
}

/**
 * ✅ Applique le tri choisi
 */


private function applySort(QueryBuilder $qb, string $sort): void
{
    switch ($sort) {
        case 'date_asc':
            $qb->orderBy('p.date_creation', 'ASC');
            break;

        case 'date_desc':
            $qb->orderBy('p.date_creation', 'DESC');
            break;

        case 'reactions_desc':
            $qb->orderBy('p.nbr_reactions', 'DESC');
            break;

        case 'categorie_asc':
            $qb->orderBy('p.categorie', 'ASC');
            break;

        default:
            $qb->orderBy('p.date_creation', 'DESC');
    }
}

/**
 * @return Post[]
 */
public function findRecentWithUsers(int $limit = 5): array
{
    return $this->createQueryBuilder('p')
        ->leftJoin('p.user', 'u')
        ->addSelect('u')
        ->leftJoin('p.commentaires', 'c')
        ->addSelect('c')
        ->leftJoin('c.user', 'cu')
        ->addSelect('cu')
        ->orderBy('p.date_creation', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}
/**
 * @return array{
 *     totalPosts: int,
 *     totalReactions: int,
 *     totalComments: int,
 *     engagement: float|int
 * }
 */
public function getKpis(): array
{
    $qb = $this->createQueryBuilder('p')
        ->select('COUNT(p.id) as totalPosts, COALESCE(SUM(p.nbr_reactions),0) as totalReactions, COALESCE(SUM(p.nbr_commentaires),0) as totalComments');

    $res = $qb->getQuery()->getSingleResult();

    $totalPosts = (int) $res['totalPosts'];
    $totalReactions = (int) $res['totalReactions'];
    $totalComments = (int) $res['totalComments'];

    $engagement = $totalPosts > 0 ? ($totalReactions + $totalComments) / $totalPosts : 0;

    return [
        'totalPosts' => $totalPosts,
        'totalReactions' => $totalReactions,
        'totalComments' => $totalComments,
        'engagement' => $engagement,
    ];
}

/**
 * @return array<int, array{label: string|null, total: int|string}>
 */
public function countByCategorie(): array
{
    return $this->createQueryBuilder('p')
        ->select('p.categorie as label, COUNT(p.id) as total')
        ->groupBy('p.categorie')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

/**
 * @return array<int, array{label: string|null, total: int|string}>
 */
public function countByHumeur(): array
{
    return $this->createQueryBuilder('p')
        ->select('p.humeur as label, COUNT(p.id) as total')
        ->groupBy('p.humeur')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

/**
 * @return Post[]
 */
public function topPostsByReactions(int $limit = 5): array
{
    return $this->createQueryBuilder('p')
        ->orderBy('p.nbr_reactions', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}

/**
 * @return array{
 *     total: int,
 *     reactions: int,
 *     comments: int,
 *     avg_reactions: int,
 *     public_rate: int
 * }
 */
public function getBlogKpis(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    $row = $this->createQueryBuilder('p')
        ->select('COUNT(p.id) AS total')
        ->addSelect('COALESCE(SUM(p.nbr_reactions), 0) AS reactions')
        ->addSelect('COALESCE(SUM(p.nbr_commentaires), 0) AS comments')
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->getQuery()
        ->getSingleResult();

    $total = (int) ($row['total'] ?? 0);
    $reactions = (int) ($row['reactions'] ?? 0);

    return [
        'total' => $total,
        'reactions' => $reactions,
        'comments' => (int) ($row['comments'] ?? 0),
        'avg_reactions' => $total > 0 ? (int) round($reactions / $total) : 0,
        'public_rate' => 0, // tu le calcules si tu as un champ isPublic
    ];
}

/**
 * @return Post[]
 */
public function topBlogPostsByReactions(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 5): array
{
    return $this->createQueryBuilder('p')
        ->select('p.titre AS titre, p.categorie AS categorie, p.nbr_reactions AS nbrReactions, p.nbr_commentaires AS nbrCommentaires')
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->orderBy('p.nbr_reactions', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getArrayResult();
}

/**
 * @return array<int, array{label: string, total: numeric-string}>
 */
public function countBlogByCategorie(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    /** @var array<int, array{label: string, total: numeric-string}> $rows */
    $rows = $this->createQueryBuilder('p')
        ->select("COALESCE(p.categorie, 'Sans catégorie') AS label, COUNT(p.id) AS total")
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->groupBy('label')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();

    return $rows;
}

/**
 * @return array<int, array{label: string, total: numeric-string}>
 */
public function countBlogByHumeur(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    /** @var array<int, array{label: string, total: numeric-string}> $rows */
    $rows = $this->createQueryBuilder('p')
        ->select("COALESCE(p.humeur, 'Non définie') AS label, COUNT(p.id) AS total")
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->groupBy('label')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();

    return $rows;
}

/**
 * @return array<int, array{day: string, total: int}>
 */
public function countBlogByDay(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    $rows = $this->createQueryBuilder('p')
        ->select('p.date_creation AS d, p.nbr_reactions AS r, p.nbr_commentaires AS c')
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->getQuery()
        ->getArrayResult();

    $map = [];
    foreach ($rows as $row) {
        $dt = $row['d'];
        $day = ($dt instanceof \DateTimeInterface)
            ? $dt->format('Y-m-d')
            : (new \DateTimeImmutable((string) $dt))->format('Y-m-d');

        if (!isset($map[$day])) {
            $map[$day] = ['total' => 0, 'reactions' => 0, 'comments' => 0];
        }
        $map[$day]['total']++;
        $map[$day]['reactions'] += (int) ($row['r'] ?? 0);
        $map[$day]['comments']  += (int) ($row['c'] ?? 0);
    }

    ksort($map);

    $result = [];
    foreach ($map as $day => $vals) {
        $result[] = ['day' => $day] + $vals;
    }

    return $result;
}





    //    /**
    //     * @return Post[] Returns an array of Post objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Post
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}