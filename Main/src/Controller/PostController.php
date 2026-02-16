<?php

namespace App\Controller;

use App\Entity\Post;
use App\Form\PostType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repository\PostRepository;
use App\Service\GeminiService;
use App\Repository\ReactionRepository;
use App\Entity\Reaction;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;
use App\Service\BadWordsService;
use App\Entity\User;



#[Route('/posts')]
class PostController extends AbstractController
{
    #[Route('', name: 'post_index', methods: ['GET', 'POST'])]
    public function index(PostRepository $repo, Request $request, EntityManagerInterface $em): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'date_desc');

        if ($q !== '') {
            $posts = $repo->searchWithSort($q, $sort);
        } else {
            $posts = $repo->findAllSorted($sort);
        }

        // ✅ Afficher uniquement les posts approuvés (public)
        $posts = array_values(array_filter($posts, fn(Post $p) => $p->isApproved()));

        // ✅ recent : uniquement approuvés
        $recentPosts = $repo->findRecentWithUsers(5);
        $recentPosts = array_values(array_filter($recentPosts, fn(Post $p) => $p->isApproved()));

        // tags
        $tags = [];
        foreach ($posts as $p) {
            if ($p->getHashtags()) {
                $parts = preg_split('/\s+|,/', trim($p->getHashtags()));
                foreach ($parts as $t) {
                    $t = trim($t);
                    if ($t === '') continue;
                    if ($t[0] !== '#') $t = '#'.$t;
                    $tags[$t] = true;
                }
            }
        }
        $tags = array_slice(array_keys($tags), 0, 12);

        $user = $this->getUser();
$myPendingPosts = [];

if ($user) {
    $myPendingPosts = $repo->findBy(
        ['user' => $user, 'isApproved' => false],
        ['date_creation' => 'DESC']
    );
}
// ✅ Notifications de modération (uniquement pour le user connecté)
$moderationNotifs = [];

if ($user) {
    $moderationNotifs = $repo->createQueryBuilder('p')
        ->andWhere('p.user = :u')
        ->andWhere('p.moderationSeen = false')
        ->andWhere('p.moderationStatus IN (:st)')
        ->setParameter('u', $user)
        ->setParameter('st', ['APPROVED', 'REJECTED'])
        ->orderBy('p.date_creation', 'DESC')
        ->getQuery()
        ->getResult();

    $em->flush();
}


        return $this->render('post/index.html.twig', [
            'posts' => $posts,
            'recentPosts' => $recentPosts,
            'tags' => $tags,
            'q' => $q,
            'sort' => $sort,
            'myPendingPosts' => $myPendingPosts,
            'moderationNotifs' => $moderationNotifs,


        ]);
    }


#[Route('/new', name: 'post_new', methods: ['GET', 'POST'])]
public function new(Request $request, EntityManagerInterface $em, BadWordsService $badWordsService): Response
{
    // ✅ Forcer l'utilisateur à être connecté
    $user = $this->getUser();
    if (!$user) {
        // ✅ SweetAlert warning
        $this->addFlash('swal_warning', 'Vous devez être connecté pour créer un post.');
        return $this->redirectToRoute('app_login');
    }

    $post = new Post();
    $post->setDateCreation(new \DateTimeImmutable());

    $form = $this->createForm(PostType::class, $post);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        // ✅ Assigner l'utilisateur APRÈS handleRequest()
        $post->setUser($user);

        // ✅ URL image (champ HTML "image_url" dans twig)
        $imageUrl = trim((string) $request->request->get('image_url', ''));
        if ($imageUrl !== '') {
            $post->setImgPost($imageUrl);
        } else {
            $post->setImgPost('uploads/pieces_jointes/default-post.jpg');
        }

        // ✅ Vérification BadWords (titre + contenu + localisation)
        $textToCheck = trim(
            (string) $post->getTitre() . ' ' .
            (string) $post->getContenu() . ' ' .
            (string) ($post->getLocalisation() ?? '')
        );

        $check = $badWordsService->check($textToCheck);

        if ($check['hasBadWords']) {
            // ❌ On n’enregistre PAS
            $this->addFlash(
                'swal_error',
                "Post refusé : il contient des mots inappropriés. Votre publication a été annulée."
            );
            return $this->redirectToRoute('post_new');
        }

        // ✅ Post en attente de validation
        $post->setIsApproved(false);

        $em->persist($post);
        $em->flush();

        // ✅ SweetAlert success
        $this->addFlash('swal_success', 'Post envoyé ✅ Il sera visible après validation par un admin.');
        return $this->redirectToRoute('post_index');
    }

    return $this->render('post/new.html.twig', [
        'form' => $form->createView(),
        'post' => $post,
    ]);
}




  #[Route('/posts/{id}/edit', name: 'post_edit', methods: ['GET', 'POST'])]
