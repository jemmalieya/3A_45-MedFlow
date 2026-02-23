<?php
// ============================================================
//  src/Service/EpidemieDetectionService.php
// ============================================================

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use League\Csv\Writer;

class EpidemieDetectionService
{
    // ─── Seuils de détection ───────────────────────────────────
    private const SEUIL_MODERE   = 1.25;   // ratio baseline => alerte jaune
    private const SEUIL_FORT     = 1.60;   // ratio baseline => alerte rouge
    private const CACHE_TTL      = 3600;   // 1h cache API

    // ─── Mots-clés par maladie (adaptés à ta pharmacie) ────────
    private const MALADIES = [
        'Grippe / Rhume' => [
            'keywords' => ['grippe', 'rhinite', 'fièvre', 'fiev', 'anti-gripp', 'nasal', 'sirop', 'décongest'],
            'icon'     => '🤧',
            'color'    => '#6366f1',
        ],
        'Infections respiratoires' => [
            'keywords' => ['bronchite', 'toux', 'expector', 'mucolyt', 'bronch', 'pulmo', 'antitussif'],
            'icon'     => '🫁',
            'color'    => '#06b6d4',
        ],
        'Infections cutanées' => [
            'keywords' => ['dermat', 'eczéma', 'eruption', 'antisept', 'cicatr', 'antifong', 'champign'],
            'icon'     => '🩹',
            'color'    => '#f59e0b',
        ],
        'Troubles digestifs' => [
            'keywords' => ['diarrhée', 'gastro', 'vomiss', 'probiot', 'antidiarr', 'reflux', 'antiacide'],
            'icon'     => '🫀',
            'color'    => '#10b981',
        ],
        'Douleur / Inflammation' => [
            'keywords' => ['paracétam', 'paracetam', 'ibuprof', 'anti-inflam', 'douleur', 'antalgiq', 'aspirine'],
            'icon'     => '💊',
            'color'    => '#f43f5e',
        ],
        'Allergies' => [
            'keywords' => ['allergie', 'antihistam', 'loratad', 'cetiriz', 'pollen', 'rhinite'],
            'icon'     => '🌿',
            'color'    => '#84cc16',
        ],
        'Infections urinaires' => [
            'keywords' => ['cystite', 'urinaire', 'antibio', 'urosept', 'cranber'],
            'icon'     => '🧫',
            'color'    => '#a855f7',
        ],
    ];

    public function __construct(
        private readonly HttpClientInterface    $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface         $cache,
        private readonly string                 $apiNinjasKey,
        private readonly string                 $openWeatherApiKey,
    ) {}

    // ============================================================
    //  API 1 : WHO Disease Outbreaks (données officielles OMS)
    //  URL : https://www.who.int/api/news/diseaseoutbreaks
    // ============================================================
    public function getWhoOutbreaks(string $region = 'EMRO'): array
    {
        return $this->cache->get('who_outbreaks_' . $region, function (ItemInterface $item) use ($region) {
            $item->expiresAfter(self::CACHE_TTL * 6); // 6h pour l'OMS

            try {
                $res  = $this->httpClient->request('GET',
                    'https://www.who.int/api/news/diseaseoutbreaks',
                    [
                        'timeout' => 10,
                        'query' => ['sf_culture' => 'fr'],
                    ]
                );

                $data = $res->toArray(false);
                $outbreaks = array_slice($data['value'] ?? [], 0, 8);

                return [
                    'ok'        => true,
                    'source'    => 'WHO Official API',
                    'outbreaks' => array_map(static fn($o) => [
                        'titre' => $o['Title']       ?? 'Inconnu',
                        'pays'  => $o['CountryName'] ?? '—',
                        'date'  => $o['ReportDate']  ?? '',
                        'url'   => $o['Url']         ?? '#',
                    ], $outbreaks),
                ];

            } catch (\Throwable $e) {
                return ['ok' => false, 'source' => 'WHO', 'error' => $e->getMessage(), 'outbreaks' => []];
            }
        });
    }

