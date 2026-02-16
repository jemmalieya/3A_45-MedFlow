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

use App\Service\VonageSmsService;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Service\WeatherService;   




use Symfony\Component\HttpFoundation\JsonResponse;


class EvenementController extends AbstractController
{


    
#[Route('/evenements', name: 'app_evenements', methods: ['GET'])]
public function index(EvenementRepository $repo): Response
{
    // ✅ Ta liste reste triée par date de début (comme avant)
    $evenements = $repo->findBy([], ['date_debut_event' => 'DESC']);

    // ✅ Dernier événement AJOUTÉ (tri par date_creation_event)
    $latestCreated = $repo->findOneBy([], ['date_creation_event' => 'DESC']);

    $hasNew = false;
    $latestNewTitle = null;

    if ($latestCreated && $latestCreated->getDateCreationEvent()) {
        $limit = new \DateTime('-2 days');

        if ($latestCreated->getDateCreationEvent() > $limit) {
            $hasNew = true;
            $latestNewTitle = $latestCreated->getTitreEvent();
        }
    }

    return $this->render('evenement/index.html.twig', [
        'evenements' => $evenements,
        'hasNew' => $hasNew,
        'latestNewTitle' => $latestNewTitle,
    ]);
}

#[Route('/evenements/{id}', name: 'app_evenement_show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function show(
    Evenement $evenement,
    WeatherService $weather,
    EvenementRepository $repo
): Response
{
    // =============================
    // 1) METEO
    // =============================
    $meteo = null;
    try {
        $meteo = $weather->getWeather($evenement->getVilleEvent());
    } catch (\Throwable $e) {
        $meteo = null;
    }

    // =============================
    // 2) RECO IA (scoring + user prefs)
    // =============================
    $user = $this->getUser();

    // retourne une liste d'Evenement (déjà filtrée/triée côté repo)
    $recs = $repo->findRecommendedForUser(
        $evenement,
        ($user instanceof \App\Entity\User) ? $user : null,
        6
    );

    // =============================
    // 3) Construire "recommended" : score + raisons + popularité
    // =============================
    $recommended = [];
    $now = new \DateTime();

    foreach ($recs as $ev) {
        $score = 0;
        $reasons = [];

        // Même type (+3)
        if ($evenement->getTypeEvent() && $ev->getTypeEvent() === $evenement->getTypeEvent()) {
            $score += 3;
            $reasons[] = 'Même type';
        }

        // Même ville (+2)
        if ($evenement->getVilleEvent() && $ev->getVilleEvent() === $evenement->getVilleEvent()) {
            $score += 2;
            $reasons[] = 'Même ville';
        }

        // Proche en date (+1) si <= 30 jours
        $d = $ev->getDateDebutEvent();
        if ($d instanceof \DateTimeInterface) {
            $diffDays = (int) $now->diff($d)->format('%r%a');
            if ($diffDays >= 0 && $diffDays <= 30) {
                $score += 1;
                $reasons[] = 'Date proche';
            }
        }

        // Populaire (+2) si >= 3 demandes acceptées
        $accepted = method_exists($ev, 'countDemandesByStatus') ? (int) $ev->countDemandesByStatus('accepted') : 0;
        if ($accepted >= 3) {
            $score += 2;
            $reasons[] = 'Populaire';
        }

        // (Optionnel) si score = 0, tu peux ignorer
        // if ($score === 0) continue;

        $recommended[] = [
            'event' => $ev,
            'score' => $score,        // max 8
            'accepted' => $accepted,
            'reasons' => $reasons,
        ];
    }

    // Trier score DESC
    usort($recommended, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    // =============================
    // 4) Render
    // =============================
    return $this->render('evenement/show.html.twig', [
        'evenement' => $evenement,
        'meteo' => $meteo,

        // ⚠️ On envoie "recommended" (tableau riche) au lieu de "recommendations"
        'recommended' => $recommended,
    ]);
}



    
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

  
    #[Route('/admin/evenements/cards', name: 'admin_evenement_cards', methods: ['GET'])]
    public function adminCards(EvenementRepository $repo): Response
    {
           $evenements = $repo->findBy([], ['date_debut_event' => 'DESC']);
             return $this->render('admin/cardsEvents.html.twig', [
                    'evenements' => $evenements,
                    ]);
    }
   
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

    $user = $this->getUser();

    // ✅ Si tu veux obliger la connexion, décommente ceci :
    // if (!$user) {
    //     $this->addFlash('danger', "Veuillez vous connecter pour envoyer une demande.");
    //     return $this->redirectToRoute('app_login'); // adapte à ta route login
    // }

    if ($user instanceof \App\Entity\User) {
        // ✅ On force depuis la base (user connecté)
        $payload = [
            'nom'     => trim($user->getNom() . ' ' . $user->getPrenom()),
            'email'   => $user->getEmailUser(),
            'tel'     => $user->getTelephoneUser(),
            'message' => (string) $request->request->get('message', ''),
        ];
    } else {
        // (optionnel) invités
        $payload = [
            'nom'     => (string) $request->request->get('nom', ''),
            'email'   => (string) $request->request->get('email', ''),
            'tel'     => (string) $request->request->get('tel', ''),
            'message' => (string) $request->request->get('message', ''),
        ];
    }

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


#[Route('/admin/evenements/demandes', name: 'admin_evenement_demandes_index', methods: ['GET'])]
public function demandesIndex(EvenementRepository $repo): Response
{
    $events = $repo->findBy([], ['date_debut_event' => 'DESC']);

    
    $totalPending = 0;
    foreach ($events as $ev) {
        $totalPending += $ev->countDemandesByStatus('pending');
    }

    return $this->render('admin/demandesEvents_index.html.twig', [
        'events' => $events,
        'totalPending' => $totalPending,
    ]);
}

#[Route('/admin/evenements/{id}/demandes', name: 'admin_evenement_demandes_show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function demandesShow(Evenement $evenement, EvenementRepository $repo): Response
{
    $demandes = $evenement->getDemandesJson();

    usort($demandes, function($a, $b) {
        $sa = $a['status'] ?? 'pending';
        $sb = $b['status'] ?? 'pending';
        if ($sa !== $sb) return $sa === 'pending' ? -1 : 1;
        return strcmp(($b['created_at'] ?? ''), ($a['created_at'] ?? ''));
    });

    // ✅ IA Risk ici
    $riskData = $this->calculateRiskScore($evenement, $repo);

    return $this->render('admin/demandesEvents_show.html.twig', [
        'evenement' => $evenement,
        'demandes' => $demandes,
        'acceptedCount' => $evenement->countAcceptedDemandes(),
        'pendingCount' => $evenement->countDemandesByStatus('pending'),
        'riskData' => $riskData, // ✅ IMPORTANT
    ]);
}


#[Route('/admin/evenements/{id}/demandes/{demandeId}/decide', name: 'admin_evenement_demandes_decide', requirements: ['id' => '\d+'], methods: ['POST'])]
public function decideDemande(
    Request $request,
    Evenement $evenement,
    string $demandeId,
    EntityManagerInterface $em,
    VonageSmsService $sms
): Response {
    $status = (string) $request->request->get('status', 'pending'); // accepted / refused
    $note   = trim((string) $request->request->get('note', ''));

    // 1) chercher la demande dans JSON
    $demandes = $evenement->getDemandesJson();
    $index = null;

    foreach ($demandes as $i => $d) {
        if (($d['id'] ?? null) == $demandeId) {
            $index = $i;
            break;
        }
    }

    if ($index === null) {
        $this->addFlash('danger', "Demande introuvable.");
        return $this->redirectToRoute('admin_evenement_demandes_show', ['id' => $evenement->getId()]);
    }

    $demande = $demandes[$index];
    $nom = $demande['nom'] ?? 'Participant';
    $tel = $demande['tel'] ?? null;

    if (!$tel) {
        $this->addFlash('danger', "Téléphone du participant introuvable.");
        return $this->redirectToRoute('admin_evenement_demandes_show', ['id' => $evenement->getId()]);
    }

   
    $tel = trim((string)$tel);
    if ($tel && $tel[0] !== '+') {
       
        $tel = '+' . $tel;
    }

    
    $demandes[$index]['status'] = $status;
    $demandes[$index]['admin_note'] = $note;
    $demandes[$index]['decided_at'] = (new \DateTime())->format('Y-m-d H:i:s');

   
    $alreadyHadTicket = !empty($demandes[$index]['ticket_code']);

    if ($status === 'accepted' && !$alreadyHadTicket) {
        $ticketCode = strtoupper('MF-' . $evenement->getId() . '-' . substr(md5(uniqid('', true)), 0, 6));
        $demandes[$index]['ticket_code'] = $ticketCode;
        $demandes[$index]['ticket_used'] = false; // optionnel
    }

    try {
        
        $evenement->setDemandesJsonArray($demandes);
        $evenement->setDateMiseAJourEvent(new \DateTime());

        $em->flush();

        $eventTitre = (string) $evenement->getTitreEvent();
        $dates = $evenement->getDateDebutEvent()->format('d/m/Y') . " -> " . $evenement->getDateFinEvent()->format('d/m/Y');

    
        if ($status === 'accepted') {
            $ticketCode = $demandes[$index]['ticket_code'] ?? '';
            $msg = "MedFlow\nParticipation ACCEPTEE\nEvent: $eventTitre\nDates: $dates\nCode: $ticketCode";

          
            if (!$alreadyHadTicket) {
                $smsResult = $sms->sendSms($tel, $msg);

               
                $vonageStatus = $smsResult['messages'][0]['status'] ?? null;
                if ((string)$vonageStatus === '0') {
                    $this->addFlash('success', "✅ Acceptée + SMS envoyé.");
                } else {
                    $err = $smsResult['messages'][0]['error-text'] ?? 'SMS non délivré';
                    $this->addFlash('warning', "✅ Acceptée SMS : $err");
                }
            } else {
                $this->addFlash('success', "✅ Acceptée (ticket déjà généré, SMS non renvoyé).");
            }

        } elseif ($status === 'refused') {

            $reason = $note ? "Raison: $note" : "";
            $msg = "MedFlow\nParticipation REFUSEE\nEvent: $eventTitre\n$reason";

            $smsResult = $sms->sendSms($tel, $msg);
            $vonageStatus = $smsResult['messages'][0]['status'] ?? null;

            if ((string)$vonageStatus === '0') {
                $this->addFlash('success', "❌ Refusée + SMS envoyé.");
            } else {
                $err = $smsResult['messages'][0]['error-text'] ?? 'SMS non délivré';
                $this->addFlash('warning', "❌ RefuséeSMS : $err");
            }

        } else {
            $this->addFlash('success', "Décision enregistrée ✅");
        }

    } catch (\Throwable $e) {
        $this->addFlash('danger', "Erreur: " . $e->getMessage());
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
        'demandesPercent' => $demandesPercent, 
    ]);
}   


    #[Route('/admin/evenements/{id}/pdf', name: 'admin_evenement_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
