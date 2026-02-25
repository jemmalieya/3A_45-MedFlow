<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UrgencyDetectionService
{
    private HttpClientInterface $client;
    private string $openaiApiKey;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
        $this->openaiApiKey = $_ENV['OPENAI_API_KEYW'] ?? getenv('OPENAI_API_KEYW') ?? '';
    }

    public function detectUrgency(string $motif): string
    {
        $prompt = "You are a professional medical triage assistant.\nClassify the urgency level of the patient description below.\nRespond ONLY with one word:\nLow, Normal, High, or Critical.\n\nPatient description:\n\"$motif\"";
        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a professional medical triage assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            'max_tokens' => 5,
        ];
        try {
            $response = $this->client->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->openaiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);
            $data = $response->toArray();
            $result = $data['choices'][0]['message']['content'] ?? 'Normal';
            $result = trim($result);
            if (!in_array($result, ['Low', 'Normal', 'High', 'Critical'])) {
                return 'Normal';
            }
            return $result;
        } catch (\Exception $e) {
            return 'Normal';
        }
    }
}