    // ============================================================
    //  API 2 : OpenWeatherMap + (optionnel) API Ninjas worldtime
    //  - OpenWeather : météo actuelle + risque grippal
    //  - Ninjas : worldtime (optionnel, affichage bonus)
    // ============================================================
    public function getInfluenzaData(string $country = 'Tunisia'): array
    {
        return $this->cache->get('influenza_' . md5($country), function (ItemInterface $item) use ($country) {
            $item->expiresAfter(self::CACHE_TTL * 12);

            try {
                // ==============================
                // API Ninjas (OPTIONNELLE)
                // ==============================
                $timeInfo = null;

                if (!empty($this->apiNinjasKey)) {
                    // city=... peut être premium chez Ninjas -> on utilise timezone gratuit
                    $tz = ($country === 'Tunisia') ? 'Africa/Tunis' : 'UTC';

                    $resTime = $this->httpClient->request('GET', 'https://api.api-ninjas.com/v1/worldtime', [
                        'timeout' => 8,
                        'headers' => ['X-Api-Key' => $this->apiNinjasKey],
                        'query'   => ['timezone' => $tz],
                    ]);

                    $timeInfo = $resTime->toArray(false);
                }

                // ==============================
                // OpenWeatherMap (OBLIGATOIRE pour météo)
                // ==============================
                if (empty($this->openWeatherApiKey)) {
                    return [
                        'ok'       => false,
                        'source'   => 'OpenWeatherMap',
                        'error'    => 'OPENWEATHER_API_KEY manquante',
                        'timeInfo' => $timeInfo,
                    ];
                }

                $city = ($country === 'Tunisia') ? 'Tunis' : $country;

                $meteoRes = $this->httpClient->request('GET',
                    'https://api.openweathermap.org/data/2.5/weather',
                    [
                        'timeout' => 8,
                        'query'   => [
                            'q'     => $city,
                            'appid' => $this->openWeatherApiKey,
                            'units' => 'metric',
                            'lang'  => 'fr',
                        ],
                    ]
                );

                $meteo = $meteoRes->toArray(false);

                $temp        = $meteo['main']['temp'] ?? null;
                $humidity    = $meteo['main']['humidity'] ?? null;
                $description = $meteo['weather'][0]['description'] ?? 'Inconnu';
                $icon        = $meteo['weather'][0]['icon'] ?? '';

                // Calcul risque grippal selon météo
                $risqueGrippe = 'Faible';
                if ($temp !== null && $humidity !== null) {
                    if ($temp < 10 && $humidity > 70) $risqueGrippe = 'Élevé';
                    elseif ($temp < 15 || $humidity > 65) $risqueGrippe = 'Modéré';
                }

                return [
                    'ok'           => true,
                    'source'       => 'OpenWeatherMap',
                    'ville'        => $meteo['name'] ?? $city,
                    'temperature'  => $temp,
                    'humidity'     => $humidity,
                    'description'  => $description,
                    'icon'         => $icon ? "https://openweathermap.org/img/wn/{$icon}@2x.png" : '',
                    'risqueGrippe' => $risqueGrippe,
                    'timeInfo'     => $timeInfo,
                ];

            } catch (\Throwable $e) {
                return ['ok' => false, 'source' => 'OpenWeatherMap', 'error' => $e->getMessage()];
            }
        });
    }

