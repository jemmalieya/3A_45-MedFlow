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
use Symfony\Component\HttpFoundation\JsonResponse;



#[Route('/blog/commentaire')]
class CommentaireController extends AbstractController
{
    #[Route('/blog/commentaire/add/{id}', name: 'commentaire_add', methods: ['GET', 'POST'])]
public function add(Post $post, Request $request, EntityManagerInterface $em): Response
{
    $commentaire = new Commentaire();
    $commentaire->setPost($post);
    
    // Récupérer l'utilisateur connecté via AbstractController
    $user = $this->getUser(); // Cette ligne récupère l'utilisateur connecté
    
    if (!$user) {
        // Si l'utilisateur n'est pas connecté, rediriger vers la page de connexion
        $this->addFlash('error', 'Vous devez être connecté pour ajouter un commentaire.');
        return $this->redirectToRoute('app_login'); // Redirige vers la page de connexion si l'utilisateur n'est pas connecté
    }

    // Associer l'utilisateur au commentaire
    $commentaire->setUser($user);

    // Créer et traiter le formulaire
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
            // Pas besoin de manipuler manuellement la date, elle est automatiquement définie dans le constructeur de l'entité
            $commentaire->setContenu($contenu);
            $commentaire->setDateCreation(new \DateTimeImmutable());

            // Persister le commentaire dans la base de données
            $em->persist($commentaire);

            // Si tu utilises le champ nbrCommentaires
            $post->setNbrCommentaires(($post->getNbrCommentaires() ?? 0) + 1);

            // Sauvegarder les modifications en base de données
            $em->flush();

            // Ajouter un message flash pour informer l'utilisateur du succès
            $this->addFlash('success', 'Commentaire ajouté ✅');

            // Rediriger vers la page de détails du post
            return $this->redirectToRoute('post_show', ['id' => $post->getId()]);
        }
    }

    // Rendre la vue du formulaire
    return $this->render('commentaire/form.html.twig', [
        'form' => $form->createView(),
        'post' => $post,
        'mode' => 'add'
    ]);
}


#[Route('/commentaire/edit/{id}', name: 'commentaire_edit', methods: ['GET', 'POST'])]
public function edit(Commentaire $commentaire, Request $request, EntityManagerInterface $em): Response
{

$user = $this->getUser();
if (!$user || $commentaire->getUser() !== $user) {
    $this->addFlash('error', 'Accès refusé.');
    return $this->redirectToRoute('post_show', ['id' => $commentaire->getPost()->getId()]);
}

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



#[Route('/commentaires/{id}/delete', name: 'commentaire_delete', methods: ['POST'])]
public function delete(Request $request, Commentaire $commentaire, EntityManagerInterface $em): JsonResponse
{
    if (!$this->isCsrfTokenValid('del_commentaire_' . $commentaire->getId(), $request->request->get('_token'))) {
        return new JsonResponse(['ok' => false, 'message' => 'CSRF invalide'], 403);
    }

    // ✅ Sauver les IDs AVANT suppression
    $commentId = $commentaire->getId();
    $post = $commentaire->getPost();
    $postId = $post?->getId();

    // décrémenter compteur
    if ($post) {
        $post->setNbrCommentaires(max(0, ($post->getNbrCommentaires() ?? 0) - 1));
    }

    $em->remove($commentaire);
    $em->flush();

    return new JsonResponse([
        'ok' => true,
        'postId' => $postId,
        'nbrCommentaires' => $post?->getNbrCommentaires() ?? 0,
        'commentId' => $commentId, // ✅ plus jamais null
    ]);
}




    #[Route('/add-inline/{id}', name: 'commentaire_add_inline', methods: ['POST'])]
public function addInline(Post $post, Request $request, EntityManagerInterface $em): Response
{
    // CSRF
    

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

    // Assigner l'utilisateur connecté (required)
    $user = $this->getUser();
    if (!$user) {
        $this->addFlash('comment_error', $postId.'|Vous devez être connecté pour ajouter un commentaire.');
        return $this->redirectToRoute('app_login');
    }
    $commentaire->setUser($user);

    $em->persist($commentaire);

    // ✅ MAJ nbr_commentaires (si tu utilises ce champ)
    $post->setNbrCommentaires(($post->getNbrCommentaires() ?? 0) + 1);

    $em->flush();

    $this->addFlash('success', 'Commentaire ajouté ✅');
    return $this->redirectToRoute('post_index');
}

#[Route('/inline-edit/{id}', name: 'commentaire_inline_edit', methods: ['POST'])]
public function inlineEdit(Request $request, Commentaire $commentaire, EntityManagerInterface $em): JsonResponse
{
    $user = $this->getUser();

    // ✅ Autorisation : propriétaire OU admin
    if (!$user || (!$this->isGranted('ROLE_ADMIN') && $commentaire->getUser() !== $user)) {
        return new JsonResponse(['ok' => false, 'message' => 'Accès refusé.'], 403);
    }

    // ✅ CSRF
    if (!$this->isCsrfTokenValid('edit_commentaire_' . $commentaire->getId(), (string)$request->request->get('_token'))) {
        return new JsonResponse(['ok' => false, 'message' => 'CSRF invalide.'], 403);
    }

    $contenu = trim((string) $request->request->get('contenu'));

    // ✅ mêmes validations que chez toi
    if ($contenu === '') {
        return new JsonResponse(['ok' => false, 'message' => 'Le commentaire ne peut pas être vide.'], 422);
    }
    if (!preg_match('/^\p{L}/u', $contenu)) {
        return new JsonResponse(['ok' => false, 'message' => 'Le commentaire doit commencer par une lettre.'], 422);
    }
    preg_match_all('/\p{L}/u', $contenu, $matches);
    if (count($matches[0]) < 2) {
        return new JsonResponse(['ok' => false, 'message' => 'Le commentaire doit contenir au moins 2 lettres.'], 422);
    }

    $commentaire->setContenu($contenu);
    $em->flush();

    return new JsonResponse([
        'ok' => true,
        'commentId' => $commentaire->getId(),
        'contenu' => $commentaire->getContenu(),
    ]);
}


}