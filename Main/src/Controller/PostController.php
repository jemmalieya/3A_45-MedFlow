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

#[Route('/posts')]
class PostController extends AbstractController
{
    #[Route('', name: 'post_index', methods: ['GET'])]
    public function index(PostRepository $repo): Response
    {
        $posts = $repo->findBy([], ['date_creation' => 'DESC']);
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
        ]);
    }

    #[Route('/posts/new', name: 'post_new', methods: ['GET', 'POST'])]
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
            $file->move($this->getParameter('posts_images_directory'), $newFilename);
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
            $file->move($this->getParameter('posts_images_directory'), $newFilename);
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


#[Route('/posts/{id}', name: 'post_show', methods: ['GET'])]
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
}
