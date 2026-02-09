<?php

namespace App\Repository;

use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

public function search(string $q): array
{
    $q = trim($q);

    $qb = $this->createQueryBuilder('p')
        ->orderBy('p.date_creation', 'DESC'); // ⚠️ pareil: propriété exacte

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

public function findAllSorted(string $sort): array
{
    $qb = $this->createQueryBuilder('p');

    $this->applySort($qb, $sort);

    return $qb->getQuery()->getResult();
}

public function searchWithSort(string $q, string $sort): array
{
    $qb = $this->createQueryBuilder('p');

    $qb->andWhere('p.titre LIKE :q OR p.contenu LIKE :q OR p.categorie LIKE :q')
       ->setParameter('q', '%' . $q . '%');

    $this->applySort($qb, $sort);

    return $qb->getQuery()->getResult();
}

/**
 * ✅ Applique le tri choisi
 */
private function applySort($qb, string $sort): void
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

public function countByCategorie(): array
{
    return $this->createQueryBuilder('p')
        ->select('p.categorie as label, COUNT(p.id) as total')
        ->groupBy('p.categorie')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

public function countByHumeur(): array
{
    return $this->createQueryBuilder('p')
        ->select('p.humeur as label, COUNT(p.id) as total')
        ->groupBy('p.humeur')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

public function topPostsByReactions(int $limit = 5): array
{
    return $this->createQueryBuilder('p')
        ->orderBy('p.nbr_reactions', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}

  public function getBlogKpis(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    $row = $this->createQueryBuilder('p')
        ->select('COUNT(p.id) AS totalPosts')
        ->addSelect('COALESCE(SUM(p.nbr_reactions), 0) AS totalReactions')
        ->addSelect('COALESCE(SUM(p.nbr_commentaires), 0) AS totalComments')
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->getQuery()
        ->getSingleResult();

    return [
        'totalPosts' => (int) $row['totalPosts'],
        'totalReactions' => (int) $row['totalReactions'],
        'totalComments' => (int) $row['totalComments'],
    ];
}

public function topBlogPostsByReactions(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 5): array
{
    return $this->createQueryBuilder('p')
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->orderBy('p.nbr_reactions', 'DESC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();
}

public function countBlogByCategorie(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    return $this->createQueryBuilder('p')
        ->select("COALESCE(p.categorie, 'Sans catégorie') AS label, COUNT(p.id) AS total")
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->groupBy('label')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

public function countBlogByHumeur(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    return $this->createQueryBuilder('p')
        ->select("COALESCE(p.humeur, 'Non définie') AS label, COUNT(p.id) AS total")
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->groupBy('label')
        ->orderBy('total', 'DESC')
        ->getQuery()
        ->getArrayResult();
}

public function countBlogByDay(\DateTimeInterface $from, \DateTimeInterface $to): array
{
    $rows = $this->createQueryBuilder('p')
        ->select('p.date_creation AS d')
        ->andWhere('p.date_creation BETWEEN :from AND :to')
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->getQuery()
        ->getArrayResult();

    // Regrouper par jour en PHP
    $map = [];

    foreach ($rows as $r) {
        $dt = $r['d']; // peut être DateTimeInterface
        if ($dt instanceof \DateTimeInterface) {
            $day = $dt->format('Y-m-d');
        } else {
            // fallback si doctrine renvoie string
            $day = (new \DateTimeImmutable((string)$dt))->format('Y-m-d');
        }
        $map[$day] = ($map[$day] ?? 0) + 1;
    }

    ksort($map);

    $result = [];
    foreach ($map as $day => $total) {
        $result[] = ['day' => $day, 'total' => $total];
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
