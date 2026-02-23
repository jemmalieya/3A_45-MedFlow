<?php
namespace App\Service;

use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PrescriptionDocumentIntelligenceService
{
    public function extractText(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'pdf') {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();
        } elseif (in_array($ext, ['png', 'jpg', 'jpeg'])) {
            $text = shell_exec('tesseract "'.$file->getPathname().'" stdout');
        } else {
            throw new \Exception('Unsupported file type.');
        }
        return [
            'raw_text' => $text,
            'parsed' => $this->parsePrescriptionData($text)
        ];
    }

    private function parsePrescriptionData(string $text): array
    {
        $parsed = [
            'fiche_id' => '',
            'nomMedicament' => '',
            'dose' => '',
            'frequence' => '',
            'duree' => '',
            'instructions' => '',
            'createdAt' => '',
        ];
        // Fiche ID (Fiche Médicale)
        if (preg_match('/Fiche Médicale\s*:?\s*(\d+)/iu', $text, $m)) {
            $parsed['fiche_id'] = trim($m[1]);
        } elseif (preg_match('/Fiche\s*ID\s*:?\s*(\d+)/iu', $text, $m)) {
            $parsed['fiche_id'] = trim($m[1]);
        }
        // Médicament
        if (preg_match('/Médicament\s*:?\s*(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['nomMedicament'] = trim($m[1]);
        }
        // Dose (Dosage)
        if (preg_match('/Dosage\s*:?\s*(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['dose'] = trim($m[1]);
        } elseif (preg_match('/Dose\s*:?\s*(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['dose'] = trim($m[1]);
        }
        // Fréquence
        if (preg_match('/Fréquence\s*:?\s*(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['frequence'] = trim($m[1]);
        }
        // Durée
        if (preg_match('/Durée\s*:?\s*(\d+)/iu', $text, $m)) {
            $parsed['duree'] = trim($m[1]);
        }
        // Instructions
        if (preg_match('/Instructions\s*:?\s*(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['instructions'] = trim($m[1]);
        }
        // Created At (Document généré le ...)
        if (preg_match('/Document généré le\s*:?\s*(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['createdAt'] = trim($m[1]);
        } elseif (preg_match('/Date de création\s*:?\s*(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['createdAt'] = trim($m[1]);
        }
        return $parsed;
    }
}
