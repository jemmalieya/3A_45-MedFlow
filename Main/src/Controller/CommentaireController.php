<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\Commentaire;
use App\Form\CommentaireType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Form\FormError;


#[Route('/blog/commentaire')]
class CommentaireController extends AbstractController
{
    #[Route('/blog/commentaire/add/{id}', name: 'commentaire_add', methods: ['GET','POST'])]
public function add(Post $post, Request $request, EntityManagerInterface $em): Response
{
    $commentaire = new Commentaire();
    $commentaire->setPost($post);

    $form = $this->createForm(CommentaireType::class, $commentaire);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        $contenu = trim((string) $commentaire->getContenu());

        // ✅ Validation PHP uniquement (les 3 règles)
        if ($contenu === '') {
            $form->get('contenu')->addError(new \Symfony\Component\Form\FormError("Le commentaire ne peut pas être vide."));
        } elseif (!preg_match('/^\p{L}/u', $contenu)) {
            $form->get('contenu')->addError(new \Symfony\Component\Form\FormError("Le commentaire doit commencer par une lettre."));
        } else {
            preg_match_all('/\p{L}/u', $contenu, $matches);
            if (count($matches[0]) < 2) {
                $form->get('contenu')->addError(new \Symfony\Component\Form\FormError("Le commentaire doit contenir au moins 2 lettres."));
            }
        }

        // ✅ Si pas d’erreur => save
        if ($form->isValid()) {
            $commentaire->setContenu($contenu);
            $commentaire->setDateCreation(new \DateTimeImmutable());

            $em->persist($commentaire);

            // si tu utilises nbrCommentaires
            $post->setNbrCommentaires(($post->getNbrCommentaires() ?? 0) + 1);

            $em->flush();

            $this->addFlash('success', 'Commentaire ajouté ✅');
            return $this->redirectToRoute('post_show', ['id' => $post->getId()]);
        }
    }

    return $this->render('commentaire/form.html.twig', [
        'form' => $form->createView(),
        'post' => $post,
        'mode' => 'add'
    ]);
}


#[Route('/commentaire/edit/{id}', name: 'commentaire_edit', methods: ['GET', 'POST'])]
public function edit(Commentaire $commentaire, Request $request, EntityManagerInterface $em): Response
{
    $form = $this->createForm(CommentaireType::class, $commentaire, [
        'attr' => ['novalidate' => 'novalidate'] // ✅ désactive HTML5
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted()) {

        // ✅ éviter null -> string
        $commentaire->setContenu(trim((string) $commentaire->getContenu()));
        $contenu = $commentaire->getContenu();

        // ✅ Validation PHP uniquement
        if ($contenu === '') {
            $form->get('contenu')->addError(new FormError('Le commentaire ne peut pas être vide.'));
        }
        elseif (!preg_match('/^\p{L}/u', $contenu)) {
            $form->get('contenu')->addError(new FormError('Le commentaire doit commencer par une lettre.'));
        }
        else {
            preg_match_all('/\p{L}/u', $contenu, $matches);
            if (count($matches[0]) < 2) {
                $form->get('contenu')->addError(new FormError('Le commentaire doit contenir au moins 2 lettres.'));
            }
        }

        if ($form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Commentaire modifié ✅');

            return $this->redirectToRoute('post_show', [
                'id' => $commentaire->getPost()->getId(),
            ]);
        }
    }

    return $this->render('commentaire/form.html.twig', [
        'form' => $form,
        'post' => $commentaire->getPost(),
        'mode' => 'edit',
    ]);
}



    #[Route('/delete/{id}', name: 'commentaire_delete', methods: ['POST'])]
    public function delete(Commentaire $commentaire, Request $request, EntityManagerInterface $em): Response
    {
        $post = $commentaire->getPost();

        if ($this->isCsrfTokenValid('del_commentaire_'.$commentaire->getId(), $request->request->get('_token'))) {
            $em->remove($commentaire);
            $em->flush();
            $this->addFlash('success', 'Commentaire supprimé 🗑️');
        }

        return $this->redirectToRoute('post_show', ['id' => $post->getId()]);
    }

    #[Route('/add-inline/{id}', name: 'commentaire_add_inline', methods: ['POST'])]
public function addInline(Post $post, Request $request, EntityManagerInterface $em): Response
{
    // CSRF
    if (!$this->isCsrfTokenValid('add_comment_'.$post->getId(), $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('CSRF token invalid.');
    }

    $contenu = trim((string) $request->request->get('contenu'));
    $postId  = (string) $post->getId();

    // ✅ 1) pas vide
    if ($contenu === '') {
        $this->addFlash('comment_error', $postId.'|Le commentaire ne peut pas être vide.');
        return $this->redirectToRoute('post_index');
    }

    // ✅ 2) doit commencer par une lettre (unicode)
    if (!preg_match('/^\p{L}/u', $contenu)) {
        $this->addFlash('comment_error', $postId.'|Le commentaire doit commencer par une lettre.');
        return $this->redirectToRoute('post_index');
    }

    // ✅ 3) doit contenir au moins 2 lettres
    preg_match_all('/\p{L}/u', $contenu, $matches);
    if (count($matches[0]) < 2) {
        $this->addFlash('comment_error', $postId.'|Le commentaire doit contenir au moins 2 lettres.');
        return $this->redirectToRoute('post_index');
    }

    // ✅ OK -> enregistrer
    $commentaire = new Commentaire();
    $commentaire->setPost($post);
    $commentaire->setContenu($contenu);
    $commentaire->setDateCreation(new \DateTimeImmutable());

    $em->persist($commentaire);

    // ✅ MAJ nbr_commentaires (si tu utilises ce champ)
    $post->setNbrCommentaires(($post->getNbrCommentaires() ?? 0) + 1);

    $em->flush();

    $this->addFlash('success', 'Commentaire ajouté ✅');
    return $this->redirectToRoute('post_index');
}



}
