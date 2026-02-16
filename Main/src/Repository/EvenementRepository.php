<?php

namespace App\Repository;
use App\Entity\User;
use App\Entity\Evenement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evenement>
 */
class EvenementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evenement::class);
    }

    //    /**
    //     * @return Evenement[] Returns an array of Evenement objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('e.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Evenement
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    public function findAllSortedByStatutCustom(): array
{
    // ordre métier: Publié -> En cours -> Brouillon -> Annulé
    return $this->createQueryBuilder('e')
        ->addSelect("
            CASE
                WHEN e.statutEvent = 'Publié' THEN 1
                WHEN e.statutEvent = 'En cours' THEN 2
                WHEN e.statutEvent = 'Brouillon' THEN 3
                WHEN e.statutEvent = 'Annulé' THEN 4
                ELSE 5
            END AS HIDDEN statutOrder
        ")
        ->orderBy('statutOrder', 'ASC')
        ->addOrderBy('e.dateDebutEvent', 'DESC')
        ->getQuery()
        ->getResult();
}

public function findRecommended(Evenement $evenement)
{
    return $this->createQueryBuilder('e')
        ->where('e.id != :id')
        ->andWhere('e.type_event = :type OR e.ville_event = :ville')
        ->setParameter('id', $evenement->getId())
        ->setParameter('type', $evenement->getTypeEvent())
        ->setParameter('ville', $evenement->getVilleEvent())
        ->orderBy('e.date_debut_event', 'ASC')
        ->setMaxResults(3)
        ->getQuery()
        ->getResult();
}


public function findRecommendedForUser(Evenement $current, ?User $user, int $limit = 6): array
{
    // 1) On récupère des candidats (même type OU même ville) + filtrage simple
    $qb = $this->createQueryBuilder('e')
        ->where('e.id != :id')
        ->setParameter('id', $current->getId())
        ->andWhere('e.statut_event IS NULL OR LOWER(e.statut_event) != :annule')
        ->setParameter('annule', 'annulé');

    // On priorise les événements qui ressemblent déjà (type/ville)
    $orX = $qb->expr()->orX();
    if ($current->getTypeEvent()) {
        $orX->add('e.type_event = :type');
        $qb->setParameter('type', $current->getTypeEvent());
    }
    if ($current->getVilleEvent()) {
        $orX->add('e.ville_event = :ville');
        $qb->setParameter('ville', $current->getVilleEvent());
    }
    if (count($orX->getParts()) > 0) {
        $qb->andWhere($orX);
    }

    // On limite large, scoring ensuite
    $candidates = $qb->orderBy('e.date_debut_event', 'ASC')
        ->setMaxResults(40)
        ->getQuery()
        ->getResult();

    // 2) Historique user : types préférés (basé sur demandes envoyées)
    $userPrefTypes = [];
    $userCity = null;

    if ($user) {
        // adapte si ton User a ville/adresse (sinon laisse null)
       $userCity = null;

// Essaie ville_user si un jour tu l'ajoutes
//if (method_exists($user, 'getVilleUser')) {
  //  $userCity = $user->getVilleUser();
//}

// Sinon on utilise adresse_user (existe chez toi)
if (!$userCity && method_exists($user, 'getAdresseUser')) {
    $userCity = $user->getAdresseUser();
}


        $userEmail = method_exists($user, 'getEmailUser') ? $user->getEmailUser() : null;
        $typeCounts = [];

        if ($userEmail) {
            // On scanne tous les events pour détecter où ce user a demandé (JSON)
            // (simple, pas ultra performant mais OK pour un projet étudiant)
            $allEvents = $this->createQueryBuilder('x')->getQuery()->getResult();

            foreach ($allEvents as $ev) {
                foreach ($ev->getDemandesJson() as $d) {
                    if (($d['email'] ?? null) && strtolower($d['email']) === strtolower($userEmail)) {
                        $t = $ev->getTypeEvent() ?? null;
                        if ($t) $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
                    }
                }
            }
        }

        arsort($typeCounts);
        $userPrefTypes = array_slice(array_keys($typeCounts), 0, 2); // top 2 types
    }

    // 3) Scoring
    $now = new \DateTime();
    $scored = [];

    foreach ($candidates as $ev) {
        $score = 0;

        // Même type +3
        if ($current->getTypeEvent() && $ev->getTypeEvent() === $current->getTypeEvent()) {
            $score += 3;
        }

        // Même ville +2
        if ($current->getVilleEvent() && $ev->getVilleEvent() === $current->getVilleEvent()) {
            $score += 2;
        }

        // Proche en date +1 (si dans 30 jours)
        $d = $ev->getDateDebutEvent();
        if ($d instanceof \DateTimeInterface) {
            $diffDays = (int) $now->diff($d)->format('%r%a');
            if ($diffDays >= 0 && $diffDays <= 30) {
                $score += 1;
            }
        }

        // Beaucoup de demandes acceptées +2 (si >= 3)
        if (method_exists($ev, 'countDemandesByStatus')) {
            $accepted = (int) $ev->countDemandesByStatus('accepted');
            if ($accepted >= 3) {
                $score += 2;
            }
        }

        // ===== BONUS ULTIME USER =====
        // Ville user +2
      if ($userCity && $ev->getVilleEvent()) {
    $uc = strtolower((string) $userCity);
    $vc = strtolower((string) $ev->getVilleEvent());

    // Si la ville de l’event est contenue dans l’adresse user (ex: "Rue..., Tunis")
    if (str_contains($uc, $vc)) {
        $score += 2;
    }
}

        // Type préféré user +2
        if (!empty($userPrefTypes) && $ev->getTypeEvent() && in_array($ev->getTypeEvent(), $userPrefTypes, true)) {
            $score += 2;
        }

        // Optionnel : ne garder que les scores > 0
        $scored[] = ['event' => $ev, 'score' => $score];
    }

    // Trier par score DESC, puis date ASC
    usort($scored, function ($a, $b) {
        if ($a['score'] === $b['score']) {
            $da = $a['event']->getDateDebutEvent();
            $db = $b['event']->getDateDebutEvent();
            if (!$da || !$db) return 0;
            return $da <=> $db;
        }
        return $b['score'] <=> $a['score'];
    });

    // On extrait le top N
    $result = [];
    foreach ($scored as $row) {
        if (count($result) >= $limit) break;
        $result[] = $row['event'];
    }

    return $result;
}



}
