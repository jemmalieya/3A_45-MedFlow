<?php

namespace App\Controller;

use App\Entity\Post;
use App\Form\PostType;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Dompdf\Dompdf;
use Dompdf\Options;

#[Route('/posts')]
class PostController extends AbstractController
{
    #[Route('', name: 'post_index', methods: ['GET'])]
    public function index(PostRepository $repo, Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'date_desc');
        // ✅ si q existe => recherche, sinon => liste normale
        if ($q !== '') {
            $posts = $repo->searchWithSort($q, $sort);
        } else {
            $posts = $repo->findAllSorted($sort); 
            // ⚠️ mets ici le VRAI nom de ta propriété (dateCreation ou date_creation)
        }
        $recentPosts = $repo->findBy([], ['date_creation' => 'DESC'], 5);
        
        // tags (simple) => depuis hashtags
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

        return $this->render('post/index.html.twig', [
            'posts' => $posts,
            'recentPosts' => $recentPosts,
            'tags' => $tags,
            'q' => $q,
            'sort' => $sort,
        ]);
    }

    #[Route('/new', name: 'post_new', methods: ['GET', 'POST'])]
public function new(Request $request, EntityManagerInterface $em): Response
{
    $post = new Post();
    $post->setDateCreation(new \DateTimeImmutable());

    $form = $this->createForm(PostType::class, $post);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        /** @var UploadedFile|null $file */
        $file = $form->get('img_post')->getData();

        if ($file) {
            $newFilename = uniqid() . '.' . $file->guessExtension();
            $file->move($this->getParameter('pieces_jointes_directory'), $newFilename);
            $post->setImgPost('uploads/pieces_jointes/' . $newFilename);
        } else {
            // option: image par défaut
            $post->setImgPost('uploads/pieces_jointes/default-post.jpg');
        }

        $em->persist($post);
        $em->flush();

        $this->addFlash('success', 'Post ajouté ✅');
        return $this->redirectToRoute('post_index');
    }

    return $this->render('post/new.html.twig', [
        'form' => $form->createView(),
    ]);
}


  #[Route('/posts/{id}/edit', name: 'post_edit', methods: ['GET', 'POST'])]
public function edit(Request $request, Post $post, EntityManagerInterface $em): Response
{
    $oldImg = $post->getImgPost();

    $form = $this->createForm(PostType::class, $post);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        /** @var UploadedFile|null $file */
        $file = $form->get('img_post')->getData();

        if ($file) {
            $newFilename = uniqid() . '.' . $file->guessExtension();
            $file->move($this->getParameter('pieces_jointes_directory'), $newFilename);
            $post->setImgPost('uploads/pieces_jointes/' . $newFilename);
        } else {
            // ✅ garder l'ancienne
            $post->setImgPost($oldImg);
        }

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

    $type = $request->request->get('type'); // like, love, haha...

    $post->setNbrReactions(($post->getNbrReactions() ?? 0) + 1);
    $em->flush();

    return new JsonResponse([
        'ok' => true,
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


#[Route('/posts/stats', name: 'post_stats', methods: ['GET'])]
public function stats(PostRepository $repo): Response
{
    $kpis = $repo->getKpis();
    $byCategorie = $repo->countByCategorie();
    $byHumeur = $repo->countByHumeur();
    $topPosts = $repo->topPostsByReactions(5);

    // max pour les barres
    $maxCat = 0;
    foreach ($byCategorie as $row) $maxCat = max($maxCat, (int)$row['total']);

    $maxMood = 0;
    foreach ($byHumeur as $row) $maxMood = max($maxMood, (int)$row['total']);

    return $this->render('post/stats.html.twig', [
        'kpis' => $kpis,
        'byCategorie' => $byCategorie,
        'byHumeur' => $byHumeur,
        'topPosts' => $topPosts,
        'maxCat' => $maxCat,
        'maxMood' => $maxMood,
    ]);
}


}
