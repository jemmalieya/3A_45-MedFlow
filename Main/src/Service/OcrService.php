<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OcrService
{
    public function __construct(
        private HttpClientInterface $http,
        private string $ocrspaceKey
    ) {}

    public function extractText(string $absoluteFilePath, string $lang = 'fre'): string
    {
        $res = $this->http->request('POST', 'https://api.ocr.space/parse/image', [
            'headers' => ['apikey' => $this->ocrspaceKey],
            'body' => [
                'language' => $lang,         // 'fre' ou 'eng'
                'OCREngine' => '2',
                'isOverlayRequired' => 'false',
                'file' => fopen($absoluteFilePath, 'r'),
            ],
        ]);

        $data = $res->toArray(false);

        if (!empty($data['IsErroredOnProcessing'])) {
            $msg = $data['ErrorMessage'][0] ?? 'OCR error';
            throw new \RuntimeException($msg);
        }

        return (string)($data['ParsedResults'][0]['ParsedText'] ?? '');
    }
}