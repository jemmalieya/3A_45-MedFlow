<?php

namespace App\Controller;

use App\Entity\Reclamation;
use App\Entity\User;
use App\Form\ReclamationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Psr\Log\LoggerInterface;
use App\Form\ReponseReclamationType;

use App\Repository\ReclamationRepository;
use App\Repository\ReponseReclamationRepository;
use App\Entity\ReponseReclamation;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Service\TextReformulationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\CloudinaryReclamationService;

use Cloudinary\Cloudinary;

use App\Service\MyMemoryTranslateService;
use App\Service\ReclamationMlPriorityService;
class ReclamationController extends AbstractController
{




#[Route('/reclamation/translate-to-fr', name: 'reclamation_translate_to_fr', methods: ['POST'])]
public function translateToFr(Request $request, MyMemoryTranslateService $translator): JsonResponse
{
    $data = json_decode($request->getContent(), true) ?? [];

    $sourceLang  = $data['sourceLang'] ?? 'auto';
    $contenu     = $data['contenu'] ?? '';
    $description = $data['description'] ?? '';

    $contenuFr = $contenu ? $translator->toFrench($contenu, $sourceLang) : null;
    $descFr    = $description ? $translator->toFrench($description, $sourceLang) : null;

    if ($contenu && !$contenuFr) {
        return $this->json([
            'ok' => false,
            'error' => "Traduction indisponible. Veuillez saisir la version française avant de publier.",
            'contenu_fr' => '',
            'description_fr' => $descFr ?? '',
        ]);
    }

    return $this->json([
        'ok' => true,
        'contenu_fr' => $contenuFr ?? '',
        'description_fr' => $descFr ?? '',
    ]);
}

     #[Route('/test-email', name: 'test_email')]
    public function sendTestEmail(MailerInterface $mailer): Response
    {
        // Créez l'e-mail
        $email = (new Email())
            ->from('noreply@votreentreprise.com')
            ->to('jemmalieya9@gmail.com') // Remplacez par votre propre e-mail
            ->subject('Test Mailer Symfony')
            ->text('Ceci est un e-mail de test pour vérifier le fonctionnement du mailer.');

        // Envoyez l'e-mail
        try {
            $mailer->send($email);
            return new Response('E-mail envoyé avec succès!');
        } catch (\Exception $e) {
            return new Response('Erreur lors de l\'envoi de l\'e-mail: ' . $e->getMessage());
        }
    }


