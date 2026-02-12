<?php
namespace App\Controller;

use App\Entity\FicheMedicale;
use App\Entity\Prescription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class ExportPdfController extends AbstractController
{
    #[Route('/fiche-medicale/{id}/export-pdf', name: 'fiche_medicale_export_pdf')]
    public function exportFicheMedicalePdf(FicheMedicale $ficheMedicale): Response
    {
        $html = $this->renderView('pdf/fiche_medicale.html.twig', [
            'fiche' => $ficheMedicale
        ]);
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $filename = 'fiche_medicale_' . $ficheMedicale->getId() . '.pdf';
        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    #[Route('/prescription/{id}/export-pdf', name: 'prescription_export_pdf')]
    public function exportPrescriptionPdf(Prescription $prescription): Response
    {
        $html = $this->renderView('pdf/prescription.html.twig', [
            'prescription' => $prescription
        ]);
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $filename = 'prescription_' . $prescription->getId() . '.pdf';
        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }
}
