<?php

namespace App\Controller;

use App\Entity\Reclamation;
use App\Form\ReclamationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use App\Repository\ReclamationRepository;
use App\Repository\ReponseReclamationRepository;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Dompdf\Dompdf;
use Dompdf\Options;

class ReclamationController extends AbstractController
{
   #[Route('/reclamation', name: 'reclamation_index')]
public function index(Request $request, EntityManagerInterface $em): Response
{
    $reclamation = new Reclamation();

    // valeurs automatiques
    $reclamation->setDateCreationR(new \DateTimeImmutable());
    $reclamation->setStatutReclamation('En attente');

    if (!$reclamation->getReferenceReclamation()) {
        $reclamation->setReferenceReclamation(
            'REC-' . date('Ymd') . '-' . rand(1000, 9999)
        );
    }

    $form = $this->createForm(ReclamationType::class, $reclamation);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        /** @var UploadedFile|null $file */
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
                $this->addFlash('admin_danger', 'Erreur lors de l’upload du fichier.');
            }
        }

        $em->persist($reclamation);
        $em->flush();

        $this->addFlash('success', 'Réclamation ajoutée avec succès');

        return $this->redirectToRoute('reclamation_index');
    }
     
    // ✅ IMPORTANT : toujours retourner une Response si pas soumis/valide
    return $this->render('reclamation/index.html.twig', [
        'form' => $form->createView(),
    ]);
}


 #[Route('/reclamation/list', name: 'reclamation_list', methods: ['GET'])]
public function list(Request $request, ReclamationRepository $reclamationRepository): Response
{
    // 🔎 Récupération des paramètres GET
    $q      = $request->query->get('q');
    $type   = $request->query->get('type');
    $statut = $request->query->get('statut');
    $sort   = $request->query->get('sort');
    $dir    = $request->query->get('dir');

    // Sécurité tri
    $allowedSort = ['date_creation_r', 'type', 'statut_reclamation', 'contenu'];
    $sort = in_array($sort, $allowedSort, true) ? $sort : 'date_creation_r';

    $dir = strtoupper($dir ?? 'DESC');
    $dir = in_array($dir, ['ASC', 'DESC'], true) ? $dir : 'DESC';

    // QueryBuilder
    $qb = $reclamationRepository->createQueryBuilder('r');

    // 🔎 Recherche texte
    if ($q && trim($q) !== '') {
        $qb->andWhere('LOWER(r.contenu) LIKE :q')
           ->setParameter('q', '%' . mb_strtolower(trim($q)) . '%');
    }

    // 🎯 Filtre type
    if ($type && trim($type) !== '') {
        $qb->andWhere('r.type = :type')
           ->setParameter('type', $type);
    }

    // 🎯 Filtre statut_reclamation
    if ($statut && trim($statut) !== '') {
        $qb->andWhere('r.statutReclamation = :statut')
           ->setParameter('statut', $statut);
    }

    // ✅ Résultat final
    $reclamations = $qb
        ->orderBy('r.' . $sort, $dir)
        ->getQuery()
        ->getResult();

    return $this->render('reclamation/list.html.twig', [
        'reclamations' => $reclamations,
        'filters' => compact('q', 'type', 'statut', 'sort', 'dir'),
    ]);
}

#[Route('/reclamation/{id}/edit', name: 'reclamation_edit')]
public function edit(
    Request $request,
    Reclamation $reclamation,
    EntityManagerInterface $em
): Response {

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

        return $this->redirectToRoute('reclamation_list');
    }

    return $this->render('reclamation/edit.html.twig', [
        'form' => $form,
        'reclamation' => $reclamation,
    ]);
}

#[Route('/reclamation/{id}', name: 'reclamation_delete', methods: ['POST'])]
public function delete(
    Request $request,
    Reclamation $reclamation,
    EntityManagerInterface $em
): Response {
    if ($this->isCsrfTokenValid('delete'.$reclamation->getIdReclamation(), $request->request->get('_token'))) {
        $em->remove($reclamation);
        $em->flush();

        $this->addFlash('success', 'Réclamation supprimée avec succès');
    }

    return $this->redirectToRoute('reclamation_list');
}



#[Route('/reclamation/{id}/reponses', name: 'reclamation_reponses', methods: ['GET'])]
public function reponsesFront(
    Reclamation $reclamation,
    ReponseReclamationRepository $repo
): Response {

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


}
