<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GroqService
{
    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $model
    ) {}

    public function chat(string $message): string
    {
        $message = trim($message);

        $payload = [
            "model" => $this->model,
            "messages" => [
                [
                    "role" => "system",
                    "content" =>
                        "Tu es un assistant Pharmacie pour un site e-commerce MedFlow. "
                        ."Réponds en français, clair et court. "
                        ."Pas de diagnostic médical. Si c'est sérieux, conseiller un médecin/pharmacien."
                ],
                ["role" => "user", "content" => $message],
            ],
            "temperature" => 0.6,
            "max_tokens" => 400,
        ];

        $res = $this->http->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => $payload,
        ]);

        $data = $res->toArray(false);

        return (string)($data['choices'][0]['message']['content'] ?? "Désolé, je n’ai pas pu répondre.");
    }
}
