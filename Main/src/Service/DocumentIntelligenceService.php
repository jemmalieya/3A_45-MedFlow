<?php
namespace App\Service;

use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class DocumentIntelligenceService
{
    /**
     * @return array{raw_text: string, parsed: array<string, string>}
     */
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

        $normalizedText = is_string($text) ? $text : '';
        return [
            'raw_text' => $normalizedText,
            'parsed' => $this->parseMedicalData($normalizedText)
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseMedicalData(string $text): array
    {
        // Improved regex parsing for diagnostic, observations, exam results, prescriptions
        $parsed = [
            'rendez_vous_id' => '',
            'diagnostic' => '',
            'observations' => '',
            'resultatsExamens' => '',
            'startTime' => '',
            'endTime' => '',
            'dureeMinutes' => '',
            'createdAt' => '',
        ];
        // Rendez-vous ID (handle spaces, variants, and possible missing colon)
        if (preg_match('/Rendez[-\s]?vous\s*:?\s*(\d+)/iu', $text, $m)) {
            $parsed['rendez_vous_id'] = trim($m[1]);
        }
        // Diagnostic
        if (preg_match('/Diagnostic\s*:?(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['diagnostic'] = trim($m[1]);
        }
        // Observations
        if (preg_match('/Observations\s*:?(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['observations'] = trim($m[1]);
        }
        // Exam Results
        if (preg_match('/(Exam Results|Résultats Examens)\s*:?(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['resultatsExamens'] = trim($m[2]);
        }
        // Start Time
        if (preg_match('/Début\s*:?(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['startTime'] = trim($m[1]);
        }
        // End Time
        if (preg_match('/Fin\s*:?(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['endTime'] = trim($m[1]);
        }
        // Duration
        if (preg_match('/Durée\s*:?(.*?)(minutes?)/iu', $text, $m)) {
            $parsed['dureeMinutes'] = trim($m[1]);
        }
        // Created At
        if (preg_match('/Date de création\s*:?(.*?)(\n|$)/iu', $text, $m)) {
            $parsed['createdAt'] = trim($m[1]);
        }
        return $parsed;
    }
}
