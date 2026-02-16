<?php

namespace App\Service;

use GuzzleHttp\Client;

class TextReformulationService
{
    private Client $client;
    private string $apiKey;

    public function __construct(string $openAiApiKey)
    {
        $this->apiKey = $openAiApiKey;
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 30,
        ]);
    }

    public function reformuler(string $text): string
    {
        // Si le texte est vide, retourne vide
        if (empty(trim($text))) {
            return '';
        }

        try {
            $response = $this->client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Tu es un assistant de service client professionnel. Reformule toujours les textes de manière claire, polie et professionnelle, même si le texte semble déjà correct. Change au moins quelques mots pour rendre le texte plus professionnel et fluide.'
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Reformule ce texte de manière professionnelle : ' . $text
                        ],
                    ],
                    'temperature' => 0.7, // plus créatif
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Retourne la reformulation ou le texte original si le modèle ne renvoie rien
            return $data['choices'][0]['message']['content'] ?? $text;

        } catch (\Exception $e) {
            // En cas d'erreur OpenAI, on retourne le texte original
            return 'ERREUR OPENAI : ' . $e->getMessage();
        }
    }
}