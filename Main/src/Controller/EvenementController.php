<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Form\EvenementType;
use App\Repository\EvenementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;


class EvenementController extends AbstractController
{
    // ✅ FRONT LIST
    #[Route('/evenements', name: 'app_evenements', methods: ['GET'])]
    public function index(EvenementRepository $repo): Response
    {
        $evenements = $repo->findBy([], ['date_debut_event' => 'DESC']);

        return $this->render('evenement/index.html.twig', [
            'evenements' => $evenements,
        ]);
    }

    // ✅ FRONT SHOW
    #[Route('/evenements/{id}', name: 'app_evenement_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Evenement $evenement): Response
    {
        return $this->render('evenement/show.html.twig', [
            'evenement' => $evenement,
        ]);
    }

    // ✅ ADMIN LIST TABLE
    #[Route('/admin/evenements', name: 'admin_evenement_index', methods: ['GET'])]
    public function adminIndex(EvenementRepository $repo): Response
    {
        $evenements = $repo->findBy([], ['date_debut_event' => 'DESC']);

        return $this->render('evenement/admin_index.html.twig', [
            'evenements' => $evenements,
        ]);
    }

    // ✅ ADMIN ADD
    #[Route('/admin/evenements/new', name: 'admin_evenement_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $evenement = new Evenement();
        $evenement->setDateCreationEvent(new \DateTime());
        $evenement->setDateMiseAJourEvent(new \DateTime());

        $form = $this->createForm(EvenementType::class, $evenement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $evenement->setDateMiseAJourEvent(new \DateTime());
            $em->persist($evenement);
            $em->flush();

            $this->addFlash('success', 'Événement ajouté avec succès ✅');
            return $this->redirectToRoute('admin_evenement_index');
        }

        return $this->render('evenement/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    // ✅ ADMIN EDIT
    #[Route('/admin/evenements/{id}/edit', name: 'admin_evenement_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Evenement $evenement, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(EvenementType::class, $evenement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $evenement->setDateMiseAJourEvent(new \DateTime());
            $em->flush();

            $this->addFlash('success', 'Événement modifié ✅');
            return $this->redirectToRoute('admin_evenement_index');
        }

        return $this->render('evenement/edit.html.twig', [
            'evenement' => $evenement,
            'form' => $form->createView(),
        ]);
    }

    // ✅ ADMIN DELETE
    #[Route('/admin/evenements/{id}/delete', name: 'admin_evenement_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Evenement $evenement, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_evenement_'.$evenement->getId(), $request->request->get('_token'))) {
            $em->remove($evenement);
            $em->flush();
            $this->addFlash('success', 'Événement supprimé 🗑️');
        }

        return $this->redirectToRoute('admin_evenement_index');
    }

    // ✅ ADMIN CARDS (SB Admin2)
    #[Route('/admin/evenements/cards', name: 'admin_evenement_cards', methods: ['GET'])]
    public function adminCards(EvenementRepository $repo): Response
    {
           $evenements = $repo->findBy([], ['date_debut_event' => 'DESC']);
             return $this->render('evenement/cards.html.twig', [
                    'evenements' => $evenements,
                    ]);
    }
    // ✅ ADMIN SHOW (SB Admin2)
    #[Route('/admin/evenements/{id}', name: 'admin_evenement_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function adminShow(Evenement $evenement): Response
    {
          return $this->render('evenement/show_adm.html.twig', [
                  'evenement' => $evenement,
                    ]);
    }





    #[Route('/evenements/{id}/demander', name: 'app_evenement_demander', requirements: ['id' => '\d+'], methods: ['POST'])]
public function demanderParticipation(Request $request, Evenement $evenement, EntityManagerInterface $em): Response
{
    if (!$evenement->canReceiveDemandes()) {
        $this->addFlash('danger', "Les demandes sont fermées pour cet événement.");
        return $this->redirectToRoute('app_evenement_show', ['id' => $evenement->getId()]);
    }

    $payload = [
        'nom' => $request->request->get('nom'),
        'email' => $request->request->get('email'),
        'tel' => $request->request->get('tel'),
        'message' => $request->request->get('message'),
    ];

    try {
        $evenement->addDemande($payload);
        $evenement->setDateMiseAJourEvent(new \DateTime());
        $em->flush();

        $this->addFlash('success', "Demande envoyée ✅ (en attente de validation admin)");
    } catch (\Throwable $e) {
        $this->addFlash('danger', $e->getMessage());
    }

    return $this->redirectToRoute('app_evenement_show', ['id' => $evenement->getId()]);
}

// ✅ Admin: liste des événements avec badge demandes
#[Route('/admin/evenements/demandes', name: 'admin_evenement_demandes_index', methods: ['GET'])]
public function demandesIndex(EvenementRepository $repo): Response
{
    $events = $repo->findBy([], ['date_debut_event' => 'DESC']);

    // total pending (pour badge sidebar si tu veux l’afficher)
    $totalPending = 0;
    foreach ($events as $ev) {
        $totalPending += $ev->countDemandesByStatus('pending');
    }

    return $this->render('evenement/demandes_index.html.twig', [
        'events' => $events,
        'totalPending' => $totalPending,
    ]);
}

// ✅ Admin: voir demandes d’un événement
#[Route('/admin/evenements/{id}/demandes', name: 'admin_evenement_demandes_show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function demandesShow(Evenement $evenement): Response
{
    $demandes = $evenement->getDemandesJson();

    // tri: pending d’abord, puis plus récent
    usort($demandes, function($a, $b) {
        $sa = $a['status'] ?? 'pending';
        $sb = $b['status'] ?? 'pending';
        if ($sa !== $sb) return $sa === 'pending' ? -1 : 1;
        return strcmp(($b['created_at'] ?? ''), ($a['created_at'] ?? ''));
    });

    return $this->render('evenement/demandes_show.html.twig', [
        'evenement' => $evenement,
        'demandes' => $demandes,
        'acceptedCount' => $evenement->countAcceptedDemandes(),
        'pendingCount' => $evenement->countDemandesByStatus('pending'),
    ]);
}

// ✅ Admin: accepter/refuser
#[Route('/admin/evenements/{id}/demandes/{demandeId}/decide', name: 'admin_evenement_demandes_decide', requirements: ['id' => '\d+'], methods: ['POST'])]
public function decideDemande(
    Request $request,
    Evenement $evenement,
    string $demandeId,
    EntityManagerInterface $em
): Response {
    $status = $request->request->get('status'); // accepted / refused
    $note = $request->request->get('note');

    try {
        $evenement->decideDemande($demandeId, $status, 'admin', $note);
        $evenement->setDateMiseAJourEvent(new \DateTime());
        $em->flush();

        $this->addFlash('success', "Décision enregistrée ✅");
    } catch (\Throwable $e) {
        $this->addFlash('danger', $e->getMessage());
    }

    return $this->redirectToRoute('admin_evenement_demandes_show', ['id' => $evenement->getId()]);
}




 
               
}