   #[Route('/reclamation', name: 'reclamation_index')]
public function index(
    Request $request,
    EntityManagerInterface $em,
    CloudinaryReclamationService $cloud,
    LoggerInterface $logger,
    ReclamationMlPriorityService $ml 
): Response {
    $reclamation = new Reclamation();

    $reclamation->setDateCreationR(new \DateTimeImmutable());
    $reclamation->setStatutReclamation('En attente');

    /** @var User|null $user */
    $user = $this->getUser();
    if (!$user) {
        $this->addFlash('warning', 'Vous devez être connecté pour créer une réclamation.');
        return $this->redirectToRoute('app_login');
    }
    $reclamation->setUser($user);

    if (!$reclamation->getReferenceReclamation()) {
        $reclamation->setReferenceReclamation('REC-' . date('Ymd') . '-' . rand(1000, 9999));
    }

    $form = $this->createForm(ReclamationType::class, $reclamation);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        /**
         * ✅ AJOUT PRO (SANS CHANGER TON CODE) :
         * On récupère ce que le front (JS) a calculé : langue + original + sentiment + score
         * puis on calcule priorite automatiquement via ta méthode existante.
         */
        $langOrig  = $request->request->get('langueOriginale');      // ex: "en" / "ar" / "fr"
        $contOrig  = $request->request->get('contenuOriginal');      // texte original avant traduction
        $descOrig  = $request->request->get('descriptionOriginal');  // texte original avant traduction
        $sentiment = $request->request->get('sentiment');            // NEGATIVE/NEUTRAL/POSITIVE
        $urgence   = $request->request->get('urgenceScore');         // 0..100

        // On applique seulement si les champs existent (sinon ton code continue normal)
        if ($langOrig !== null) {
            $reclamation->setLangueOriginale($langOrig);
        }
        if ($contOrig !== null) {
            $reclamation->setContenuOriginal($contOrig);
        }
        if ($descOrig !== null) {
            $reclamation->setDescriptionOriginal($descOrig);
        }
        if ($sentiment !== null) {
            $reclamation->setSentiment($sentiment);
        }
        if ($urgence !== null && $urgence !== '') {
            $reclamation->setUrgenceScore((int) $urgence);
        }

        // timestamps IA (optionnel mais pro)
        if ($langOrig !== null || $sentiment !== null || $urgence !== null) {
            $reclamation->setTranslatedAt(new \DateTimeImmutable());
            $reclamation->setAnalysisAt(new \DateTimeImmutable());
        }

        // ✅ priorité auto (tu la gardes dans l'entité)
        // (si sentiment/urgence pas fournis => ça garde ta priorite par défaut NORMALE)
        $reclamation->updatePrioriteFromSentiment();

        // =========================
// ✅ FORCER ENREGISTREMENT EN FR (SANS CHANGER DB)
// =========================
$contenuFr = trim((string) $request->request->get('contenu_fr', ''));
$descFr    = trim((string) $request->request->get('description_fr', ''));

// FR obligatoire pour publier
if ($contenuFr === '') {
    $this->addFlash('error', "La version française est obligatoire pour publier la réclamation.");
    return $this->redirectToRoute('reclamation_index');
}

// On stocke en base la version FR (remplace l'original)
$reclamation->setContenu($contenuFr);
$reclamation->setDescription($descFr);

        // =========================
        // ✅ TON CODE (PIECE JOINTE)
        // =========================
        /** @var UploadedFile|null $file */
        $file = $form->get('pieceJointePath')->getData();

        if ($file) {
            $validExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
            $extension = $file->guessExtension();

            if (!in_array($extension, $validExtensions)) {
                $this->addFlash('error', 'Le fichier n\'est pas valide. Formats autorisés: JPG, PNG, PDF.');
                return $this->redirectToRoute('reclamation_index');
            }

            /** @var UploadedFile|null $file */
            $file = $form->get('pieceJointePath')->getData();

            if ($file instanceof UploadedFile) {
                $result = $cloud->uploadProof($file);

                // ✅ PRO : on stocke public_id (pas URL)
                $reclamation->setPieceJointePath($result['public_id']);
                $reclamation->setPieceJointeResourceType($result['resource_type'] ?? null);
                $reclamation->setPieceJointeFormat($result['format'] ?? null);
                $reclamation->setPieceJointeBytes($result['bytes'] ?? null);
                $reclamation->setPieceJointeOriginalName($file->getClientOriginalName());
            }
        }
// ✅ Métier avancé ML : priorité + score + langue
$analysis = $ml->analyze(
    (string) $reclamation->getContenu(),
    (string) $reclamation->getDescription(),
    $reclamation->getType()
);

$reclamation->setLangueOriginale($analysis["lang"]);
$reclamation->setUrgenceScore($analysis["urgenceScore"]);
$reclamation->setPriorite($analysis["priority"]);
$reclamation->setAnalysisAt(new \DateTimeImmutable());
        // =========================
        // ✅ TON CODE (SAVE + EMAIL)
        // =========================
        $em->persist($reclamation);
        $em->flush();

        $this->sendReclamationCreatedEmail($reclamation, $user, $logger);
        $this->addFlash('success', 'Réclamation ajoutée avec succès! Un email de confirmation a été envoyé.');

        return $this->redirectToRoute('reclamation_index');
    }

    return $this->render('reclamation/index.html.twig', [
        'form' => $form->createView(),
    ]);
}

#[Route('/mes-reclamations', name: 'my_reclamations', methods: ['GET'])]
public function myReclamations(ReclamationRepository $repo, EntityManagerInterface $em): Response
{
    /** @var User|null $user */
    $user = $this->getUser();
    if (!$user) {
        $this->addFlash('warning', 'Vous devez être connecté pour voir vos réclamations.');
        return $this->redirectToRoute('app_login');
    }

    // ✅ Récupérer les réclamations du user
    $reclamations = $repo->createQueryBuilder('r')
        ->where('r.user = :userId')
        ->setParameter('userId', $user->getId())
        ->orderBy('r.date_creation_r', 'DESC')
        ->getQuery()
        ->getResult();

    // 🔔 Compter les réponses non lues
    $notificationsCount = $em->getRepository(ReponseReclamation::class)
        ->createQueryBuilder('rep')
        ->join('rep.reclamation', 'r')
        ->select('COUNT(rep)')
        ->where('r.user = :user')
        ->andWhere('rep.isRead = false')
        ->setParameter('user', $user)
        ->getQuery()
        ->getSingleScalarResult();

    $rows = $em->getRepository(ReponseReclamation::class)
    ->createQueryBuilder('rep')
    ->join('rep.reclamation', 'r')
    ->select('IDENTITY(rep.reclamation) AS recId, COUNT(rep) AS cnt')
    ->where('r.user = :user')
    ->andWhere('rep.isRead = false')
    ->setParameter('user', $user)
    ->groupBy('recId')
    ->getQuery()
    ->getArrayResult();

$unreadByRec = [];
foreach ($rows as $row) {
    $unreadByRec[(int)$row['recId']] = (int)$row['cnt'];
}


    return $this->render('reclamation/my_reclamations.html.twig', [
        'reclamations' => $reclamations,
        'user' => $user,
        'notificationsCount' => (int) $notificationsCount,
        'unreadByRec' => $unreadByRec,

    ]);
}


