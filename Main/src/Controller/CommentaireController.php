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
use App\Service\AiCommentModerationService;
use App\Entity\User;

#[Route('/blog/commentaire')]
class CommentaireController extends AbstractController
{
    #[Route('/blog/commentaire/add/{id}', name: 'commentaire_add', methods: ['GET', 'POST'])]
public function add(Post $post, Request $request, EntityManagerInterface $em, AiCommentModerationService $moderation): Response
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


    

$user = $this->getUser();

if (!$user instanceof User) {
    throw $this->createAccessDeniedException();
}

$commentaire->setUser($user);

   $form = $this->createForm(CommentaireType::class, $commentaire);
   $form->handleRequest($request);

if ($form->isSubmitted()) {

    // Récupérer depuis le formulaire (pas l'entité)
    $contenu = trim((string) $form->get('contenu')->getData());

    // ✅ Tes 3 règles PHP
    if (empty($contenu)) {
        $form->get('contenu')->addError(new FormError("Le commentaire ne peut pas être vide."));
    } elseif (!preg_match('/^\p{L}/u', $contenu)) {
        $form->get('contenu')->addError(new FormError("Le commentaire doit commencer par une lettre."));
    } else {
        preg_match_all('/\p{L}/u', $contenu, $matches);
        if (count($matches[0]) < 2) {
            $form->get('contenu')->addError(new FormError("Le commentaire doit contenir au moins 2 lettres."));
        }
    }

    // ✅ Maintenant seulement, on vérifie isValid()
    if ($form->isValid()) {
        $commentaire->setContenu($contenu);
        $commentaire->setDateCreation(new \DateTimeImmutable());

// ✅ MODERATION IA
// ======================
/** @var array{allow: bool, score: float, label: string} $decision */
$decision = $moderation->moderate($contenu);

$commentaire->setModerationScore($decision['score']);
$commentaire->setModerationLabel($decision['label']);
$commentaire->markAsModerated();

if (!$decision['allow']) {

    $commentaire->setStatus('blocked');

    $this->addFlash(
        'error',
        sprintf(
            'Commentaire bloqué automatiquement (IA: %s / score %.2f).',
            $decision['label'],
            $decision['score']
        )
    );

} else {

    $commentaire->setStatus('published');
}
            // Persister le commentaire dans la base de données
            $em->persist($commentaire);

            // Si tu utilises le champ nbrCommentaires
            $post->setNbrCommentaires(((int) $post->getNbrCommentaires()) + 1);
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
public function edit(Commentaire $commentaire, Request $request, EntityManagerInterface $em, AiCommentModerationService $moderation): Response
{

$user = $this->getUser();
$post = $commentaire->getPost();

if (!$user instanceof User || $commentaire->getUser() !== $user) {

    $this->addFlash('error', 'Accès refusé.');

    if ($post === null) {
        throw $this->createNotFoundException('Post introuvable.');
    }

    return $this->redirectToRoute('post_show', [
        'id' => $post->getId()
    ]);
}

    $form = $this->createForm(CommentaireType::class, $commentaire, [
        'attr' => ['novalidate' => 'novalidate'] // ✅ désactive HTML5
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted()) {

        // ✅ éviter null -> string
        $contenu = trim((string) $commentaire->getContenu());
$commentaire->setContenu($contenu);

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
            $decision = $moderation->moderate($contenu);

$commentaire->setModerationScore($decision['score']);
$commentaire->setModerationLabel($decision['label']);
$commentaire->markAsModerated();

if (!$decision['allow']) {
    $commentaire->setStatus('blocked');
    $this->addFlash('error', "Modification refusée: commentaire bloqué par IA (IA: {$decision['label']} / score {$decision['score']}).");
} else {
    $commentaire->setStatus('published');
}
            $em->flush();
            $this->addFlash('success', 'Commentaire modifié ✅');

            $post = $commentaire->getPost();
if ($post === null) {
    throw $this->createNotFoundException('Post introuvable.');
}

return $this->redirectToRoute('post_show', [
    'id' => $post->getId(),
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
public function delete(Request $request, Commentaire $commentaire, EntityManagerInterface $em): Response
{
$token = $request->request->get('_token');

if (!$this->isCsrfTokenValid(
    'del_commentaire_' . $commentaire->getId(),
    is_string($token) ? $token : null
)) {
    $this->addFlash('error', 'CSRF invalide');

    $post = $commentaire->getPost();

    if ($post === null) {
        throw $this->createNotFoundException('Post introuvable.');
    }

    return $this->redirectToRoute('post_show', [
        'id' => $post->getId()
    ]);
}

    $post = $commentaire->getPost();
 $commentId = $commentaire->getId();
    $post = $commentaire->getPost();
    $postId = $post?->getId();
    $em->remove($commentaire);

    // décrémenter compteur
    if ($post) {
       $post->setNbrCommentaires(
    max(0, ((int) $post->getNbrCommentaires()) - 1)
);
    }

    $em->flush();

    $this->addFlash('success', 'Commentaire supprimé ✅');

   return $this->redirectToRoute('post_index', [
    'id' => $postId,
    'ok' => true,
]);
}



  #[Route('/add-inline/{id}', name: 'commentaire_add_inline', methods: ['POST'])]
public function addInline(
    Post $post,
    Request $request,
    EntityManagerInterface $em,
    AiCommentModerationService $moderation
): Response
{
    // CSRF (tu peux ajouter le check ici si tu veux, je ne touche pas)

    $contenu = trim((string) $request->request->get('contenu'));
    $postId  = (string) $post->getId();

    // ✅ NEW: récupérer la redirection voulue (index ou show)
    $redirect = (string) $request->request->get('redirect', '');

    // ✅ helper: fallback si redirect vide / invalide
    $fallback = $this->generateUrl('post_index');

    // ✅ sécurité simple : on autorise uniquement un chemin interne "/..."
    if ($redirect === '' || !str_starts_with($redirect, '/')) {
        $redirect = $fallback;
    }

    // ✅ 1) pas vide
    if ($contenu === '') {
        $this->addFlash('comment_error', $postId.'|Le commentaire ne peut pas être vide.');
        return $this->redirect($redirect);
    }

    // ✅ 2) doit commencer par une lettre (unicode)
    if (!preg_match('/^\p{L}/u', $contenu)) {
        $this->addFlash('comment_error', $postId.'|Le commentaire doit commencer par une lettre.');
        return $this->redirect($redirect);
    }

    // ✅ 3) doit contenir au moins 2 lettres
    preg_match_all('/\p{L}/u', $contenu, $matches);
    if (count($matches[0]) < 2) {
        $this->addFlash('comment_error', $postId.'|Le commentaire doit contenir au moins 2 lettres.');
        return $this->redirect($redirect);
    }

    // ✅ OK -> enregistrer
    $commentaire = new Commentaire();
    $commentaire->setPost($post);
    $commentaire->setContenu($contenu);
    $commentaire->setDateCreation(new \DateTimeImmutable());

    // Assigner l'utilisateur connecté (required)
    $user = $this->getUser();

if (!$user instanceof User) {
    $this->addFlash(
        'comment_error',
        $postId . '|Vous devez être connecté pour ajouter un commentaire.'
    );

    return $this->redirectToRoute('app_login');
}

$commentaire->setUser($user);

    $decision = $moderation->moderate($contenu);

    $commentaire->setModerationScore($decision['score']);
    $commentaire->setModerationLabel($decision['label']);
    $commentaire->markAsModerated();

    if (!$decision['allow']) {
        $commentaire->setStatus('blocked');
        $this->addFlash('comment_error', $postId.'|Commentaire bloqué automatiquement (IA: '.$decision['label'].' / score '.$decision['score'].').');
        // On enregistre quand même le commentaire en "blocked" :
    } else {
        $commentaire->setStatus('published');
    }

    $em->persist($commentaire);

    if ($commentaire->getStatus() === 'published') {
        $post->setNbrCommentaires(((int) $post->getNbrCommentaires()) + 1);
    }

   $em->flush();

if ($request->headers->has('Turbo-Frame')) {
    // important: renvoyer le frame complet
    return $this->render('commentaire/_frame.html.twig', [
        'p' => $post,
        'commentErrors' => [],
    ]);
}

return $this->redirectToRoute('post_index');
}

#[Route('/inline-edit/{id}', name: 'commentaire_inline_edit', methods: ['POST'])]
public function inlineEdit(
    Request $request,
    Commentaire $commentaire,
    EntityManagerInterface $em,
    AiCommentModerationService $moderation
): JsonResponse {

    $user = $this->getUser();

    // ✅ Autorisation
    if (!$user || (!$this->isGranted('ROLE_ADMIN') && $commentaire->getUser() !== $user)) {
        return new JsonResponse(['ok' => false, 'message' => 'Accès refusé.'], 403);
    }

    // ✅ CSRF
    if (!$this->isCsrfTokenValid(
        'edit_commentaire_' . $commentaire->getId(),
        (string) $request->request->get('_token')
    )) {
        return new JsonResponse(['ok' => false, 'message' => 'CSRF invalide.'], 403);
    }

    $contenu = trim((string) $request->request->get('contenu'));

    // ✅ Validation
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

    // ✅ IA moderation
    $decision = $moderation->moderate($contenu);

    $commentaire->setModerationScore($decision['score']);
    $commentaire->setModerationLabel($decision['label']);
    $commentaire->markAsModerated();

    if (!$decision['allow']) {
        $commentaire->setStatus('blocked');

        return new JsonResponse([
            'ok' => false,
            'message' => 'Commentaire bloqué automatiquement (IA: '
                . $decision['label']
                . ' / score '
                . $decision['score']
                . ')'
        ], 422);
    }

    $commentaire->setStatus('published');
    $commentaire->setContenu($contenu);

    $em->flush();

    return new JsonResponse([
        'ok' => true,
        'commentId' => $commentaire->getId(),
        'contenu' => $commentaire->getContenu(),
    ]);
}

}