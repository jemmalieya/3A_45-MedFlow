<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiService
{
    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $model
    ) {}

    public function generate(string $prompt): string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            $this->model
        );

        $response = $this->http->request('POST', $url, [
            'headers' => [
                'x-goog-api-key' => $this->apiKey, // ✅ comme doc
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ],
        ]);

        $data = $response->toArray(false);

        return (string)($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
}