    // ============================================================
    //  API 3 : Open Meteo (gratuit, sans clé) — historique 30 jours
    //  météo pour corrélation maladies saisonnières
    // ============================================================
    public function getMeteoHistorique(float $lat = 36.8, float $lon = 10.18): array
    {
        $cacheKey = 'meteo_histo_' . round($lat, 1) . '_' . round($lon, 1);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($lat, $lon) {
            $item->expiresAfter(self::CACHE_TTL * 24);

            try {
                $end   = (new \DateTime())->format('Y-m-d');
                $start = (new \DateTime('-30 days'))->format('Y-m-d');

                $res  = $this->httpClient->request('GET',
                    'https://archive-api.open-meteo.com/v1/archive',
                    [
                        'timeout' => 12,
                        'query'   => [
                            'latitude'   => $lat,
                            'longitude'  => $lon,
                            'start_date' => $start,
                            'end_date'   => $end,
                            'daily'      => 'temperature_2m_mean,precipitation_sum,relative_humidity_2m_mean',
                            'timezone'   => 'Africa/Tunis',
                        ],
                    ]
                );

                $data = $res->toArray(false);
                $daily = $data['daily'] ?? [];

                return [
                    'ok'             => true,
                    'source'         => 'Open-Meteo (sans clé)',
                    'dates'          => $daily['time'] ?? [],
                    'temperatures'   => $daily['temperature_2m_mean'] ?? [],
                    'precipitations' => $daily['precipitation_sum'] ?? [],
                    'humidites'      => $daily['relative_humidity_2m_mean'] ?? [],
                ];

            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'source' => 'Open-Meteo',
                    'error' => $e->getMessage(),
                    'dates' => [],
                    'temperatures' => [],
                    'precipitations' => [],
                    'humidites' => [],
                ];
            }
        });
    }

    // ============================================================
    //  DÉTECTION LOCALE — signaux ventes de ta pharmacie
    // ============================================================
    public function localSignalsChart(int $daysShort = 7, int $daysLong = 30): array
    {
        $labels  = [];
        $valeurs = [];
        $meta    = [];

        foreach (self::MALADIES as $maladie => $config) {
            $short    = $this->sumSoldByKeywords($config['keywords'], $daysShort);
            $long     = $this->sumSoldByKeywords($config['keywords'], $daysLong);
            $baseline = ($long / max(1, $daysLong)) * $daysShort;
            $ratio    = $baseline > 0 ? ($short / $baseline) : 0;

            $level = 'normal';
            if ($ratio >= self::SEUIL_FORT) $level = 'fort';
            elseif ($ratio >= self::SEUIL_MODERE) $level = 'modere';

            $labels[]  = $maladie;
            $valeurs[] = (int) round($short);
            $meta[]    = [
                'label'     => $maladie,
                'icon'      => $config['icon'],
                'color'     => $config['color'],
                'last'      => (int) round($short),
                'baseline'  => (int) round($baseline),
                'ratio'     => round($ratio, 2),
                'level'     => $level,
                'variation' => $baseline > 0 ? round(($ratio - 1) * 100) : 0,
            ];
        }

        // Score de risque global
        $score = array_sum(array_map(static fn($m) => match ($m['level']) {
            'fort'   => 3,
            'modere' => 1,
            default  => 0,
        }, $meta));

        $maxScore = count(self::MALADIES) * 3;
        $risk = $score >= $maxScore * 0.6 ? 'Élevé'
             : ($score >= $maxScore * 0.3 ? 'Modéré' : 'Faible');

        return [
            'labels'   => $labels,
            'colors'   => array_column(array_values(self::MALADIES), 'color'),
            'series'   => [
                ['name' => "Ventes ({$daysShort}j)", 'data' => $valeurs],
            ],
            'risk'     => $risk,
            'score'    => $score,
            'maxScore' => $maxScore,
            'meta'     => $meta,
        ];
    }

    // ============================================================
    //  TENDANCES 6 MOIS (pour le chart évolution)
    // ============================================================
    public function getTendances6Mois(): array
    {
        $series     = [];
        $categories = [];

        for ($i = 5; $i >= 0; $i--) {
            $d = new \DateTime("first day of -$i month");
            $categories[] = $d->format('M Y');
        }

        foreach (self::MALADIES as $maladie => $config) {
            $data = [];
            for ($i = 5; $i >= 0; $i--) {
                $debut = (new \DateTime("first day of -$i month"))->setTime(0, 0);
                $fin   = (new \DateTime("last day of -$i month"))->setTime(23, 59, 59);
                $data[] = (int) $this->sumSoldByKeywordsBetween($config['keywords'], $debut, $fin);
            }
            $series[] = [
                'name'  => $config['icon'] . ' ' . $maladie,
                'data'  => $data,
                'color' => $config['color'],
            ];
        }

        return ['categories' => $categories, 'series' => $series];
    }

    // ============================================================
    //  TOP PRODUITS par maladie détectée
    // ============================================================
    public function getTopProduitsByMaladie(int $days = 30): array
    {
        $result = [];

        foreach (self::MALADIES as $maladie => $config) {
            $top = $this->getTopProduitsForKeywords($config['keywords'], $days, 3);
            if (!empty($top)) {
                $result[] = [
                    'maladie'  => $maladie,
                    'icon'     => $config['icon'],
                    'color'    => $config['color'],
                    'produits' => $top,
                ];
            }
        }

        return $result;
    }

    // ============================================================
    //  EXPORT CSV via league/csv
    // ============================================================
    public function exportSignalsCsv(array $meta): string
    {
        $csv = Writer::createFromString();
        $csv->insertOne(['Maladie', 'Ventes 7j', 'Baseline', 'Ratio', 'Niveau', 'Variation %']);

        foreach ($meta as $m) {
            $csv->insertOne([
                $m['label'],
                $m['last'],
                $m['baseline'],
                $m['ratio'],
                $m['level'],
                $m['variation'] . '%',
            ]);
        }

        return $csv->toString();
    }

    // ============================================================
    //  HELPERS REQUÊTES BDD
    // ============================================================
    private function sumSoldByKeywords(array $keywords, int $days): float
    {
        $since = (new \DateTimeImmutable())->modify("-{$days} days");
        return $this->sumSoldByKeywordsBetween($keywords, $since, new \DateTimeImmutable());
    }

    private function sumSoldByKeywordsBetween(array $keywords, \DateTimeInterface $debut, \DateTimeInterface $fin): float
{
    $or     = [];
    $params = ['debut' => $debut, 'fin' => $fin];

    foreach ($keywords as $i => $w) {
        $or[]            = "(LOWER(p.nom_produit) LIKE :k{$i} OR LOWER(p.description_produit) LIKE :k{$i} OR LOWER(p.categorie_produit) LIKE :k{$i})";
        $params["k{$i}"] = '%' . mb_strtolower($w) . '%';
    }

    $dql = "
        SELECT COALESCE(SUM(lc.quantite_commandee), 0)
        FROM App\Entity\LigneCommande lc
        JOIN lc.commande c
        JOIN lc.produit p
        WHERE c.paid_at IS NOT NULL
          AND c.date_creation_commande >= :debut
          AND c.date_creation_commande <= :fin
          AND (" . implode(' OR ', $or) . ")
    ";

    return (float) $this->em
        ->createQuery($dql)
        ->setParameters($params)
        ->getSingleScalarResult();
}

private function getTopProduitsForKeywords(array $keywords, int $days, int $limit): array
{
    $since  = (new \DateTimeImmutable())->modify("-{$days} days");
    $or     = [];
    $params = ['since' => $since];

    foreach ($keywords as $i => $w) {
        $or[]            = "(LOWER(p.nom_produit) LIKE :k{$i} OR LOWER(p.categorie_produit) LIKE :k{$i})";
        $params["k{$i}"] = '%' . mb_strtolower($w) . '%';
    }

    $dql = "
        SELECT p.nom_produit AS nom, SUM(lc.quantite_commandee) AS total
        FROM App\Entity\LigneCommande lc
        JOIN lc.commande c
        JOIN lc.produit p
        WHERE c.paid_at IS NOT NULL
          AND c.date_creation_commande >= :since
          AND (" . implode(' OR ', $or) . ")
        GROUP BY p.nom_produit
        ORDER BY total DESC
    ";

    return $this->em
        ->createQuery($dql)
        ->setParameters($params)
        ->setMaxResults($limit)
        ->getResult();
}
}