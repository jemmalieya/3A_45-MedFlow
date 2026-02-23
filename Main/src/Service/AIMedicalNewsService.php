<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AIMedicalNewsService
{
    private HttpClientInterface $client;
    private CacheInterface $cache;
    private string $apiKey;

    public function __construct(HttpClientInterface $client, CacheInterface $cache)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->apiKey = $_ENV['OPENAI_API_KEYW']; // or HF token
    }

    public function getHourlyInsights(): array
    {
        return $this->cache->get('hourly_medical_news', function (ItemInterface $item) {
            $item->expiresAfter(3600); // 1 hour cache

            $prompt = "\nGenerate 5 short, professional medical insights.\nEach should be 1 sentence.\nTopics: clinical innovation, prevention, research, awareness.\nReturn JSON array format:\n[\n  {\"title\":\"...\",\"content\":\"...\"}\n]\n";

            try {
                $response = $this->client->request('POST', 'https://api.openai.com/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt]
                        ],
                        'temperature' => 0.7
                    ]
                ]);

                $data = $response->toArray();
                $content = $data['choices'][0]['message']['content'];
                $insights = json_decode($content, true);
                if (is_array($insights) && count($insights) === 5) {
                    return $insights;
                }
            } catch (\Throwable $e) {
                // Log error if needed
            }
            // Fallback static insights
            return [
                ["title" => "Telemedicine Growth", "content" => "Telemedicine continues to expand, improving access to care worldwide."],
                ["title" => "AI in Diagnostics", "content" => "AI-powered tools are enhancing diagnostic accuracy in radiology and pathology."],
                ["title" => "Preventive Health", "content" => "Preventive screenings and early interventions reduce chronic disease burden."],
                ["title" => "Mental Health Awareness", "content" => "Hospitals are increasing mental health support for both patients and staff."],
                ["title" => "Wearable Tech", "content" => "Wearable devices help monitor patient vitals and encourage healthy habits."]
            ];
        });
    }
}
