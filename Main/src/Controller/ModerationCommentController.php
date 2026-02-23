<?php

namespace App\Controller;

use App\Entity\Commentaire;
use App\Repository\CommentaireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/moderation', name: 'admin_moderation_')]
class ModerationCommentController extends AbstractController
{
    #[Route('/comments', name: 'comments', methods: ['GET'])]
    public function comments(CommentaireRepository $repo): Response
    {
        

        $blocked = $repo->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->leftJoin('c.post', 'p')->addSelect('p')
            ->andWhere('c.status = :st')
            ->setParameter('st', 'blocked')
            ->orderBy('c.moderatedAt', 'DESC')
            // ✅ FIX: ton champ Doctrine s’appelle date_creation (pas dateCreation)
            ->addOrderBy('c.date_creation', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/comments.html.twig', [
            'blockedComments' => $blocked,
        ]);
    }

    #[Route('/comment/{id}/approve', name: 'comment_approve', methods: ['POST'])]
    public function approve(Commentaire $commentaire, Request $request, EntityManagerInterface $em): Response
    {
        

        if (!$this->isCsrfTokenValid('approve_comment_' . $commentaire->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF invalide.');
            return $this->redirectToRoute('admin_moderation_comments');
        }

        $commentaire->setStatus('published');
        $em->flush();

        $this->addFlash('success', 'Commentaire approuvé ✅');
        return $this->redirectToRoute('admin_moderation_comments');
    }

    #[Route('/comment/{id}/reject', name: 'comment_reject', methods: ['POST'])]
    public function reject(Commentaire $commentaire, Request $request, EntityManagerInterface $em): Response
    {
        

        if (!$this->isCsrfTokenValid('reject_comment_' . $commentaire->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF invalide.');
            return $this->redirectToRoute('admin_moderation_comments');
        }

        // Option simple : supprimer
        $em->remove($commentaire);
        $em->flush();

        $this->addFlash('success', 'Commentaire supprimé ✅');
        return $this->redirectToRoute('admin_moderation_comments');
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(CommentaireRepository $repo): Response
    {
        

        $total = (int) $repo->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $blocked = (int) $repo->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.status = :st')
            ->setParameter('st', 'blocked')
            ->getQuery()
            ->getSingleScalarResult();

        $published = (int) $repo->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.status = :st')
            ->setParameter('st', 'published')
            ->getQuery()
            ->getSingleScalarResult();

        $rate = $total > 0 ? round(($blocked / $total) * 100, 2) : 0;

        // Top mots sur commentaires bloqués
        $blockedTexts = $repo->createQueryBuilder('c')
            ->select('c.contenu')
            ->andWhere('c.status = :st')
            ->setParameter('st', 'blocked')
            ->getQuery()
            ->getArrayResult();

        $freq = [];
        $stop = ['le','la','les','un','une','des','de','du','au','aux','et','ou','a','à','en','dans','pour','sur','ce','cet','cette','ces','je','tu','il','elle','nous','vous','ils','elles','mon','ton','son','mes','tes','ses','est','suis','es','être','pas','plus','moins','très','tres'];

        foreach ($blockedTexts as $row) {
            $text = mb_strtolower((string) ($row['contenu'] ?? ''));
            $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
            $words = preg_split('/\s+/', trim($text)) ?: [];
            foreach ($words as $w) {
                if (mb_strlen($w) < 3) continue;
                if (in_array($w, $stop, true)) continue;
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
        }

        arsort($freq);
        $topWords = array_slice($freq, 0, 10, true);

        return $this->render('admin/stats.html.twig', [
            'total' => $total,
            'blocked' => $blocked,
            'published' => $published,
            'rate' => $rate,
            'topWords' => $topWords,
        ]);
    }
}