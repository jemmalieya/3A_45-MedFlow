<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Ressource;
use App\Form\RessourceType;
use App\Repository\RessourceRepository;
use Symfony\Component\HttpFoundation\Request;

use App\Form\RessourceBatchType;
use App\Form\Model\RessourceBatch;

use App\Service\CalusinaryEventservice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\JsonResponse;
#[Route('/admin/ressource')]
final class RessourceController extends AbstractController

{
   
    #[Route('/', name: 'admin_ressource_index', methods: ['GET'])]
public function index(Request $request, RessourceRepository $repo): Response
{
    $search = trim((string) $request->query->get('search', ''));
    $sort = (string) $request->query->get('sort', 'date_desc');

    // ordre
    $orderBy = ['date_creation_ressource' => 'DESC'];

    switch ($sort) {
        case 'date_asc':
            $orderBy = ['date_creation_ressource' => 'ASC'];
            break;
        case 'nom_asc':
            $orderBy = ['nom_ressource' => 'ASC'];
            break;
        case 'nom_desc':
            $orderBy = ['nom_ressource' => 'DESC'];
            break;
        case 'cat_asc':
            $orderBy = ['categorie_ressource' => 'ASC'];
            break;
        case 'type_asc':
            $orderBy = ['type_ressource' => 'ASC'];
            break;
    }

    // recherche
    if ($search !== '') {
        $ressources = $repo->searchAdmin($search, $orderBy);
    } else {
        $ressources = $repo->findBy([], $orderBy);
    }

    return $this->render('ressource/index.html.twig', [
        'ressources' => $ressources,
        'search' => $search,
        'sort' => $sort,
    ]);
}


#[Route('/new', name: 'admin_ressource_new', methods: ['GET', 'POST'])]
public function new(
    Request $request,
    EntityManagerInterface $em,
    CalusinaryEventservice $cloud
): Response {
    $batch = new RessourceBatch();
    $batch->ressources[] = new Ressource();

    $form = $this->createForm(RessourceBatchType::class, $batch);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        foreach ($form->get('ressources') as $i => $ressourceForm) {
            /** @var Ressource $ressource */
            $ressource = $batch->ressources[$i];

            $uploadedFile = $ressourceForm->has('uploadFile')
                ? $ressourceForm->get('uploadFile')->getData()
                : null;

            if ($ressource->getTypeRessource() === 'file') {
                if ($uploadedFile) {
                    $result = $cloud->upload($uploadedFile->getPathname(), 'medflow/ressources');

                    // URL Cloudinary dans ton champ existant
                    $ressource->setCheminFichierRessource($result['secure_url']);
                    $ressource->setCloudinaryPublicId($result['public_id']);

                    $ressource->setMimeTypeRessource($uploadedFile->getMimeType());
                    $ressource->setTailleKbRessource((int) ceil($uploadedFile->getSize() / 1024));
                }
            }

            $em->persist($ressource);
        }

        $em->flush();
        $this->addFlash('success', 'Ressources ajoutées avec succès');
        return $this->redirectToRoute('admin_ressource_index');
    }

    return $this->render('admin/new_Ressource.html.twig', [
        'form' => $form->createView(),
    ]);
}


   #[Route('/{id}', name: 'admin_ressource_show', requirements: ['id' => '\d+'], methods: ['GET'])]
public function show(Ressource $ressource): Response
{
    return $this->render('admin/show_Ressource.html.twig', [
        'ressource' => $ressource,
    ]);
}


   
    #[Route('/{id}/edit', name: 'admin_ressource_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
 public function edit(
    Request $request,
    Ressource $ressource,
    EntityManagerInterface $em,
    \App\Service\CalusinaryEventservice $cloud
): Response
    {
        $form = $this->createForm(RessourceType::class, $ressource);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $uploadedFile = $form->has('uploadFile') ? $form->get('uploadFile')->getData() : null;

if ($ressource->getTypeRessource() === 'file' && $uploadedFile) {

    // supprimer ancien si existe
    if ($ressource->getCloudinaryPublicId()) {
        $cloud->destroy($ressource->getCloudinaryPublicId());
    }

    $result = $cloud->upload($uploadedFile->getPathname(), 'medflow/ressources');
    $ressource->setCheminFichierRessource($result['secure_url']);
    $ressource->setCloudinaryPublicId($result['public_id']);

    $ressource->setMimeTypeRessource($uploadedFile->getMimeType());
    $ressource->setTailleKbRessource((int) ceil($uploadedFile->getSize() / 1024));
}
            $em->flush();

            $this->addFlash('success', 'Ressource modifiée');

            return $this->redirectToRoute('admin_ressource_index');
        }

        return $this->render('admin/edit_Ressource.html.twig', [
            'form' => $form->createView(),
            'ressource' => $ressource,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_ressource_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
   public function delete(
    Request $request,
    Ressource $ressource,
    EntityManagerInterface $em,
    \App\Service\CalusinaryEventservice $cloud
): Response
    {
        if ($this->isCsrfTokenValid('delete'.$ressource->getId(), $request->request->get('_token'))) {
            if ($ressource->getCloudinaryPublicId()) {
    $cloud->destroy($ressource->getCloudinaryPublicId());
}
            $em->remove($ressource);
            
            $em->flush();

            $this->addFlash('success', 'Ressource supprimée');
        }

        return $this->redirectToRoute('admin_ressource_index');
    }
    




#[Route('/stats', name: 'admin_ressource_stats', methods: ['GET'])]
public function statsPage(): Response
{
    return $this->render('admin/ressources_stats.html.twig');
}

#[Route('/stats/data', name: 'admin_ressource_stats_data', methods: ['GET'])]
public function statsData(RessourceRepository $repo): JsonResponse
{
    return $this->json([
        'kpi' => $repo->getKpiStats(),
        'byType' => $repo->countByType(),
        'byCategorie' => $repo->countByCategorie(),
        'topEvenements' => $repo->topEvenementsByRessources(5),
    ]);
}



    #[Route('/stats/pdf', name: 'admin_ressource_stats_pdf', methods: ['GET'])]
public function exportStatsPdf(RessourceRepository $repo): Response
{
    $kpi = $repo->getKpiStats();
    $byType = $repo->countByType();
    $byCategorie = $repo->countByCategorie();
    $topEvents = $repo->topEvenementsByRessources(10);

    // HTML via Twig
    $html = $this->renderView('admin/ressources_stats_pdf.html.twig', [
        'kpi' => $kpi,
        'byType' => $byType,
        'byCategorie' => $byCategorie,
        'topEvents' => $topEvents,
        'generatedAt' => new \DateTime(),
    ]);

    // Dompdf config
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->setIsRemoteEnabled(true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'stats_ressources.pdf';

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