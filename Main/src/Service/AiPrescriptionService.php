<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class AiPrescriptionService
{
    private string $apiKey;
    private HttpClientInterface $client;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $client, LoggerInterface $logger, string $huggingfaceApiKey)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->apiKey = $huggingfaceApiKey;
    }

    public function suggestPrescription(?string $text): ?string
    {
        if (empty($text)) {
            return '';
        }

        $prompt = sprintf(
            "Patient symptoms: %s. Suggest safe common medications with dosage for an adult patient. Only return medication names and dosage.",
            $text
        );

            $payload = [
                'model' => 'deepseek-ai/DeepSeek-V3.2',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ];

            try {
                $response = $this->client->request('POST', 'https://router.huggingface.co/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                    'json' => $payload,
                    'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                $this->logger->error('HuggingFace API error', ['status' => $status]);
                return null;
            }

            $data = $response->toArray(false);
                if (isset($data['choices'][0]['message']['content'])) {
                    return trim($data['choices'][0]['message']['content']);
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('AI prescription API failed', ['exception' => $e]);
            return null;
        }
    }
}