public function exportEvenementPdf(Evenement $evenement): Response
{
   
    $frontUrl = $this->generateUrl(
        'app_evenement_show',
        ['id' => $evenement->getId()],
        UrlGeneratorInterface::ABSOLUTE_URL
    );

    
    $qrUrl = 'https://chart.googleapis.com/chart?chs=250x250&cht=qr&chl=' . urlencode($frontUrl);
    $qrImage = @file_get_contents($qrUrl);
    $qrBase64 = $qrImage ? 'data:image/png;base64,' . base64_encode($qrImage) : null;

    $html = $this->renderView('admin/evenement_fiche.html.twig', [
        'evenement' => $evenement,
        'qrBase64' => $qrBase64,
        'frontUrl' => $frontUrl,
        'generatedAt' => new \DateTime(),
    ]);


    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->setIsRemoteEnabled(true);

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

    
  #[Route('/test-email', name: 'test_email')]
public function testEmail(MailerInterface $mailer): Response
{
    $email = (new Email())
        ->from('mayssemmanai175@gmail.com')   // doit être autorisé dans Brevo
        ->to('mayssemmanai175@gmail.com')          // mets ton vrai email
        ->subject('✅ Test Email Brevo Symfony')
        ->text('Si tu reçois cet email, Brevo + Symfony fonctionnent 🎉');

    $mailer->send($email);

    return new Response('Email envoyé ✅ Vérifie ta boîte mail');
}

               

/* =========================================================
 *  CALENDRIER FRONT (USER) : page + data JSON
 * ========================================================= */

#[Route('/evenements/calendar', name: 'app_evenements_calendar', methods: ['GET'])]
public function calendarFront(Request $request, EvenementRepository $repo): Response
{
    // Pour remplir les listes des filtres
    $events = $repo->findAll();

    $villes = [];
    $types = [];
    $statuts = [];

    foreach ($events as $e) {
        if ($e->getVilleEvent())   $villes[$e->getVilleEvent()] = true;
        if ($e->getTypeEvent())    $types[$e->getTypeEvent()] = true;
        if ($e->getStatutEvent())  $statuts[$e->getStatutEvent()] = true;
    }

    ksort($villes); ksort($types); ksort($statuts);

    return $this->render('evenement/calendar.html.twig', [
        'villes' => array_keys($villes),
        'types' => array_keys($types),
        'statuts' => array_keys($statuts),
    ]);
}
#[Route('/evenements/calendar/data', name: 'app_evenements_calendar_data', methods: ['GET'])]
public function calendarFrontData(Request $request, EvenementRepository $repo): JsonResponse
{
    $start = $request->query->get('start');
    $end   = $request->query->get('end');

    $ville  = trim((string) $request->query->get('ville', ''));
    $type   = trim((string) $request->query->get('type', ''));
    $statut = trim((string) $request->query->get('statut', ''));
    $q      = trim((string) $request->query->get('q', ''));

    $events = $repo->findAll();

    $startDt = $start ? new \DateTimeImmutable($start) : null;
    $endDt   = $end ? new \DateTimeImmutable($end) : null;

    $data = [];

    foreach ($events as $ev) {
        $deb = $ev->getDateDebutEvent(); // peut être DateTimeInterface|string|null
        $fin = $ev->getDateFinEvent();

        if (!$deb || !$fin) continue;

        // ✅ Convertir EN SÛR en DateTimeImmutable (sans modify)
        $debDt = $deb instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($deb)
            : new \DateTimeImmutable((string) $deb);

        $finDt = $fin instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($fin)
            : new \DateTimeImmutable((string) $fin);

        // Filtre fenêtre calendrier
        if ($startDt && $endDt) {
            if ($finDt < $startDt || $debDt > $endDt) continue;
        }

        // Filtres
        if ($ville && strcasecmp((string) $ev->getVilleEvent(), $ville) !== 0) continue;
        if ($type && strcasecmp((string) $ev->getTypeEvent(), $type) !== 0) continue;
        if ($statut && strcasecmp((string) $ev->getStatutEvent(), $statut) !== 0) continue;

        // Recherche texte
        if ($q) {
            $hay = strtolower(
                (string) $ev->getTitreEvent().' '.
                (string) $ev->getVilleEvent().' '.
                (string) $ev->getTypeEvent().' '.
                (string) $ev->getNomLieuEvent()
            );
            if (!str_contains($hay, strtolower($q))) continue;
        }

        [$bg, $border, $txt] = $this->mapStatutColors((string) $ev->getStatutEvent());

        // ✅ FullCalendar end exclusive => +1 day avec DateInterval
        $endExclusive = $finDt->add(new \DateInterval('P1D'));

        $data[] = [
            'id' => $ev->getId(),
            'title' => (string) $ev->getTitreEvent(),
            'start' => $debDt->format('Y-m-d'),
            'end'   => $endExclusive->format('Y-m-d'),

            'backgroundColor' => $bg,
            'borderColor' => $border,
            'textColor' => $txt,

            'extendedProps' => [
                'ville' => $ev->getVilleEvent(),
                'type' => $ev->getTypeEvent(),
                'statut' => $ev->getStatutEvent(),
                'lieu' => $ev->getNomLieuEvent(),
                'adresse' => $ev->getAdresseEvent(),
                'debut' => $debDt->format('d/m/Y'),
                'fin' => $finDt->format('d/m/Y'),
                'url' => $this->generateUrl(
                    'app_evenement_show',
                    ['id' => $ev->getId()],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
            ],
        ];
    }

    return $this->json($data);
}

/* =========================================================
 *  CALENDRIER BACK (ADMIN) : page + data JSON + MOVE (drag/drop)
 * ========================================================= */

#[Route('/admin/evenements/calendar', name: 'admin_evenements_calendar', methods: ['GET'])]
public function calendarAdmin(Request $request, EvenementRepository $repo): Response
{
    $this->denyAccessUnlessGranted('ROLE_STAFF');

    $events = $repo->findAll();

    $villes = [];
    $types = [];
    $statuts = [];

    foreach ($events as $e) {
        if ($e->getVilleEvent())   $villes[$e->getVilleEvent()] = true;
        if ($e->getTypeEvent())    $types[$e->getTypeEvent()] = true;
        if ($e->getStatutEvent())  $statuts[$e->getStatutEvent()] = true;
    }

    ksort($villes); ksort($types); ksort($statuts);

    return $this->render('admin/evenements_calendar.html.twig', [
        'villes' => array_keys($villes),
        'types' => array_keys($types),
        'statuts' => array_keys($statuts),
        'csrfMove' => $this->container->get('security.csrf.token_manager')->getToken('move_event')->getValue(),
    ]);
}

#[Route('/admin/evenements/calendar/data', name: 'admin_evenements_calendar_data', methods: ['GET'])]
public function calendarAdminData(Request $request, EvenementRepository $repo): JsonResponse
{
    $this->denyAccessUnlessGranted('ROLE_STAFF');

    // on réutilise la même logique que front (filtres/recherche/couleurs/modal)
    return $this->calendarFrontData($request, $repo);
}

#[Route('/admin/evenements/calendar/move', name: 'admin_evenements_calendar_move', methods: ['POST'])]
public function calendarAdminMove(Request $request, EntityManagerInterface $em, EvenementRepository $repo): JsonResponse
{
    $this->denyAccessUnlessGranted('ROLE_STAFF');

    $payload = json_decode($request->getContent(), true) ?: [];

    $token = (string)($payload['_token'] ?? '');
    if (!$this->isCsrfTokenValid('move_event', $token)) {
        return $this->json(['ok' => false, 'message' => 'CSRF invalide'], 403);
    }

    $id = (int)($payload['id'] ?? 0);
    $startStr = (string)($payload['start'] ?? '');
    $endStr = (string)($payload['end'] ?? '');

    if (!$id || !$startStr) {
        return $this->json(['ok' => false, 'message' => 'Données manquantes'], 400);
    }

    $ev = $repo->find($id);
    if (!$ev) {
        return $this->json(['ok' => false, 'message' => 'Événement introuvable'], 404);
    }

    try {
        $newStart = new \DateTime($startStr);

        // FullCalendar end est exclusif => on retire 1 jour pour stocker en DB
        if ($endStr) {
            $newEndExclusive = new \DateTime($endStr);
            $newEnd = $newEndExclusive->sub(new \DateInterval('P1D'));

        } else {
            // si pas d'end, on garde la même durée
            $duration = $ev->getDateDebutEvent()->diff($ev->getDateFinEvent());
            $newEnd = (clone $newStart)->add($duration);
        }

        if ($newEnd < $newStart) {
            return $this->json(['ok' => false, 'message' => 'Dates invalides'], 400);
        }

        $ev->setDateDebutEvent($newStart);
        $ev->setDateFinEvent($newEnd);
        $ev->setDateMiseAJourEvent(new \DateTime());

        $em->flush();

        return $this->json(['ok' => true, 'message' => 'Dates mises à jour ✅']);
    } catch (\Throwable $e) {
        return $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
    }
}


/* =========================================================
 *  Helper : Couleurs selon statut
 * ========================================================= */
private function mapStatutColors(string $statut): array
{
    $s = strtolower(trim($statut));

    // adapte tes libellés exacts si besoin
    if ($s === 'publié' || $s === 'publie' || $s === 'published') {
        return ['#00BFA6', '#00A993', '#ffffff']; // vert/teal
    }
    if ($s === 'annulé' || $s === 'annule' || $s === 'cancelled') {
        return ['#BC0B09', '#9B0907', '#ffffff']; // rouge
    }
    if ($s === 'en attente' || $s === 'pending') {
        return ['#F59E0B', '#D97706', '#111827']; // orange
    }

    // default
    return ['#0097B2', '#007C91', '#ffffff']; // teal MedFlow
}private function calculateRiskScore(Evenement $evenement, EvenementRepository $repo): array
{
    $risk = 0;
    $reasons = [];
    $now = new \DateTime();

    // 1️⃣ Peu d'inscriptions
    $inscriptions = (int) $evenement->countAcceptedDemandes();
    if ($inscriptions < 5) {
        $risk += 40;
        $reasons[] = "Peu d'inscriptions";
    }

    // 2️⃣ Date proche (< 7 jours)
    $debut = $evenement->getDateDebutEvent();
    if ($debut instanceof \DateTimeInterface) {
        $diff = (int) $now->diff($debut)->days;
        if ($debut > $now && $diff <= 7) {
            $risk += 20;
            $reasons[] = "Date très proche";
        }
    }

    // 3️⃣ Type peu performant ✅ (chez toi c'est type_event)
    $type = $evenement->getTypeEvent();
    if ($type) {
        $allEvents = $repo->findBy(['type_event' => $type]);

        $totalDemandes = 0;
        foreach ($allEvents as $ev) {
            $totalDemandes += (int) $ev->countAcceptedDemandes();
        }

        if ($totalDemandes < 10) {
            $risk += 20;
            $reasons[] = "Type peu populaire";
        }
    }

    // 4️⃣ Ville peu performante ✅ (chez toi c'est ville_event)
    $ville = $evenement->getVilleEvent();
    if ($ville) {
        $eventsVille = $repo->findBy(['ville_event' => $ville]);

        $totalVille = 0;
        foreach ($eventsVille as $ev) {
            $totalVille += (int) $ev->countAcceptedDemandes();
        }

        if ($totalVille < 10) {
            $risk += 20;
            $reasons[] = "Ville peu performante";
        }
    }

    return [
        'riskScore' => min($risk, 100),
        'reasons' => $reasons,
    ];
}

}
