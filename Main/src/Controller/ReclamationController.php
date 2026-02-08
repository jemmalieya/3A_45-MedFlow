<?php

namespace App\Controller;

use App\Entity\Reclamation;
use App\Form\ReclamationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Repository\ReclamationRepository;
use App\Repository\ReponseReclamationRepository;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ReclamationController extends AbstractController
{
   #[Route('/reclamation', name: 'reclamation_index', methods: ['GET', 'POST'])]
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
        $qb->andWhere('r.statut = :statut')
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


}
