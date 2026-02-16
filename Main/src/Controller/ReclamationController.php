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
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Service\TextReformulationService;
use Symfony\Component\HttpFoundation\JsonResponse;

class ReclamationController extends AbstractController
{
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
    public function index(Request $request, EntityManagerInterface $em, MailerInterface $mailer, LoggerInterface $logger): Response
    {
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

            /** @var UploadedFile|null $file */
            $file = $form->get('pieceJointePath')->getData();

            if ($file) {
                $validExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
                $extension = $file->guessExtension();

                if (!in_array($extension, $validExtensions)) {
                    $this->addFlash('error', 'Le fichier n\'est pas valide. Formats autorisés: JPG, PNG, PDF.');
                    return $this->redirectToRoute('reclamation_index');
                }

                $newFilename = uniqid() . '.' . $file->guessExtension();
                try {
                    $file->move(
                        $this->getParameter('pieces_jointes_directory'),
                        $newFilename
                    );
                    $reclamation->setPieceJointePath('uploads/pieces_jointes/' . $newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Erreur lors du téléchargement du fichier.');
                    return $this->redirectToRoute('reclamation_index');
                }
            }

            // Sauvegarder la réclamation
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
    EntityManagerInterface $em
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
    $newFilename = uniqid() . '.' . $file->guessExtension();

    try {
        $file->move(
            $this->getParameter('pieces_jointes_directory'),
            $newFilename
        );
        $reclamation->setPieceJointePath('uploads/pieces_jointes/' . $newFilename);
    } catch (FileException $e) {
        $this->addFlash('danger', 'Erreur lors de l’upload du fichier.');
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
    EntityManagerInterface $em
): Response {
    // ✅ Vérifier que l'utilisateur est le propriétaire de la réclamation
    /** @var User|null $user */
    $user = $this->getUser();
    if (!$user || $reclamation->getUser() !== $user) {
        $this->addFlash('error', 'Vous n\'avez pas le droit de supprimer cette réclamation.');
        return $this->redirectToRoute('my_reclamations');
    }

    if ($this->isCsrfTokenValid('delete'.$reclamation->getIdReclamation(), $request->request->get('_token'))) {
        $em->remove($reclamation);
        $em->flush();

        $this->addFlash('success', 'Réclamation supprimée avec succès');
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







}