public function edit(Request $request, Post $post, EntityManagerInterface $em): Response
{
    $form = $this->createForm(PostType::class, $post);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        // ✅ S'assurer que l'utilisateur reste assigné
        if (!$post->getUser()) {
            $post->setUser($this->getUser());
        }
        // ✅ URL image (champ HTML "image_url" dans twig)
        $imageUrl = trim((string) $request->request->get('image_url', ''));

        if ($imageUrl !== '') {
            $post->setImgPost($imageUrl);
        }
        // sinon on garde l’ancienne imgPost (ne rien faire)

        $post->setDateModification(new \DateTimeImmutable());

        $em->flush();

        $this->addFlash('success', 'Post modifié ✅');
        return $this->redirectToRoute('post_index');
    }

    return $this->render('post/edit.html.twig', [
        'post' => $post,
        'form' => $form->createView(),
    ]);
}
#[Route('/posts/{id}', name: 'post_show', requirements: ['id' => '\d+'],methods: ['GET'])]
public function show(Post $post): Response
{
    return $this->render('post/show.html.twig', [
        'post' => $post,
    ]);
}


    #[Route('/{id}/delete', name: 'post_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Post $post, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_post_'.$post->getId(), $request->request->get('_token'))) {
            $em->remove($post);
            $em->flush();
            $this->addFlash('success', 'Post supprimé.');
        }

        return $this->redirectToRoute('post_index');
    }

    

#[Route('/{id}/react', name: 'post_react', methods: ['POST'])]
    public function react(Post $post, Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('react_post_'.$post->getId(), $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'message' => 'CSRF invalid'], 403);
        }

        $type = (string) $request->request->get('type', ''); // like, love, haha...

        // require auth
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['ok' => false, 'message' => 'Authentication required'], 403);
        }

        /** @var ReactionRepository $reactionRepo */
        $reactionRepo = $em->getRepository(Reaction::class);
        $existing = $reactionRepo->findOneBy(['post' => $post, 'user' => $user]);

        if ($existing === null) {
            // create
            $r = new Reaction();
            $r->setPost($post)->setUser($user)->setType($type);

            try {
                $em->persist($r);
                // increment post counter
                $post->incrementReactions(1);
                $em->flush();

                return new JsonResponse([
                    'ok' => true,
                    'action' => 'created',
                    'nbrReactions' => $post->getNbrReactions(),
                    'type' => $type,
                    'postId' => $post->getId(),
                ]);
            } catch (\Throwable $e) {
                // If Reaction table doesn't exist or DB error, fallback: still increment counter
                try {
                    $post->incrementReactions(1);
                    $em->flush();
                } catch (\Throwable $ignored) {
                    // ignore secondary failures
                }

                return new JsonResponse([
                    'ok' => true,
                    'action' => 'created_fallback',
                    'nbrReactions' => $post->getNbrReactions(),
                    'type' => $type,
                    'postId' => $post->getId(),
                ]);
            }
        }

        // already exists
        if ($existing->getType() === $type) {
            // same reaction clicked -> noop
            return new JsonResponse([
                'ok' => true,
                'action' => 'noop',
                'nbrReactions' => $post->getNbrReactions(),
                'type' => $type,
                'postId' => $post->getId(),
            ]);
        }

        // change emoji
        $existing->setType($type);
        $em->flush();

        return new JsonResponse([
            'ok' => true,
            'action' => 'updated',
            'nbrReactions' => $post->getNbrReactions(),
            'type' => $type,
            'postId' => $post->getId(),
        ]);
    }

