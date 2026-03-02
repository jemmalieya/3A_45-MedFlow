<?php
namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;

    public function __construct(EntityManagerInterface $em, UserPasswordHasherInterface $hasher)
    {
        $this->em = $em;
        $this->hasher = $hasher;
    }

    /**
     * Créer un utilisateur
     *
     * @param User $user Utilisateur à persister
     * @return User
     */
    public function saveUser(User $user): User
{
    $this->em->persist($user);
    $this->em->flush();

    return $user;
}

    /**
     * Mettre à jour un utilisateur
     * 
     * @param User                 $user
     * @param array<string, mixed> $data
     * @return User
     */
    public function updateUser(User $user, array $data): User
    {
        // Mettre à jour les champs de l'utilisateur
        $user->setPrenom($data['prenom']);
        $user->setNom($data['nom']);
        
        if (isset($data['plainPassword'])) {
            $user->setPassword($this->hasher->hashPassword($user, $data['plainPassword']));
        }

        $this->em->flush();

        return $user;
    }

    /**
     * Supprimer un utilisateur
     * 
     * @param User $user
     */
    public function deleteUser(User $user): void
    {
        $this->em->remove($user);
        $this->em->flush();
    }
    /**
     * @return User[]
     */
    public function getFilteredUsers(string $q, string $sort, string $role = 'PATIENT'): array
    {
        $qb = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.roleSysteme = :role')
            ->setParameter('role', $role);

        // Filter by search query
        if ($q !== '') {
            $qb->andWhere('u.cin LIKE :q OR u.nom LIKE :q OR u.prenom LIKE :q OR u.emailUser LIKE :q')
               ->setParameter('q', '%'.$q.'%');
        }

        // Sorting logic
        if ($sort === 'name') {
            $qb->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC');
        } elseif ($sort === 'cin') {
            $qb->orderBy('u.cin', 'ASC');
        } else {
            $qb->orderBy('u.id', 'DESC');
        }

        /** @var User[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

}