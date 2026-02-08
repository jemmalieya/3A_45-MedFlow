<?php

namespace App\Controller;

use App\Entity\Reclamation;
use App\Entity\ReponseReclamation;
use App\Form\ReponseReclamationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\ReponseReclamationRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

class ReponseController extends AbstractController
{
   #[Route('/admin/reponse/reclamation/{id}', name: 'admin_reponses_by_reclamation', methods: ['GET'])]
public function reponsesByReclamation(
    Reclamation $reclamation,
    ReponseReclamationRepository $repo
): Response {
    $reponses = $repo->findBy(
        ['reclamation' => $reclamation],
        ['date_creation_rep' => 'DESC']
    );

    return $this->render('admin/reponses.html.twig', [
        'reclamation' => $reclamation,
        'reponses' => $reponses,
    ]);
}

    #[Route('/admin/reclamations', name: 'admin_reclamations')]
public function reclamations(EntityManagerInterface $em): Response
{
    $reclamations = $em->getRepository(Reclamation::class)->findBy([], [
        'date_creation_r' => 'DESC'
    ]);

    return $this->render('admin/reclamations.html.twig', [
        'reclamations' => $reclamations,
    ]);
}
#[Route('/admin/reponse', name: 'admin_reponses', methods: ['GET'])]
public function index(EntityManagerInterface $em): Response
{
    $reponses = $em->getRepository(ReponseReclamation::class)->findBy([], [
        'date_creation_rep' => 'DESC'
    ]);

    return $this->render('admin/reponses.html.twig', [
        'reclamation' => null,     // ✅ IMPORTANT
        'reponses' => $reponses,
    ]);
}


    /**
     * ✍️ Répondre à une réclamation
     */
    #[Route('/repondre/{id}', name: 'admin_reponse_new')]
    public function repondre(
        Reclamation $reclamation,
        Request $request,
        ReponseReclamationRepository $repo ,
        EntityManagerInterface $em
    ): Response {
        $reponse = new ReponseReclamation();

        // liaison réponse ↔ réclamation
        $reponse->setReclamation($reclamation);

        $form = $this->createForm(ReponseReclamationType::class, $reponse);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
              // 🔍 vérifier s’il existe au moins une REPONSE
    $hasReponse = $repo->count([
        'reclamation' => $reclamation,
        'typeReponse' => 'REPONSE'
    ]) > 0 || $reponse->getTypeReponse() === 'REPONSE';

    $reclamation->setStatutReclamation($hasReponse ? 'TRAITEE' : 'En attente');


            $em->persist($reponse);
            $em->flush();

            $this->addFlash('admin_success', 'Réponse envoyée avec succès.');

            return $this->redirectToRoute('admin_reclamations');
        }

        return $this->render('admin/repondre.html.twig', [
            'form' => $form->createView(),
            'reclamation' => $reclamation,
        ]);
    }

    /**
     * ✏️ Modifier une réponse
     */
   #[Route('/modifier/{id}', name: 'admin_reponse_edit')]
public function edit(
    ReponseReclamation $reponse,
    Request $request,
    EntityManagerInterface $em,
    ReponseReclamationRepository $repo
): Response {
    $form = $this->createForm(ReponseReclamationType::class, $reponse);
    $form->handleRequest($request);

    $reclamation = $reponse->getReclamation();

    if ($form->isSubmitted() && $form->isValid()) {

        // ✅ Vérifier si la réclamation a au moins une réponse de type REPONSE
        $hasReponse = $repo->count([
            'reclamation' => $reclamation,
            'typeReponse' => 'REPONSE'
        ]) > 0;

        $reclamation->setStatutReclamation($hasReponse ? 'TRAITEE' : 'En attente');

        $em->flush();
        $this->addFlash('admin_success', 'Réponse modifiée avec succès.');

        return $this->redirectToRoute('admin_reponses');
    }

    return $this->render('admin/edit_reponse.html.twig', [
        'form' => $form->createView(),
        'reponse' => $reponse,
    ]);
}

#[Route('/admin/reponse/supprimer/{id}', name: 'admin_reponse_delete', methods: ['POST'])]
public function delete(
    ReponseReclamation $reponse,
    Request $request,
    EntityManagerInterface $em,
    ReponseReclamationRepository $repo
): Response {
    if (!$this->isCsrfTokenValid(
        'delete_reponse_' . $reponse->getIdReponse(),
        $request->request->get('_token')
    )) {
        $this->addFlash('admin_danger', 'Token CSRF invalide.');
        return $this->redirectToRoute('admin_reponses');
    }

    $reclamation = $reponse->getReclamation();

    // 🔥 suppression
    $em->remove($reponse);
    $em->flush();

    // 🔎 compter les réponses restantes
    $totalReponses = $repo->count([
        'reclamation' => $reclamation
    ]);

    // 🔎 compter les réponses de type REPONSE restantes
    $nbReponsesTypeREPONSE = $repo->count([
        'reclamation' => $reclamation,
        'typeReponse' => 'REPONSE'
    ]);

    // ✅ LOGIQUE STATUT
    if ($totalReponses === 0) {
        // ❌ aucune réponse → EN_ATTENTE
        $reclamation->setStatutReclamation('En attente');
        $reclamation->setDateClotureR(null);
    } elseif ($nbReponsesTypeREPONSE > 0) {
        // ✅ au moins une REPONSE → TRAITEE
        $reclamation->setStatutReclamation('TRAITEE');
    } else {
        // ⚠️ réponses existent mais aucune REPONSE
        $reclamation->setStatutReclamation('En attente');
        $reclamation->setDateClotureR(null);
    }

    $em->flush();

    $this->addFlash('admin_success', 'Réponse supprimée avec succès.');

    return $this->redirectToRoute('admin_reponses_by_reclamation', [
        'id' => $reclamation->getIdReclamation()
    ]);
}


}