#[Route('/posts/export/pdf', name: 'post_export_pdf', methods: ['GET'])]
public function exportAllPdf(PostRepository $repo, Request $request): Response
{
    $q = trim((string) $request->query->get('q', ''));
    $sort = (string) $request->query->get('sort', 'date_desc');

    // ✅ On garde la même logique que ton index (recherche + tri)
    if ($q !== '') {
        // si tu as déjà searchWithSort
        $posts = method_exists($repo, 'searchWithSort')
            ? $repo->searchWithSort($q, $sort)
            : $repo->search($q);
    } else {
        // si tu as déjà findAllSorted
        $posts = method_exists($repo, 'findAllSorted')
            ? $repo->findAllSorted($sort)
            : $repo->findBy([], ['date_creation' => 'DESC']);
    }

    // ✅ Stats "innovantes"
    $totalPosts = count($posts);
    $totalReactions = 0;
    $totalComments = 0;
    $categoriesCount = [];

    foreach ($posts as $p) {
        $totalReactions += $p->getNbrReactions();
        $totalComments += $p->getNbrCommentaires();

        $cat = $p->getCategorie() ?: 'Sans catégorie';
        $categoriesCount[$cat] = ($categoriesCount[$cat] ?? 0) + 1;
    }

    arsort($categoriesCount);
    $topCategories = array_slice($categoriesCount, 0, 5, true);

    // Top posts par réactions
    $postsSortedByReact = $posts;
    usort($postsSortedByReact, fn($a, $b) => $b->getNbrReactions() <=> $a->getNbrReactions());
    $topPosts = array_slice($postsSortedByReact, 0, 5);

    // ✅ HTML depuis Twig
    $html = $this->renderView('post/pdf_all.html.twig', [
        'posts' => $posts,
        'q' => $q,
        'sort' => $sort,
        'totalPosts' => $totalPosts,
        'totalReactions' => $totalReactions,
        'totalComments' => $totalComments,
        'topCategories' => $topCategories,
        'topPosts' => $topPosts,
        'generatedAt' => new \DateTimeImmutable(),
    ]);

    // ✅ Dompdf config
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans'); // accents OK
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'rapport_posts_' . (new \DateTime())->format('Ymd_His') . '.pdf';

    return new Response(
        $dompdf->output(),
        200,
        [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]
    );
}


#[Route('/admin/blog/stats', name: 'admin_blog_stats', methods: ['GET'])]
public function adminBlogStats(PostRepository $repo, Request $request): Response
{
    $days = max(1, (int) $request->query->get('days', 7));

    $to = new \DateTimeImmutable('today 23:59:59');
    $from = $to->modify('-'.($days - 1).' days')->setTime(0, 0, 0);

    // Ces méthodes doivent exister dans PostRepository (je te les donne si tu veux)
    $kpis = $repo->getBlogKpis($from, $to);
    $byDay = $repo->countBlogByDay($from, $to);
    $byCategorie = $repo->countBlogByCategorie($from, $to);
    $byHumeur = $repo->countBlogByHumeur($from, $to);
    $topPosts = $repo->topBlogPostsByReactions($from, $to, 5);

    return $this->render('admin/stat_Blog.html.twig', [
        'days' => $days,
        'from' => $from,
        'to' => $to,
        'kpis' => $kpis,
        'byDay' => $byDay,
        'byCategorie' => $byCategorie,
        'byHumeur' => $byHumeur,
        'topPosts' => $topPosts,
    ]);
}



#[Route('/ai/generate', name: 'post_ai_generate', methods: ['POST'])]
public function aiGenerate(Request $request, GeminiService $gemini, LoggerInterface $logger): JsonResponse
{
    // Récupérer les données du payload JSON
    $payload = json_decode($request->getContent(), true) ?? [];
    $task = $payload['task'] ?? 'improve'; // La tâche (améliorer le texte, générer des hashtags, etc.)
    $text = trim((string)($payload['text'] ?? ''));

    // Si le texte est vide, retour d'erreur
    if ($text === '') {
        return new JsonResponse(['ok' => false, 'message' => 'Texte vide'], 400);
    }

    // Choisir le prompt en fonction de la tâche
    $prompt = match ($task) {
        'title' => "Propose 5 titres courts (max 8 mots) pour ce post:\n\n" . $text,
        'hashtags' => "Donne 8 hashtags pertinents (format #tag) séparés par espace pour ce post:\n\n" . $text,
        default => "Améliore ce contenu en français: plus clair, professionnel, et garde le sens. Retourne uniquement le texte amélioré.\n\n" . $text,
    };

  try {
    $result = $gemini->generate($prompt);

    return new JsonResponse([
        'ok' => true,
        'result' => $result,
        'debug' => [
            'len' => strlen((string)$result),
            'task' => $task,
        ],
    ]);
} catch (\Throwable $e) {
    return new JsonResponse([
        'ok' => false,
        'message' => 'Erreur Gemini',
        'debug' => [
            'class' => get_class($e),
            'msg' => $e->getMessage(),
        ]
    ], 500);
}


}

  #[Route('/admin/pending', name: 'admin_posts_pending', methods: ['GET'])]
    public function pending(PostRepository $repo): Response
    {
        //$this->denyAccessUnlessGranted('ROLE_ADMIN');

        // ⚠️ champ DB = date_creation (pas dateCreation)
        $posts = $repo->findBy(['isApproved' => false], ['date_creation' => 'DESC']);

        return $this->render('admin/posts_pending.html.twig', [
            'posts' => $posts,
        ]);
    }