#[Route('/reclamation/mes-reclamations', name: 'reclamation_list', methods: ['GET'])]
public function list(
    ReclamationRepository $reclamationRepository,
    EntityManagerInterface $em
): Response
{
    /** @var User|null $user */
    $user = $this->getUser();
    if (!$user) {
        $this->addFlash('warning', 'Vous devez être connecté pour voir vos réclamations.');
        return $this->redirectToRoute('app_login');
    }

    // ✅ Réclamations de l'utilisateur
    $reclamations = $reclamationRepository->createQueryBuilder('r')
        ->where('r.user = :user')
        ->setParameter('user', $user)
        ->orderBy('r.date_creation_r', 'DESC')
        ->getQuery()
        ->getResult();

    // 🔔 Notifications = nombre de réponses admin non lues
    $notificationsCount = $em->getRepository(ReponseReclamation::class)
        ->createQueryBuilder('rep')
        ->join('rep.reclamation', 'r')
        ->select('COUNT(rep)')
        ->where('r.user = :user')
        ->andWhere('rep.isRead = false')
        ->setParameter('user', $user)
        ->getQuery()
        ->getSingleScalarResult();

    $rows = $em->getRepository(ReponseReclamation::class)
    ->createQueryBuilder('rep')
    ->join('rep.reclamation', 'r')
    ->select('r.id_reclamation AS recId, COUNT(rep) AS cnt')
    ->where('r.user = :user')
    ->andWhere('rep.isRead = false')
    ->setParameter('user', $user)
    ->groupBy('r.id_reclamation')
    ->getQuery()
    ->getArrayResult();

$unreadByRec = [];
foreach ($rows as $row) {
    $unreadByRec[(int)$row['recId']] = (int)$row['cnt'];
}


    // ✅ Si tu veux afficher un message flash dès qu'il y a des réponses non lues
    if ((int)$notificationsCount > 0) {
        $this->addFlash('info', "🔔 Vous avez $notificationsCount nouvelle(s) réponse(s) à vos réclamations.");
    }

    return $this->render('reclamation/my_reclamations.html.twig', [
        'reclamations' => $reclamations,
        'notificationsCount' => (int) $notificationsCount,
        'user' => $user,
        'unreadByRec' => $unreadByRec,

    ]);
}



#[Route('/reclamation/{id}/edit', name: 'reclamation_edit')]
public function edit(
    Request $request,
    Reclamation $reclamation,
    EntityManagerInterface $em,
    CloudinaryReclamationService $cloud
): Response {
    // ✅ Vérifier que l'utilisateur est le propriétaire de la réclamation
    /** @var User|null $user */
    $user = $this->getUser();
    if (!$user || $reclamation->getUser() !== $user) {
        $this->addFlash('error', 'Vous n\'avez pas le droit de modifier cette réclamation.');
        return $this->redirectToRoute('my_reclamations');
    }

    $form = $this->createForm(ReclamationType::class, $reclamation);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        $reclamation->setDateModificationR(new \DateTimeImmutable());
        /** @var UploadedFile $file */
$file = $form->get('pieceJointePath')->getData();

if ($file) {
   /** @var UploadedFile|null $file */
$file = $form->get('pieceJointePath')->getData();

if ($file instanceof UploadedFile) {

    // ✅ Supprimer ancien fichier cloud si on remplace
    if ($reclamation->getPieceJointePath()) {
        $cloud->delete(
            $reclamation->getPieceJointePath(),
            $reclamation->getPieceJointeResourceType() ?? 'image'
        );
    }

    $result = $cloud->uploadProof($file);

    $reclamation->setPieceJointePath($result['public_id']);
    $reclamation->setPieceJointeResourceType($result['resource_type'] ?? null);
    $reclamation->setPieceJointeFormat($result['format'] ?? null);
    $reclamation->setPieceJointeBytes($result['bytes'] ?? null);
    $reclamation->setPieceJointeOriginalName($result['original_filename'] ?? $file->getClientOriginalName());
}

}


        $em->flush();

        $this->addFlash('success', 'Réclamation modifiée avec succès');

        return $this->redirectToRoute('my_reclamations');
    }

    return $this->render('reclamation/edit.html.twig', [
        'form' => $form,
        'reclamation' => $reclamation,
    ]);

    $reponsesNonLues = $em->getRepository(ReponseReclamation::class)
    ->createQueryBuilder('rep')
    ->join('rep.reclamation', 'r')
    ->where('r.user = :user')
    ->andWhere('rep.isRead = false')
    ->setParameter('user', $this->getUser())
    ->getQuery()
    ->getResult();

