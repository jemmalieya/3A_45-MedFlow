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

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


use Dompdf\Dompdf;
use Dompdf\Options;



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
    // ✅ ADMIN LIST TABLE + TRI
#[Route('/admin/evenements', name: 'admin_evenement_index', methods: ['GET'])]
public function adminIndex(Request $request, EvenementRepository $repo): Response
{
    $sort = $request->query->get('sort', 'date_desc'); // default

    $orderBy = ['date_debut_event' => 'DESC'];

    switch ($sort) {
        case 'date_asc':
            $orderBy = ['date_debut_event' => 'ASC'];
            break;

        case 'titre_asc':
            $orderBy = ['titre_event' => 'ASC'];
            break;

        case 'titre_desc':
            $orderBy = ['titre_event' => 'DESC'];
            break;

        case 'ville_asc':
            $orderBy = ['ville_event' => 'ASC'];
            break;

        case 'type_asc':
            $orderBy = ['type_event' => 'ASC'];
            break;

        // statut: Published first (custom, métier)
        case 'statut_custom':
            $evenements = $repo->findAllSortedByStatutCustom();
            return $this->render('admin/adminEvent_index.html.twig', [
                'evenements' => $evenements,
                'sort' => $sort,
            ]);
    }

    $evenements = $repo->findBy([], $orderBy);

    return $this->render('admin/adminEvent_index.html.twig', [
        'evenements' => $evenements,
        'sort' => $sort,
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

        return $this->render('admin/newEvents.html.twig', [
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

        return $this->render('admin/editEvents.html.twig', [
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
             return $this->render('admin/cardsEvents.html.twig', [
                    'evenements' => $evenements,
                    ]);
    }
    // ✅ ADMIN SHOW (SB Admin2)
    #[Route('/admin/evenements/{id}', name: 'admin_evenement_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function adminShow(Evenement $evenement): Response
    {
          return $this->render('admin/showEvents_adm.html.twig', [
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

    return $this->render('admin/demandesEvents_index.html.twig', [
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

    return $this->render('admin/demandesEvents_show.html.twig', [
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
    EntityManagerInterface $em,
    MailerInterface $mailer
): Response {
    $status = $request->request->get('status'); // accepted / refused
    $note = $request->request->get('note');

    // 1) récupérer la demande depuis le JSON (pour connaitre email/nom)
    $demandes = $evenement->getDemandesJson();
    $demandeFound = null;

    foreach ($demandes as $d) {
        if (($d['id'] ?? null) == $demandeId) {
            $demandeFound = $d;
            break;
        }
    }

    if (!$demandeFound) {
        $this->addFlash('danger', "Demande introuvable.");
        return $this->redirectToRoute('admin_evenement_demandes_show', ['id' => $evenement->getId()]);
    }

    $nom = $demandeFound['nom'] ?? 'Participant';
    $emailTo = $demandeFound['email'] ?? null;

    if (!$emailTo) {
        $this->addFlash('danger', "Email du participant introuvable.");
        return $this->redirectToRoute('admin_evenement_demandes_show', ['id' => $evenement->getId()]);
    }

    try {
        // 2) décision + save
        $evenement->decideDemande($demandeId, $status, 'admin', $note);
        $evenement->setDateMiseAJourEvent(new \DateTime());
        $em->flush();

        // 3) Email + QR si accepté
        $eventTitre = $evenement->getTitreEvent();
        $dates = $evenement->getDateDebutEvent()->format('d/m/Y') . " → " . $evenement->getDateFinEvent()->format('d/m/Y');

        if ($status === 'accepted') {

    $checkUrl = $this->generateUrl(
        'participation_check',
        ['id' => $evenement->getId(), 'demandeId' => $demandeId],
        UrlGeneratorInterface::ABSOLUTE_URL
    );

    $qrUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($checkUrl);

    $qrImage = @file_get_contents($qrUrl);
    $qrBase64 = $qrImage ? 'data:image/png;base64,' . base64_encode($qrImage) : null;

    $email = (new Email())
        ->from('mayssemmanai175@gmail.com')


        ->to($emailTo)
        ->subject('✅ Participation acceptée - ' . $eventTitre)
        ->html("
            <h2>Bonjour $nom,</h2>
            <p>Votre demande de participation à <b>$eventTitre</b> a été <b style='color:green;'>acceptée</b>.</p>
            <p><b>Dates :</b> $dates</p>

            <p>✅ Voici votre QR Code (ticket) :</p>
            " . ($qrBase64
                ? "<p><img src='$qrBase64' width='220' alt='QR Code'></p>"
                : "<p style='color:red'>Impossible de générer le QR Code.</p>"
            ) . "

            <p>Ou cliquez ici : <a href='$checkUrl'>$checkUrl</a></p>
            <p style='color:#666'>MedFlow</p>
        ");

    $mailer->send($email);
}
elseif ($status === 'refused') {

            // ====== Email REFUSÉ ======
            $reason = $note ? "<p><b>Note admin :</b> $note</p>" : "";

            $email = (new Email())
                ->from('mayssemmanai175@gmail.com')

                ->to($emailTo)
                ->subject('❌ Participation refusée - ' . $eventTitre)
                ->html("
                    <h2>Bonjour $nom,</h2>
                    <p>Votre demande de participation à <b>$eventTitre</b> a été <b style='color:#b00020;'>refusée</b>.</p>
                    $reason
                    <p><b>Dates :</b> $dates</p>
                    <p style='color:#666'>MedFlow</p>
                ");

            $mailer->send($email);
        }

        $this->addFlash('success', "Décision enregistrée ✅ Email envoyé.");

    } catch (\Throwable $e) {
        $this->addFlash('danger', $e->getMessage());
    }

    return $this->redirectToRoute('admin_evenement_demandes_show', ['id' => $evenement->getId()]);
}


  #[Route('/participation/check/{id}/{demandeId}', name: 'participation_check', requirements: ['id' => '\d+'], methods: ['GET'])]
public function checkParticipation(Evenement $evenement, string $demandeId): Response
{
    $demandes = $evenement->getDemandesJson();
    $demandeFound = null;

    foreach ($demandes as $d) {
        if (($d['id'] ?? null) == $demandeId) {
            $demandeFound = $d;
            break;
        }
    }

    if (!$demandeFound) {
        throw $this->createNotFoundException("QR invalide ou demande introuvable.");
    }

    return $this->render('evenement/participation_check.html.twig', [
        'evenement' => $evenement,
        'demande' => $demandeFound,
    ]);
}
   #[Route('/admin/evenements/stats', name: 'admin_evenements_stats', methods: ['GET'])]
public function statsPage(): Response
{
    return $this->render('admin/evenements_stats.html.twig');
}
#[Route('/admin/evenements/stats/data', name: 'admin_evenements_stats_data', methods: ['GET'])]
public function statsData(EvenementRepository $repo): JsonResponse
{
    $events = $repo->findAll();

    $byType = [];
    $byVille = [];
    $byStatut = [];
    $demandes = ['total'=>0,'accepted'=>0,'pending'=>0,'refused'=>0];

    foreach ($events as $ev) {
        $type = $ev->getTypeEvent() ?? 'N/A';
        $ville = $ev->getVilleEvent() ?? 'N/A';
        $statut = $ev->getStatutEvent() ?? 'N/A';

        $byType[$type] = ($byType[$type] ?? 0) + 1;
        $byVille[$ville] = ($byVille[$ville] ?? 0) + 1;
        $byStatut[$statut] = ($byStatut[$statut] ?? 0) + 1;

        foreach ($ev->getDemandesJson() as $d) {
            $demandes['total']++;
            $s = $d['status'] ?? 'pending';
            if (isset($demandes[$s])) $demandes[$s]++;
        }
    }

    // ✅ Calcul % (safe si total=0)
    $total = max(1, $demandes['total']);

    $demandesPercent = [
        'accepted' => round(($demandes['accepted'] / $total) * 100, 1),
        'pending'  => round(($demandes['pending'] / $total) * 100, 1),
        'refused'  => round(($demandes['refused'] / $total) * 100, 1),
    ];

    return $this->json([
        'byType' => $byType,
        'byVille' => $byVille,
        'byStatut' => $byStatut,
        'demandes' => $demandes,
        'demandesPercent' => $demandesPercent, // ✅ nouveau
    ]);
}   


    #[Route('/admin/evenements/{id}/pdf', name: 'admin_evenement_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
public function exportEvenementPdf(Evenement $evenement): Response
{
    // ✅ 1) Générer un lien (front show) pour QR
    // (si tu veux autre route, change ici)
    $frontUrl = $this->generateUrl(
        'app_evenement_show',
        ['id' => $evenement->getId()],
        UrlGeneratorInterface::ABSOLUTE_URL
    );

    // ✅ 2) QR base64 (comme tu fais déjà)
    $qrUrl = 'https://chart.googleapis.com/chart?chs=250x250&cht=qr&chl=' . urlencode($frontUrl);
    $qrImage = @file_get_contents($qrUrl);
    $qrBase64 = $qrImage ? 'data:image/png;base64,' . base64_encode($qrImage) : null;

    // ✅ 3) HTML du PDF (Twig)
    $html = $this->renderView('admin/evenement_fiche.html.twig', [
        'evenement' => $evenement,
        'qrBase64' => $qrBase64,
        'frontUrl' => $frontUrl,
        'generatedAt' => new \DateTime(),
    ]);

    // ✅ 4) Dompdf config
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->setIsRemoteEnabled(true); // utile si tu utilises des images externes

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'fiche_evenement_' . $evenement->getId() . '.pdf';

    return new Response(
        $dompdf->output(),
        200,
        [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"'
        ]
    );
}

    

               
}