#[Route('/admin/{id}/approve', name: 'admin_post_approve', methods: ['POST'])]
public function approve(Post $post, Request $request, EntityManagerInterface $em): Response
{
   // $this->denyAccessUnlessGranted('ROLE_ADMIN');

    if (!$this->isCsrfTokenValid('approve_post_'.$post->getId(), $request->request->get('_token'))) {
        $this->addFlash('danger', 'Token CSRF invalide.');
        return $this->redirectToRoute('admin_posts_pending');
    }

    $post->setIsApproved(true);

    // ✅ Notif pour le user
    $post->setModerationStatus('APPROVED');
    $post->setModerationMessage('Votre post "' . $post->getTitre() . '" a été approuvé ✅');
    $post->setModerationSeen(false);

    $em->flush();

    $this->addFlash('success', 'Post approuvé ✅');
    return $this->redirectToRoute('admin_posts_pending');
}


#[Route('/admin/{id}/reject', name: 'admin_post_reject', methods: ['POST'])]
public function reject(Post $post, Request $request, EntityManagerInterface $em): Response
{
  //  $this->denyAccessUnlessGranted('ROLE_ADMIN');

    if (!$this->isCsrfTokenValid('reject_post_'.$post->getId(), $request->request->get('_token'))) {
        $this->addFlash('danger', 'Token CSRF invalide.');
        return $this->redirectToRoute('admin_posts_pending');
    }

    // ❌ ne pas supprimer : on garde le post en "REJECTED"
    $post->setIsApproved(false);

    // ✅ Notif pour le user
    $post->setModerationStatus('REJECTED');
    $post->setModerationMessage('Votre post "' . $post->getTitre() . '" a été refusé ❌');
    $post->setModerationSeen(false);

    $em->flush();

    $this->addFlash('success', 'Post refusé ✅');
    return $this->redirectToRoute('admin_posts_pending');
}

    #[Route('/my/pending', name: 'post_my_pending', methods: ['GET'])]
    public function myPending(PostRepository $repo): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // ⚠️ champ DB = date_creation
        $posts = $repo->findBy(
            ['user' => $user, 'isApproved' => false],
            ['date_creation' => 'DESC']
        );

        return $this->render('post/my_pending.html.twig', [
            'posts' => $posts
        ]);
    }

#[Route('/moderation/{id}/ack', name: 'post_moderation_ack', methods: ['POST'])]
public function moderationAck(Post $post, Request $request, EntityManagerInterface $em): JsonResponse
{
    $user = $this->getUser();

    if (!$user instanceof User) {
        return new JsonResponse(['ok' => false, 'message' => 'Unauthorized'], 403);
    }

    // sécurité : seul le propriétaire peut ack
    if ($post->getUser()?->getId() !== $user->getId()) {
        return new JsonResponse(['ok' => false, 'message' => 'Unauthorized'], 403);
    }

    // IMPORTANT: _token (sans espace)
    if (!$this->isCsrfTokenValid('moderation_ack_'.$post->getId(), (string)$request->request->get('_token'))) {
        return new JsonResponse(['ok' => false, 'message' => 'CSRF invalid'], 403);
    }

    // marquer comme vue
    $post->setModerationSeen(true);

    // si refusé => supprimer
    if ($post->getModerationStatus() === 'REJECTED') {
        $em->remove($post);
        $em->flush();
        return new JsonResponse(['ok' => true, 'deleted' => true]);
    }

    $em->flush();
    return new JsonResponse(['ok' => true, 'deleted' => false]);
}



}