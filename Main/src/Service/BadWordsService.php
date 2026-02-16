<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class BadWordsService
{
    public function __construct(private HttpClientInterface $client) {}

    /**
     * Retourne: ['hasBadWords' => bool, 'badWords' => string[]]
     */
    public function check(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['hasBadWords' => false, 'badWords' => []];
        }

        // ✅ Exemple API (Purgomalum) : renvoie la version censurée
        // Docs: https://www.purgomalum.com/
        $res = $this->client->request('GET', 'https://www.purgomalum.com/service/json', [
            'query' => ['text' => $text],
            'timeout' => 6,
        ]);

        $data = $res->toArray(false);
        $result = $data['result'] ?? '';

        // Si l'API a remplacé des mots par **** => badwords détectés
        $has = str_contains($result, '*');

        // On essaie d'extraire grossièrement les mots masqués (optionnel)
        // Ici, on renvoie juste un tableau vide si tu ne veux pas lister.
        return [
            'hasBadWords' => $has,
            'badWords' => [],
        ];
    }
}
