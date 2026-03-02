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
use App\Service\AiRiskService;
use App\Service\AiEventRecommenderService;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use App\Service\VonageSmsService;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Service\WeatherService;   

use Symfony\Component\HttpFoundation\File\UploadedFile;



use App\Entity\Ressource;



use Symfony\Component\HttpFoundation\JsonResponse;


class EvenementController extends AbstractController
{


    
#[Route('/evenements', name: 'app_evenements', methods: ['GET'])]
public function index(Request $request, EvenementRepository $repo): Response
{
    $page = max(1, (int)$request->query->get('page', 1));
    $limit = 12;
    $offset = ($page - 1) * $limit;

    $evenements = $repo->findBy([], ['date_debut_event' => 'DESC'], $limit, $offset);

    $latestCreated = $repo->findOneBy([], ['date_creation_event' => 'DESC']);

    $hasNew = false;
    $latestNewTitle = null;

   if ($latestCreated) {
    $limitDt = new \DateTime('-2 days');
    if ($latestCreated->getDateCreationEvent() > $limitDt) {
        $hasNew = true;
        $latestNewTitle = $latestCreated->getTitreEvent();
    }
}

    return $this->render('evenement/index.html.twig', [
        'evenements' => $evenements,
        'page' => $page,
        'limit' => $limit,
        'hasNew' => $hasNew,
        'latestNewTitle' => $latestNewTitle,
    ]);
}

#[Route('/evenements/{id}', name: 'app_evenement_show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function show(
    int $id,
    WeatherService $weather,
    EvenementRepository $repo,
    AiEventRecommenderService $aiRec,
    SessionInterface $session
): Response {
    $evenement = $repo->findOneWithRessources($id);

    if (!$evenement) {
        throw $this->createNotFoundException('Événement introuvable.');
    }
   // =============================
// 1) METEO
// =============================
$meteo = null;

$city = trim((string) $evenement->getVilleEvent());
if ($city !== '') {
    try {
        $meteo = $weather->getWeather($city);
    } catch (\Throwable $e) {
        $meteo = null;
    }
}
    // =============================
    // 2) RECO IA (scoring + user prefs)
    // =============================
  $user = $this->getUser();
$mfUser = $user instanceof \App\Entity\User ? $user : null;

$recs = $repo->findRecommendedForUser($evenement, $mfUser, 6);

    // =============================
    // 3) Construire "recommended" : score + raisons + popularité
    // =============================
   // =============================
// 2) IA Recommandation (personnalisée user/session)
// =============================
$user = $this->getUser();

// (A) stocker un mini historique session (pour invités aussi)
$hist = $session->get('rec_hist', [
    'types' => [],
    'villes' => [],
]);
if ($evenement->getTypeEvent())  $hist['types'][]  = (string)$evenement->getTypeEvent();
if ($evenement->getVilleEvent()) $hist['villes'][] = (string)$evenement->getVilleEvent();
$hist['types']  = array_values(array_slice(array_unique($hist['types']), -5));
$hist['villes'] = array_values(array_slice(array_unique($hist['villes']), -5));
$session->set('rec_hist', $hist);

// (B) récupérer des candidats
/** @var Evenement[] $candidates */
$candidates = $repo->findCandidatesForAiRecommendation($evenement, 25);

// (C) construire payloads
$currentPayload = [
    'id'        => $evenement->getId(),
    'titre'     => (string) $evenement->getTitreEvent(),
    'ville'     => (string) $evenement->getVilleEvent(),
    'type'      => (string) $evenement->getTypeEvent(),
    'date_debut'=> $evenement->getDateDebutEvent()->format('Y-m-d'),
    'date_fin'  => $evenement->getDateFinEvent()->format('Y-m-d'),
];
$userPayload = [
    'is_logged' => $user instanceof \App\Entity\User,
    'session_preferences' => $hist,
];

if ($user instanceof \App\Entity\User) {
    $uid = $user->getId();
    if ($uid !== null) {
        $userPayload['id'] = (int) $uid;
    }
    $userPayload['nom'] = trim((string)$user->getNom().' '.(string)$user->getPrenom());
}
$candsPayload = [];
foreach ($candidates as $ev) {
    $eid = $ev->getId();
    if ($eid === null) {
        continue; // ✅ PHPStan: id must be int
    }

    $dateDebut = $ev->getDateDebutEvent();

    $candsPayload[] = [
        'id' => (int) $eid,
        'titre' => (string) $ev->getTitreEvent(),
        'ville' => (string) $ev->getVilleEvent(),
        'type'  => (string) $ev->getTypeEvent(),
        'date_debut' => $ev->getDateDebutEvent()->format('Y-m-d'),
        'popularite' => (int) $ev->countAcceptedDemandes(),
    ];
}
// (D) appel IA : renvoie [{id, score, reasons}]
$aiRanks = $aiRec->recommend($currentPayload, $userPayload, $candsPayload, 6);

// (E) reconstruire le tableau "recommended" attendu par Twig

/** @var array<int, Evenement> $byId */
$byId = [];
foreach ($candidates as $ev) {
    $byId[$ev->getId()] = $ev;
}
$recommended = [];
foreach ($aiRanks as $row) {
    $id = (int) $row['id'];
    if (!isset($byId[$id])) {
        continue;
    }

    $evObj = $byId[$id];

    $recommended[] = [
        'event' => $evObj,
        'score' => (float) $row['score'],                 // ✅ plus de ??
        'accepted' => (int) $evObj->countAcceptedDemandes(),
        'reasons' => array_slice($row['reasons'], 0, 3),  // ✅ plus de is_array + ??
    ];
}

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

   $page = max(1, (int)$request->query->get('page', 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$evenements = $repo->findBy([], $orderBy, $limit, $offset);

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
       $token = (string) $request->request->get('_token', '');
       if ($this->isCsrfTokenValid('delete_evenement_'.$evenement->getId(), $token)) {
            $em->remove($evenement);
            $em->flush();
            $this->addFlash('success', 'Événement supprimé 🗑️');
        }

        return $this->redirectToRoute('admin_evenement_index');
    }

  
    #[Route('/admin/evenements/cards', name: 'admin_evenement_cards', methods: ['GET'])]
    public function adminCards(EvenementRepository $repo): Response
    {
        $evenements = $repo->findBy([], ['date_debut_event' => 'DESC'], 50);
             return $this->render('admin/cardsEvents.html.twig', [
                    'evenements' => $evenements,
                    ]);
    }
   #[Route('/admin/evenements/{id}', name: 'admin_evenement_show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function adminShow(Evenement $evenement, EvenementRepository $repo, AiRiskService $ai): Response
{
   $payload = [
    'titre' => (string) $evenement->getTitreEvent(),
    'ville' => (string) $evenement->getVilleEvent(),
    'type'  => (string) $evenement->getTypeEvent(),
    'statut'=> (string) $evenement->getStatutEvent(),
    'date_debut' => $evenement->getDateDebutEvent()->format('Y-m-d'),
    'date_fin'   => $evenement->getDateFinEvent()->format('Y-m-d'),
    'accepted'   => (int) $evenement->countAcceptedDemandes(),
    'pending'    => (int) $evenement->countDemandesByStatus('pending'),
    'refused'    => (int) $evenement->countDemandesByStatus('refused'),
];

$riskData = $ai->analyzeEventRisk($payload);

    return $this->render('admin/showEvents_adm.html.twig', [
        'evenement' => $evenement,
        'riskData' => $riskData, // ✅ maintenant Twig la trouve
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
public function demandesIndex(Request $request, EvenementRepository $repo): Response
{
    $page  = max(1, (int) $request->query->get('page', 1));
    $limit = 12; // ou 9 selon ton design
    $offset = ($page - 1) * $limit;

    $events = $repo->findBy([], ['date_debut_event' => 'DESC'], $limit, $offset);

    $total = $repo->count([]);
    $pages = (int) ceil($total / $limit);

    $totalPending = 0;
    foreach ($events as $ev) {
        $totalPending += $ev->countDemandesByStatus('pending');
    }

    return $this->render('admin/demandesEvents_index.html.twig', [
        'events' => $events,
        'totalPending' => $totalPending,
        'page' => $page,
        'pages' => $pages,
        'limit' => $limit,
    ]);
}
#[Route('/admin/evenements/{id}/demandes', name: 'admin_evenement_demandes_show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function demandesShow(Evenement $evenement, EvenementRepository $repo, AiRiskService $ai): Response
{
    $demandes = $evenement->getDemandesJson();

    usort($demandes, function($a, $b) {
        $sa = $a['status'] ?? 'pending';
        $sb = $b['status'] ?? 'pending';
        if ($sa !== $sb) return $sa === 'pending' ? -1 : 1;
        return strcmp(($b['created_at'] ?? ''), ($a['created_at'] ?? ''));
    });

    // ✅ IA Risk ici
   $payload = [
    'titre' => (string) $evenement->getTitreEvent(),
    'ville' => (string) $evenement->getVilleEvent(),
    'type'  => (string) $evenement->getTypeEvent(),
    'statut'=> (string) $evenement->getStatutEvent(),
    'date_debut' => $evenement->getDateDebutEvent()->format('Y-m-d'),
    'date_fin'   => $evenement->getDateFinEvent()->format('Y-m-d'),
    'accepted'   => (int) $evenement->countAcceptedDemandes(),
    'pending'    => (int) $evenement->countDemandesByStatus('pending'),
    'refused'    => (int) $evenement->countDemandesByStatus('refused'),
];

$riskData = $ai->analyzeEventRisk($payload);

    return $this->render('admin/demandesEvents_show.html.twig', [
        'evenement' => $evenement,
        'demandes' => $demandes,
        'acceptedCount' => $evenement->countAcceptedDemandes(),
        'pendingCount' => $evenement->countDemandesByStatus('pending'),
        'riskData' => $riskData, // ✅ IMPORTANT
    ]);
}

#[Route(
    '/admin/evenements/{id}/demandes/{demandeId}/decide',
    name: 'admin_evenement_demandes_decide',
    requirements: ['id' => '\d+'],
    methods: ['POST']
)]
public function decideDemande(
    Request $request,
    Evenement $evenement,
    string $demandeId,
    EntityManagerInterface $em,
    VonageSmsService $sms
): Response {
    $status = (string) $request->request->get('status', 'pending'); // accepted / refused / pending
    $note   = trim((string) $request->request->get('note', ''));

    // 1) Récupérer les demandes (JSON)
    /** @var array<int, array<string, mixed>> $demandes */
    $demandes = $evenement->getDemandesJson();

    // 2) Trouver l’index de la demande
    $index = null;
    foreach ($demandes as $i => $d) {
        if ((string) ($d['id'] ?? '') === $demandeId) {
            $index = $i;
            break;
        }
    }

    if ($index === null) {
        $this->addFlash('danger', "Demande introuvable.");
        return $this->redirectToRoute('admin_evenement_demandes_show', ['id' => $evenement->getId()]);
    }

    // 3) Charger la demande + sécuriser tel
    $demande = $demandes[$index];

    $nom = (string) ($demande['nom'] ?? 'Participant');
    $tel = trim((string) ($demande['tel'] ?? ''));

    if ($tel === '') {
        $this->addFlash('danger', "Téléphone du participant introuvable.");
        return $this->redirectToRoute('admin_evenement_demandes_show', ['id' => $evenement->getId()]);
    }

    if (!str_starts_with($tel, '+')) {
        $tel = '+' . $tel;
    }

    // ✅ 4) Détecter si ticket déjà existant (AVANT modifications)
    /** @var mixed $rawTicket */
    $rawTicket = $demande['ticket_code'] ?? null;
    $alreadyHadTicket = is_string($rawTicket) && trim($rawTicket) !== '';

    // 5) Appliquer la décision
    $demandes[$index]['status'] = $status;
    $demandes[$index]['admin_note'] = $note;
    $demandes[$index]['decided_at'] = (new \DateTime())->format('Y-m-d H:i:s');

    // 6) Générer ticket si accepted et pas déjà
    if ($status === 'accepted' && !$alreadyHadTicket) {
        $ticketCode = strtoupper('MF-' . $evenement->getId() . '-' . substr(md5(uniqid('', true)), 0, 6));
        $demandes[$index]['ticket_code'] = $ticketCode;
        $demandes[$index]['ticket_used'] = false; // optionnel
    }

    try {
        // ✅ Important: array_values => list<> pour PHPStan
        $evenement->setDemandesJsonArray(array_values($demandes));
        $evenement->setDateMiseAJourEvent(new \DateTime());

        $em->flush();

        // Préparer message SMS
        $eventTitre = (string) $evenement->getTitreEvent();
        $deb = $evenement->getDateDebutEvent();
        $fin = $evenement->getDateFinEvent();
        $dates = $deb->format('d/m/Y') . ' -> ' . $fin->format('d/m/Y');

        if ($status === 'accepted') {
            $ticketCode = (string) ($demandes[$index]['ticket_code'] ?? '');
            $msg = "MedFlow\nParticipation ACCEPTEE\nEvent: $eventTitre\nDates: $dates\nCode: $ticketCode";

            // envoyer SMS seulement si ticket vient d’être généré (pas déjà existant)
            if (!$alreadyHadTicket) {
                $smsResult = $sms->sendSms($tel, $msg);

                $vonageStatus = $smsResult['messages'][0]['status'] ?? null;
                if ((string) $vonageStatus === '0') {
                    $this->addFlash('success', "✅ Acceptée + SMS envoyé.");
                } else {
                    $err = $smsResult['messages'][0]['error-text'] ?? 'SMS non délivré';
                    $this->addFlash('warning', "✅ Acceptée SMS : $err");
                }
            } else {
                $this->addFlash('success', "✅ Acceptée (ticket déjà généré, SMS non renvoyé).");
            }

        } elseif ($status === 'refused') {
            $reason = $note !== '' ? "Raison: $note" : "";
            $msg = "MedFlow\nParticipation REFUSEE\nEvent: $eventTitre\n$reason";

            $smsResult = $sms->sendSms($tel, $msg);
            $vonageStatus = $smsResult['messages'][0]['status'] ?? null;

            if ((string) $vonageStatus === '0') {
                $this->addFlash('success', "❌ Refusée + SMS envoyé.");
            } else {
                $err = $smsResult['messages'][0]['error-text'] ?? 'SMS non délivré';
                $this->addFlash('warning', "❌ Refusée SMS : $err");
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
    /** @var array<int, array<string, mixed>> $demandes */
    $demandes = $evenement->getDemandesJson();

    /** @var array<string, mixed>|null $demandeFound */
    $demandeFound = null;

    foreach ($demandes as $d) {
        $id = (string)($d['id'] ?? '');
        if ($id !== '' && $id === $demandeId) {
            $demandeFound = $d;
            break;
        }
    }

    if ($demandeFound === null) {
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
  $events = $repo->findBy([], ['date_debut_event' => 'DESC'], 99); 
    $byType = [];
    $byVille = [];
    $byStatut = [];
    $demandes = ['total'=>0,'accepted'=>0,'pending'=>0,'refused'=>0];

    foreach ($events as $ev) {
       $type = (string) $ev->getTypeEvent();
$ville = (string) $ev->getVilleEvent();
$statut = (string) $ev->getStatutEvent();

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
 $events = $repo->findBy([], ['date_debut_event' => 'DESC'], 99);

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
    $start = trim((string) $request->query->get('start', ''));
$end   = trim((string) $request->query->get('end', ''));

$startDt = null;
$endDt   = null;

if ($start !== '' && $end !== '') {
    $startDt = new \DateTimeImmutable($start);
    $endDt   = new \DateTimeImmutable($end);
}

    $ville  = trim((string) $request->query->get('ville', ''));
    $type   = trim((string) $request->query->get('type', ''));
    $statut = trim((string) $request->query->get('statut', ''));
    $q      = trim((string) $request->query->get('q', ''));

  $events = $repo->findBy([], ['date_debut_event' => 'DESC'], 99);


    $data = [];

    foreach ($events as $ev) {
      $deb = $ev->getDateDebutEvent();
$fin = $ev->getDateFinEvent();

// ✅ PHPStan: non-nullable => pas de check
$debDt = \DateTimeImmutable::createFromInterface($deb);
$finDt = \DateTimeImmutable::createFromInterface($fin);

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

   $events = $repo->findBy([], ['date_debut_event' => 'DESC'], 99);

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
           $oldStart = $ev->getDateDebutEvent();
$oldEnd   = $ev->getDateFinEvent();

// PHPStan: dates considérées non-nullables => pas besoin de if
$duration = $oldStart->diff($oldEnd);
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
/**
 * @return array{0:string,1:string,2:string}
 */
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
}

#[Route('/evenements/{id}/accessibilite/live', name: 'app_evenement_access_live', requirements: ['id' => '\d+'], methods: ['GET'])]
public function accessLive(Evenement $evenement): Response
{
    return $this->render('evenement/access_live.html.twig', [
        'evenement' => $evenement,
    ]);
}
#[Route('/evenements/{id}/accessibilite/save', name: 'app_evenement_access_save', requirements: ['id' => '\d+'], methods: ['POST'])]
public function saveTranscript(Request $request, int $id, EntityManagerInterface $em): JsonResponse
{
    $text = trim((string)$request->request->get('text', ''));
    if ($text === '' || mb_strlen($text) < 2) {
        return $this->json(['ok' => false, 'message' => 'Transcription vide.'], 400);
    }

    $evenementRef = $em->getReference(\App\Entity\Evenement::class, $id);

    $liveUrl = $this->generateUrl(
        'app_evenement_access_live',
        ['id' => $id],
        UrlGeneratorInterface::ABSOLUTE_URL
    );

    $r = new Ressource();
    $r->setEvenement($evenementRef); // ✅ no SELECT
    $r->setNomRessource('Transcription Accessibilité — ' . (new \DateTime())->format('d/m/Y H:i'));
    $r->setCategorieRessource('Accessibilité');
    $r->setTypeRessource('external_link');
    $r->setUrlExterneRessource($liveUrl);
    $r->setNotesRessource($text);
    $r->setEstPubliqueRessource(true);

    $em->persist($r);
    $em->flush();

    return $this->json(['ok' => true]);
}
#[Route('/admin/evenements/{id}/risk-ai', name: 'admin_evenement_risk_ai', requirements: ['id' => '\d+'], methods: ['GET'])]
public function riskAi(Evenement $evenement, AiRiskService $ai): JsonResponse
{
    $this->denyAccessUnlessGranted('ROLE_STAFF');

    $payload = [
        'titre' => (string) $evenement->getTitreEvent(),
        'ville' => (string) $evenement->getVilleEvent(),
        'type'  => (string) $evenement->getTypeEvent(),
        'statut'=> (string) $evenement->getStatutEvent(),
        'date_debut' => $evenement->getDateDebutEvent()->format('Y-m-d'),
        'date_fin'   => $evenement->getDateFinEvent()->format('Y-m-d'),
        'accepted'   => (int) $evenement->countAcceptedDemandes(),
        'pending'    => (int) $evenement->countDemandesByStatus('pending'),
        'refused'    => (int) $evenement->countDemandesByStatus('refused'),
    ];

    $result = $ai->analyzeEventRisk($payload);

    return $this->json(['ok' => true, 'ai' => $result]);
}

}
