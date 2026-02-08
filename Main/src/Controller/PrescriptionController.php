<?php

namespace App\Controller;

use App\Entity\Prescription;
use App\Repository\PrescriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PrescriptionController extends AbstractController
{
    #[Route('/prescription', name: 'app_prescription')]
    public function index(): Response
    {
        return $this->render('prescription/index.html.twig', [
            'controller_name' => 'PrescriptionController',
        ]);
    }

    #[Route('/prescription/{id}/edit', name: 'app_prescription_edit', methods: ['POST'])]
    public function edit(int $id, Request $request, PrescriptionRepository $prescRepo, EntityManagerInterface $em): Response
    {
        $presc = $prescRepo->find($id);
        if (!$presc) {
            $this->addFlash('error', 'Prescription not found.');
            return $this->redirectToRoute('app_fiche_medicale');
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('edit_prescription' . $presc->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $presc->getFicheMedicale()?->getRendezVous()?->getIdStaff()]);
        }

        $nom = trim((string) $request->request->get('nomMedicament', ''));
        $dose = trim((string) $request->request->get('dose', ''));
        $freq = trim((string) $request->request->get('frequence', ''));
        $duree = $request->request->get('duree');
        $instr = trim((string) $request->request->get('instructions', ''));

        // Basic validation
        $errors = [];
        if ($nom === '') $errors[] = 'Medication name is required.';
        if ($dose === '') $errors[] = 'Dose is required.';
        if ($freq === '') $errors[] = 'Frequency is required.';
        $dureeVal = filter_var($duree, FILTER_VALIDATE_INT);
        if ($dureeVal === false || $dureeVal < 1) $errors[] = 'Duration must be a positive integer.';

        if (!empty($errors)) {
            foreach ($errors as $e) $this->addFlash('error', $e);
            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $presc->getFicheMedicale()?->getRendezVous()?->getIdStaff()]);
        }

        $presc->setNomMedicament($nom);
        $presc->setDose($dose);
        $presc->setFrequence($freq);
        $presc->setDuree((int) $dureeVal);
        $presc->setInstructions($instr === '' ? null : $instr);

        $em->persist($presc);
        $em->flush();

        $this->addFlash('success', 'Prescription updated.');
        return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $presc->getFicheMedicale()?->getRendezVous()?->getIdStaff()]);
    }

    #[Route('/prescription/{id}/delete', name: 'app_prescription_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, PrescriptionRepository $prescRepo, EntityManagerInterface $em): Response
    {
        $presc = $prescRepo->find($id);
        if (!$presc) {
            $this->addFlash('error', 'Prescription not found.');
            return $this->redirectToRoute('app_fiche_medicale');
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_prescription' . $presc->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $presc->getFicheMedicale()?->getRendezVous()?->getIdStaff()]);
        }

        $staffId = $presc->getFicheMedicale()?->getRendezVous()?->getIdStaff();
        $em->remove($presc);
        $em->flush();
        $this->addFlash('success', 'Prescription deleted.');
        return $this->redirectToRoute('app_fiche_by_staff', ['idStaff' => $staffId]);
    }
}
