<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiRiskService
{
    public function __construct(
        private HttpClientInterface $http,
        private string $groqKey,
        private string $groqModel, // ✅ injecté depuis .env
    ) {}

    public function analyzeEventRisk(array $eventData): array
    {
        $prompt = "Analyse cet événement et retourne UNIQUEMENT un JSON valide.
Format exact attendu :
{
  \"riskScore\": number,
  \"reasons\": [\"raison1\", \"raison2\"],
  \"suggestions\": [\"suggestion1\"]
}

Données :
" . json_encode($eventData, JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->http->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->groqKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => $this->groqModel, // ✅ plus de modèle mort
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.1,
                ],
                'timeout' => 20,
            ]);

            $data = $response->toArray(false);

            // si Groq renvoie une erreur
            if (isset($data['error'])) {
                return [
                    'riskScore' => 0,
                    'reasons' => ['Erreur Groq: ' . ($data['error']['message'] ?? 'unknown')],
                    'suggestions' => [],
                    'debug' => $data,
                ];
            }

            $content = $data['choices'][0]['message']['content'] ?? '';

            // ✅ extraction JSON même si le modèle ajoute du texte
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $json = json_decode($matches[0], true);
                if (is_array($json) && isset($json['riskScore'])) {
                    return [
                        'riskScore' => max(0, min(100, (int) ($json['riskScore'] ?? 0))),
                        'reasons' => is_array($json['reasons'] ?? null) ? $json['reasons'] : [],
                        'suggestions' => is_array($json['suggestions'] ?? null) ? $json['suggestions'] : [],
                        'raw' => $content,
                    ];
                }
            }

            return [
                'riskScore' => 0,
                'reasons' => ['Réponse IA invalide (JSON non détecté).'],
                'suggestions' => [],
                'raw' => $content,
            ];
        } catch (\Throwable $e) {
            return [
                'riskScore' => 0,
                'reasons' => ['Exception HTTP: ' . $e->getMessage()],
                'suggestions' => [],
            ];
        }
    }
}