foreach ($reponsesNonLues as $rep) {
    $rep->setIsRead(true);
}

$em->flush();

}

#[Route('/reclamation/{id}', name: 'reclamation_delete', methods: ['POST'])]
public function delete(
    Request $request,
    Reclamation $reclamation,
    EntityManagerInterface $em,
    CloudinaryReclamationService $cloud
): Response {
    /** @var User|null $user */
    $user = $this->getUser();
    if (!$user || $reclamation->getUser() !== $user) {
        $this->addFlash('error', "Vous n'avez pas le droit de supprimer cette réclamation.");
        return $this->redirectToRoute('my_reclamations');
    }

    if ($this->isCsrfTokenValid('delete' . $reclamation->getIdReclamation(), $request->request->get('_token'))) {

        // 1) Supprimer la pièce jointe Cloudinary si existe
        if ($reclamation->getPieceJointePath()) {
            $cloud->delete(
                $reclamation->getPieceJointePath(),
                $reclamation->getPieceJointeResourceType() ?? 'image'
            );
        }

        // 2) ✅ Supprimer la réclamation de la BD
        $em->remove($reclamation);
        $em->flush();

        $this->addFlash('success', 'Réclamation supprimée avec succès');
    } else {
        $this->addFlash('danger', 'Token CSRF invalide.');
    }

    return $this->redirectToRoute('my_reclamations');
}

#[Route('/reclamation/{id}/reponses', name: 'reclamation_reponses', methods: ['GET'])]
public function reponsesFront(
    Reclamation $reclamation,
    ReponseReclamationRepository $repo
): Response {
    // ✅ Vérifier que l'utilisateur est le propriétaire de la réclamation
    /** @var User|null $user */
    $user = $this->getUser();
    if (!$user || $reclamation->getUser() !== $user) {
        $this->addFlash('error', 'Vous n\'avez pas le droit d\'accéder à cette réclamation.');
        return $this->redirectToRoute('my_reclamations');
    }

    $reponses = $repo->findBy(
        ['reclamation' => $reclamation],
        ['date_creation_rep' => 'DESC']
    );

    return $this->render('reclamation/reponses.html.twig', [
        'reclamation' => $reclamation,
        'reponses' => $reponses,
    ]);
}
#[Route('/admin/reclamations/stats', name: 'admin_reclamations_stats', methods: ['GET'])]
public function stats(ReclamationRepository $repo, Request $request): Response
{
    $days = max(1, (int) $request->query->get('days', 7));

    $to = new \DateTimeImmutable('today 23:59:59');
    $from = $to->modify('-'.($days - 1).' days')->setTime(0, 0, 0);

    $kpis = $repo->getReclamKpis($from, $to);
    $byDay = $repo->countReclamByDay($from, $to);
    $byType = $repo->countReclamByType($from, $to);
    $byStatut = $repo->countReclamByStatut($from, $to);
    $byPriorite = $repo->countReclamByPriorite($from, $to);

    return $this->render('admin/stat_reclamation.html.twig', [
        'days' => $days,
        'from' => $from,
        'to' => $to,
        'kpis' => $kpis,
        'byDay' => $byDay,
        'byType' => $byType,
        'byStatut' => $byStatut,
        'byPriorite' => $byPriorite,
    ]);
}


