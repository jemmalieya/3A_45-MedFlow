<?php
// src/Service/MyMemoryTranslateService.php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class MyMemoryTranslateService
{
    public function __construct(private HttpClientInterface $client) {}

    public function toFrench(string $text, string $sourceLang = 'auto'): ?string
    {
        $text = trim($text);
        if ($text === '') return null;

        // MyMemory n'aime pas trop "auto", on force en|fr par défaut
        $langpair = ($sourceLang && $sourceLang !== 'auto')
            ? $sourceLang . '|fr'
            : 'en|fr';

        $url = 'https://api.mymemory.translated.net/get?q=' . urlencode($text) . '&langpair=' . $langpair;

        $res = $this->client->request('GET', $url, ['timeout' => 10]);
        $data = $res->toArray(false);

        $translated = $data['responseData']['translatedText'] ?? null;
        $translated = is_string($translated) ? trim($translated) : null;

        return $translated !== '' ? $translated : null;
    }
}