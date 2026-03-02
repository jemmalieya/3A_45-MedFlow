<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class GeocodingService
{
    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache
    ) {}

    /**
     * Retourne ['lat' => float, 'lng' => float] ou null.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        // ✅ Cache 24h (évite rate-limit si tu refresh / ping)
        $cacheKey = 'geo_' . sha1(mb_strtolower($address));

        /** @var array{lat: float, lng: float}|null $result */
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($address): ?array {
            $item->expiresAfter(86400); // 24h

            try {
                $response = $this->http->request('GET', 'https://nominatim.openstreetmap.org/search', [
                    'query' => [
                        'q' => $address,
                        'format' => 'json',
                        'limit' => 1,
                        'addressdetails' => 0,
                    ],
                    'headers' => [
                        // ⚠️ Mets un vrai mail/identité (important pour Nominatim)
                        'User-Agent' => '3A_45-MedFlow/1.0 (contact: your_real_email@domain.com)',
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 10,
                ]);

                if ($response->getStatusCode() !== 200) {
                    return null;
                }

                // toArray(false) => array (mais contenu "mixed")
                /** @var array<int, array<string, mixed>> $data */
                $data = $response->toArray(false);

                if ($data === [] || !isset($data[0]['lat'], $data[0]['lon'])) {
                    return null;
                }

                $latRaw = $data[0]['lat'];
                $lonRaw = $data[0]['lon'];

                // On sécurise : Nominatim renvoie souvent des strings numériques
                if (!is_scalar($latRaw) || !is_scalar($lonRaw)) {
                    return null;
                }

                return [
                    'lat' => (float) $latRaw,
                    'lng' => (float) $lonRaw,
                ];
            } catch (\Throwable $e) {
                return null;
            }
        });

        return $result;
    }
}