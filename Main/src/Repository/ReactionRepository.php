<?php

namespace App\Repository;

use App\Entity\Reaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Post;
use App\Entity\User;
/**
 * @extends ServiceEntityRepository<Reaction>
 */
class ReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reaction::class);
    }

   public function findOneByPostAndUser(Post $post, User $user): ?Reaction
{
    return $this->findOneBy([
        'post' => $post,
        'user' => $user
    ]);
}
}
