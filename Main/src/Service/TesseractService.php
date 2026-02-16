<?php
namespace App\Service;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class TesseractService
{
    private $tesseractPath;

    public function __construct(string $tesseractPath = 'tesseract')
    {
        $this->tesseractPath = $tesseractPath;
    }

    public function extractText(string $imagePath): string
    {
        // Construire la commande Tesseract pour extraire le texte de l'image
        $process = new Process([$this->tesseractPath, $imagePath, 'stdout']);
        $process->run();

        // Vérifier si le processus s'est exécuté correctement
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // Retourner le texte extrait par Tesseract
        return $process->getOutput();
    }
}
