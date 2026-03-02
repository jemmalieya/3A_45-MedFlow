<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class TesseractOcrService
{
    public function __construct(
        private HttpClientInterface $http,
        private string $ocrspaceKey
    ) {}

    /**
     * Extract text using local Tesseract if available.
     *
     * @return array{
     *   text: string|null,
     *   raw: array<string, mixed>|null,
     *   error: string|null
     * }
     */
    public function extractText(string $absoluteFilePath, string $lang = 'eng'): array
    {
        $tessPath = getenv('TESSERACT_PATH');
        $tessLang = getenv('TESSERACT_LANG') ?: $lang;

        if ($tessPath) {
            $outputFile = tempnam(sys_get_temp_dir(), 'ocr_');
            $cmd = sprintf('%s %s %s -l %s', escapeshellcmd($tessPath), escapeshellarg($absoluteFilePath), escapeshellarg($outputFile), escapeshellarg($tessLang));
            $output = [];
            $ret = null;
            try {
                exec($cmd . ' 2>&1', $output, $ret);
                if ($ret !== 0) {
                    return ['text' => null, 'raw' => null, 'error' => implode("\n", $output)];
                }

                $txtFile = $outputFile . '.txt';
                $text = is_file($txtFile) ? @file_get_contents($txtFile) : '';
                @unlink($txtFile);
                @unlink($outputFile);

                return ['text' => $text !== false ? (string)$text : '', 'raw' => null, 'error' => null];
            } catch (\Throwable $e) {
                // Fall through to OCR.space fallback
            }
        }

        // Fallback to OCR.space
        try {
            $res = $this->http->request('POST', 'https://api.ocr.space/parse/image', [
                'headers' => ['apikey' => $this->ocrspaceKey],
                'timeout' => 120,
                'max_duration' => 180,
                'body' => [
                    'language' => $lang,
                    'OCREngine' => '2',
                    'isOverlayRequired' => 'false',
                    'file' => fopen($absoluteFilePath, 'r'),
                ],
            ]);

            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            return ['text' => null, 'raw' => null, 'error' => $e->getMessage()];
        }

        if (!empty($data['IsErroredOnProcessing'])) {
            $msg = is_array($data['ErrorMessage']) ? ($data['ErrorMessage'][0] ?? 'OCR error') : ($data['ErrorMessage'] ?? 'OCR error');
            return ['text' => null, 'raw' => $data, 'error' => (string)$msg];
        }

        return ['text' => (string)($data['ParsedResults'][0]['ParsedText'] ?? ''), 'raw' => $data, 'error' => null];
    }
}
