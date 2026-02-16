<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class WeatherService
{
    private HttpClientInterface $client;
    private string $apiKey;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
        $this->apiKey = $_ENV['OPENWEATHER_API_KEY'] ?? '';
    }

    public function getWeather(string $city): array
    {
        if (!$this->apiKey) {
            throw new \RuntimeException("OPENWEATHER_API_KEY manquante.");
        }

        $response = $this->client->request(
            'GET',
            'https://api.openweathermap.org/data/2.5/weather',
            [
                'query' => [
                    'q' => $city,
                    'appid' => $this->apiKey,
                    'units' => 'metric',
                    'lang' => 'fr'
                ]
            ]
        );

        return $response->toArray();
    }
}
