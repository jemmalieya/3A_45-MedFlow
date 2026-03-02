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

   /**
 * @return array{
 *   temperature: float,
 *   description: string,
 *   icon: string,
 *   humidity: int,
 *   wind_speed: float
 * }
 */
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

    /** @var array<string, mixed> $data */
    $data = $response->toArray(false);

    return [
        'temperature' => (float) ($data['main']['temp'] ?? 0),
        'description' => (string) ($data['weather'][0]['description'] ?? ''),
        'icon'        => (string) ($data['weather'][0]['icon'] ?? ''),
        'humidity'    => (int) ($data['main']['humidity'] ?? 0),
        'wind_speed'  => (float) ($data['wind']['speed'] ?? 0),
    ];
}
}
