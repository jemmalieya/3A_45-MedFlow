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
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        // ✅ Cache 24h (évite rate-limit si tu refresh / ping)
        $cacheKey = 'geo_' . sha1(mb_strtolower($address));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($address) {
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

                $status = $response->getStatusCode();

                // ✅ Nominatim renvoie parfois HTML en cas de blocage -> on ne parse pas JSON
                if ($status !== 200) {
                    // (option debug) :
                    // $raw = $response->getContent(false);
                    // dump('Nominatim status='.$status, $raw);
                    return null;
                }

                // ✅ Si JSON invalide, toArray(false) peut lever une exception => catch
                $data = $response->toArray(false);

                if (!is_array($data) || empty($data) || !isset($data[0]['lat'], $data[0]['lon'])) {
                    return null;
                }

                return [
                    'lat' => (float) $data[0]['lat'],
                    'lng' => (float) $data[0]['lon'],
                ];
            } catch (\Throwable $e) {
                // (option debug) :
                // dump('Geocoding error: '.$e->getMessage());
                return null;
            }
        });
    }
}