#[Route('/reclamations/export/pdf', name: 'reclamation_export_pdf', methods: ['GET'])]
public function exportPdf(Request $request, ReclamationRepository $repo): Response
{
    $q = trim((string) $request->query->get('q', ''));
    $sort = (string) $request->query->get('sort', 'date_creation_r');
    $dir  = (string) $request->query->get('dir', 'DESC');

    // ✅ même logique que la liste (sans userId)
    $reclamations = $repo->findFiltered([
        'q' => $q,
        'sort' => $sort,
        'dir' => $dir,
    ]);

    $html = $this->renderView('reclamation/pdf_list.html.twig', [
        'reclamations' => $reclamations,
        'generatedAt' => new \DateTimeImmutable(),
        'q' => $q,
        'sort' => $sort,
        'dir' => $dir,
    ]);

    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return new Response(
        $dompdf->output(),
        200,
        [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="reclamations.pdf"',
        ]
    );
}

public function repondreAReclamation(Request $request, Reclamation $reclamation, EntityManagerInterface $em,ReponseReclamationRepository $repo ): Response
{

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

        // ✅ NOTIFICATION USER : réponse non lue
        $reponse->setIsRead(false);

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



#[Route('/reformuler', name: 'reformuler', methods: ['POST'])]
public function reformuler(Request $request, TextReformulationService $textReformulationService): JsonResponse
{
    try {
        $data = json_decode($request->getContent(), true);

        $content = $data['content'] ?? '';
        $description = $data['description'] ?? '';

        $reformulatedContent = $textReformulationService->reformuler($content);
        $reformulatedDescription = $textReformulationService->reformuler($description);

        return new JsonResponse([
            'reformulated_content' => $reformulatedContent,
            'reformulated_description' => $reformulatedDescription,
        ]);
    } catch (\Exception $e) {
        return new JsonResponse(['error' => $e->getMessage()], 500);
    }
}
#[Route('/reclamation/{id}/mark-reponses-read', name: 'reclamation_mark_read', methods: ['POST'])]
public function markReponsesRead(Reclamation $reclamation, EntityManagerInterface $em): JsonResponse
{
    /** @var User|null $user */
    $user = $this->getUser();
    if (!$user || $reclamation->getUser() !== $user) {
        return new JsonResponse(['ok' => false, 'message' => 'Accès refusé'], 403);
    }

    $marked = 0;

    foreach ($reclamation->getReponses() as $rep) {
        if ($rep->isRead() === false) {
            $rep->setIsRead(true);
            $marked++;
        }
    }

    $em->flush();

    return new JsonResponse(['ok' => true, 'marked' => $marked]);
}

private function sendReclamationCreatedEmail(Reclamation $reclamation, User $user, LoggerInterface $logger): void
{
    $apiKey = $_ENV['BREVO_API_KEY2'] ?? null;
    $sender = $_ENV['BREVO_SENDER_EMAIL2'] ?? null;
    $appUrl  = $_ENV['APP_URL'] ?? '';

    if (!$apiKey || !$sender) {
        $logger->error('Brevo env missing', [
            'BREVO_API_KEY2' => (bool) $apiKey,
            'BREVO_SENDER_EMAIL2' => (bool) $sender,
        ]);
        return;
    }

    // ✅ ID correct (selon ton code: getIdReclamation())
    $recId = $reclamation->getIdReclamation();

    // ✅ AppUrl propre (pas de double //)
    $base = rtrim($appUrl, '/');

    // ✅ Lien (optionnel) : si tu n’as pas de page show, laisse vers la liste "mes réclamations"
    // Tu peux changer /mes-reclamations si ton route est différente
    $link = $base . '/mes-reclamations';

    // Si tu as une page détail, remplace par ton vrai path (ex: /reclamation/{id}/show)
    // $link = $base . '/reclamation/' . $recId;

    $prenom = $user->getPrenom() ?? 'Utilisateur';
    $nom    = $user->getNom() ?? '';
    $fullName = trim($prenom . ' ' . $nom);

    $payload = [
        'sender' => [
            'email' => $sender,
            'name'  => 'MedFlow',
        ],
        'to' => [[
            'email' => $user->getEmailUser(), // ✅ bonne méthode
            'name'  => $fullName ?: $user->getEmailUser(),
        ]],
        'subject' => '✅ Réclamation reçue - MedFlow',
        'htmlContent' => "
            <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto'>
              <h2 style='margin:0 0 10px'>Bonjour " . htmlspecialchars($prenom, ENT_QUOTES) . " 👋</h2>

              <p style='margin:0 0 12px'>
                Nous avons bien reçu votre réclamation <b>#{$recId}</b>.
              </p>

              <p style='margin:0 0 16px;color:#444'>
                Notre équipe va la traiter dans les plus brefs délais.
              </p>

              <p style='margin:0 0 18px'>
                <a href='{$link}' style='display:inline-block;padding:10px 14px;border-radius:10px;background:#0d6efd;color:#fff;text-decoration:none'>
                  Voir mes réclamations
                </a>
              </p>

              <hr style='border:none;border-top:1px solid #eee;margin:18px 0'>

              <p style='color:#6c757d;font-size:12px;margin:0'>
                Merci, <b>MedFlow</b>.
              </p>
            </div>
        ",
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        $logger->error('Brevo send reclamation email failed', [
            'to' => $user->getEmailUser(),
            'reclamationId' => $recId,
            'httpCode' => $httpCode,
            'curlError' => $curlErr,
            'response' => $response,
        ]);
        return;
    }

    $logger->info('Brevo reclamation email sent', [
        'to' => $user->getEmailUser(),
        'reclamationId' => $recId,
        'httpCode' => $httpCode,
        'response' => $response,
    ]);
}


private function canSeePiece(?User $user, Reclamation $reclamation): bool
{
    if (!$user) return false;

    // Owner par ID (évite doctrine proxy)
    $ownerId = $reclamation->getUser()?->getId();
    $userId  = $user->getId();
    $isOwner = ($ownerId !== null && (int)$ownerId === (int)$userId);

    // ✅ Tes colonnes BD
    $roleSysteme = method_exists($user, 'getRoleSysteme') ? $user->getRoleSysteme() : null;
    $typeStaff   = method_exists($user, 'getTypeStaff') ? $user->getTypeStaff() : null;

    $isRespBlog = ($roleSysteme === 'STAFF' && $typeStaff === 'RESP_BLOG');
    $isAdmin    = ($roleSysteme === 'ADMIN');

    return $isOwner || $isRespBlog || $isAdmin;
}


#[Route('/reclamation/{id}/piece', name: 'reclamation_piece_download', methods: ['GET'])]
public function downloadPiece(Reclamation $reclamation, Cloudinary $cloudinary): Response
{
    /** @var User|null $user */
    $user = $this->getUser();

    if (!$user) {
        throw new AccessDeniedHttpException("Accès refusé (non connecté).");
    }

    // ✅ Compare par ID (évite proxy Doctrine)
    $ownerId = $reclamation->getUser()?->getId();
    $userId  = method_exists($user, 'getId') ? $user->getId() : null;

    $isOwner    = ($ownerId !== null && $userId !== null && (int)$ownerId === (int)$userId);
    $isRespBlog = $this->isGranted('STAFF');
    

    if (!$isOwner && !$isRespBlog ) {
        throw new AccessDeniedHttpException("Accès refusé.");
    }

    if (!$reclamation->getPieceJointePath()) {
        throw $this->createNotFoundException("Aucune pièce jointe.");
    }

    $publicId = $reclamation->getPieceJointePath();
    $type = $reclamation->getPieceJointeResourceType() ?? 'image';

    $url = ($type === 'image')
        ? $cloudinary->image($publicId)->toUrl()
        : $cloudinary->raw($publicId)->toUrl();

    return $this->redirect($url);
}

#[Route('/reclamation/{id}/piece/preview', name: 'reclamation_piece_preview', methods: ['GET'])]
public function previewPiece(Reclamation $reclamation, Cloudinary $cloudinary): JsonResponse
{
    /** @var User|null $user */
    $user = $this->getUser();

    if (!$this->canSeePiece($user, $reclamation)) {
        return new JsonResponse(['ok' => false, 'message' => 'Accès refusé'], 403);
    }

    if (!$reclamation->getPieceJointePath()) {
        return new JsonResponse(['ok' => false, 'message' => 'Aucune pièce jointe'], 404);
    }

    $publicId = $reclamation->getPieceJointePath();
    $type     = $reclamation->getPieceJointeResourceType() ?? 'image';

    $url = ($type === 'image')
        ? $cloudinary->image($publicId)->toUrl()
        : $cloudinary->raw($publicId)->toUrl();

    return new JsonResponse(['ok' => true, 'url' => $url, 'type' => $type]);
}





}