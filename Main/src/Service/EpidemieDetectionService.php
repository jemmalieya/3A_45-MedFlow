<?php
// ============================================================
//  src/Service/EpidemieDetectionService.php
// ============================================================

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Writer;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EpidemieDetectionService
{
    private const SEUIL_MODERE = 1.25;
    private const SEUIL_FORT   = 1.60;
    private const CACHE_TTL    = 3600;

    /**
     * @var array<string, array{keywords: list<string>, icon: string, color: string}>
     */
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
    //  API 1 : WHO Disease Outbreaks
    // ============================================================

    /**
     * @return array{
     *   ok: bool,
     *   source: string,
     *   outbreaks: array<int, array{titre:string, pays:string, date:string, url:string}>,
     *   error?: string
     * }
     */
    public function getWhoOutbreaks(string $region = 'EMRO'): array
    {
        return $this->cache->get('who_outbreaks_' . $region, function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL * 6);
            try {
                $res  = $this->httpClient->request('GET', 'https://www.who.int/api/news/diseaseoutbreaks', [
                    'timeout' => 10,
                    'query'   => ['sf_culture' => 'fr'],
                ]);
                $data = $res->toArray(false);
                /** @var array<int, array<string, mixed>> $raw */
                $raw       = array_values((array) ($data['value'] ?? []));
                $outbreaks = array_slice($raw, 0, 8);
                return [
                    'ok'        => true,
                    'source'    => 'WHO Official API',
                    'outbreaks' => array_map(static fn(array $o): array => [
                        'titre' => (string) ($o['Title']       ?? 'Inconnu'),
                        'pays'  => (string) ($o['CountryName'] ?? '—'),
                        'date'  => (string) ($o['ReportDate']  ?? ''),
                        'url'   => (string) ($o['Url']         ?? '#'),
                    ], $outbreaks),
                ];
            } catch (\Throwable $e) {
                return ['ok' => false, 'source' => 'WHO', 'error' => $e->getMessage(), 'outbreaks' => []];
            }
        });
    }

    // ============================================================
    //  API 2 : OpenWeatherMap
    // ============================================================

    /**
     * @return array{
     *   ok: bool, source: string, error?: string, ville?: string,
     *   temperature?: float|int|null, humidity?: int|null, description?: string,
     *   icon?: string, risqueGrippe?: string, timeInfo?: array<string, mixed>|null
     * }
     */
    public function getInfluenzaData(string $country = 'Tunisia'): array
    {
        return $this->cache->get('influenza_' . md5($country), function (ItemInterface $item) use ($country) {
            $item->expiresAfter(self::CACHE_TTL * 12);
            try {
                $timeInfo = null;
                if (!empty($this->apiNinjasKey)) {
                    $tz      = ($country === 'Tunisia') ? 'Africa/Tunis' : 'UTC';
                    $resTime = $this->httpClient->request('GET', 'https://api.api-ninjas.com/v1/worldtime', [
                        'timeout' => 8,
                        'headers' => ['X-Api-Key' => $this->apiNinjasKey],
                        'query'   => ['timezone' => $tz],
                    ]);
                    /** @var array<string, mixed> $timeInfo */
                    $timeInfo = $resTime->toArray(false);
                }
                if (empty($this->openWeatherApiKey)) {
                    return ['ok' => false, 'source' => 'OpenWeatherMap', 'error' => 'OPENWEATHER_API_KEY manquante', 'timeInfo' => $timeInfo];
                }
                $city     = ($country === 'Tunisia') ? 'Tunis' : $country;
                $meteoRes = $this->httpClient->request('GET', 'https://api.openweathermap.org/data/2.5/weather', [
                    'timeout' => 8,
                    'query'   => ['q' => $city, 'appid' => $this->openWeatherApiKey, 'units' => 'metric', 'lang' => 'fr'],
                ]);
                $meteo        = $meteoRes->toArray(false);
                $temp         = $meteo['main']['temp'] ?? null;
                $humidity     = $meteo['main']['humidity'] ?? null;
                $description  = $meteo['weather'][0]['description'] ?? 'Inconnu';
                $icon         = $meteo['weather'][0]['icon'] ?? '';
                $risqueGrippe = 'Faible';
                if ($temp !== null && $humidity !== null) {
                    if ($temp < 10 && $humidity > 70)      { $risqueGrippe = 'Élevé'; }
                    elseif ($temp < 15 || $humidity > 65)  { $risqueGrippe = 'Modéré'; }
                }
                return [
                    'ok'           => true,
                    'source'       => 'OpenWeatherMap',
                    'ville'        => (string) ($meteo['name'] ?? $city),
                    'temperature'  => is_numeric($temp) ? (float) $temp : null,
                    'humidity'     => is_numeric($humidity) ? (int) $humidity : null,
                    'description'  => (string) $description,
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
    //  API 3 : Open Meteo — historique 30 jours
    // ============================================================

    /**
     * @return array{
     *   ok: bool, source: string, error?: string,
     *   dates: string[], temperatures: array<int, float|int>,
     *   precipitations: array<int, float|int>, humidites: array<int, float|int>
     * }
     */
    public function getMeteoHistorique(float $lat = 36.8, float $lon = 10.18): array
    {
        return $this->cache->get('meteo_histo_' . round($lat, 1) . '_' . round($lon, 1), function (ItemInterface $item) use ($lat, $lon) {
            $item->expiresAfter(self::CACHE_TTL * 24);
            try {
                $res = $this->httpClient->request('GET', 'https://archive-api.open-meteo.com/v1/archive', [
                    'timeout' => 12,
                    'query'   => [
                        'latitude' => $lat, 'longitude' => $lon,
                        'start_date' => (new \DateTime('-30 days'))->format('Y-m-d'),
                        'end_date'   => (new \DateTime())->format('Y-m-d'),
                        'daily'    => 'temperature_2m_mean,precipitation_sum,relative_humidity_2m_mean',
                        'timezone' => 'Africa/Tunis',
                    ],
                ]);
                $data  = $res->toArray(false);
                $daily = (isset($data['daily']) && is_array($data['daily'])) ? $data['daily'] : [];
                return [
                    'ok'             => true,
                    'source'         => 'Open-Meteo (sans clé)',
                    'dates'          => isset($daily['time']) && is_array($daily['time']) ? array_map('strval', $daily['time']) : [],
                    'temperatures'   => isset($daily['temperature_2m_mean']) && is_array($daily['temperature_2m_mean']) ? array_map('floatval', $daily['temperature_2m_mean']) : [],
                    'precipitations' => isset($daily['precipitation_sum']) && is_array($daily['precipitation_sum']) ? array_map('floatval', $daily['precipitation_sum']) : [],
                    'humidites'      => isset($daily['relative_humidity_2m_mean']) && is_array($daily['relative_humidity_2m_mean']) ? array_map('floatval', $daily['relative_humidity_2m_mean']) : [],
                ];
            } catch (\Throwable $e) {
                return ['ok' => false, 'source' => 'Open-Meteo', 'error' => $e->getMessage(), 'dates' => [], 'temperatures' => [], 'precipitations' => [], 'humidites' => []];
            }
        });
    }

    // ============================================================
    //  DÉTECTION LOCALE — signaux ventes
    //
    //  ✅ FIX "8 aggregation queries" :
    //  Avant : 1 SELECT SUM par maladie × 2 périodes = 14-16 requêtes
    //  Après : sumAllMaladiesBetween() fait UNE SEULE requête avec
    //          CASE WHEN par maladie → 2 requêtes total (short + long)
    // ============================================================

    /**
     * @return array{
     *   labels: string[], colors: string[],
     *   series: array<int, array{name:string, data: int[]}>,
     *   risk: string, score: int, maxScore: int,
     *   meta: array<int, array{
     *     label:string, icon:string, color:string, last:int, baseline:int,
     *     ratio: float, level:string, variation: float|int
     *   }>
     * }
     */
    public function localSignalsChart(int $daysShort = 7, int $daysLong = 30): array
    {
        $now        = new \DateTimeImmutable();
        $sinceShort = $now->modify("-{$daysShort} days");
        $sinceLong  = $now->modify("-{$daysLong} days");

        // ✅ 2 requêtes au lieu de 14+ : une par période, toutes maladies agrégées
        $shortTotals = $this->sumAllMaladiesBetween($sinceShort, $now);
        $longTotals  = $this->sumAllMaladiesBetween($sinceLong, $now);

        $labels  = [];
        $valeurs = [];
        $meta    = [];

        foreach (self::MALADIES as $maladie => $config) {
            $short    = (float) ($shortTotals[$maladie] ?? 0);
            $long     = (float) ($longTotals[$maladie]  ?? 0);
            $baseline = ($long / max(1, $daysLong)) * $daysShort;
            $ratio    = $baseline > 0 ? ($short / $baseline) : 0;

            $level = 'normal';
            if ($ratio >= self::SEUIL_FORT)   { $level = 'fort'; }
            elseif ($ratio >= self::SEUIL_MODERE) { $level = 'modere'; }

            $labels[]  = $maladie;
            $valeurs[] = (int) round($short);
            $meta[]    = [
                'label'     => $maladie,
                'icon'      => (string) $config['icon'],
                'color'     => (string) $config['color'],
                'last'      => (int) round($short),
                'baseline'  => (int) round($baseline),
                'ratio'     => (float) round($ratio, 2),
                'level'     => $level,
                'variation' => $baseline > 0 ? (float) round(($ratio - 1) * 100, 2) : 0,
            ];
        }

        $score    = array_sum(array_map(static fn(array $m): int => match ($m['level']) { 'fort' => 3, 'modere' => 1, default => 0 }, $meta));
        $maxScore = count(self::MALADIES) * 3;
        $risk     = $score >= $maxScore * 0.6 ? 'Élevé' : ($score >= $maxScore * 0.3 ? 'Modéré' : 'Faible');

        return [
            'labels'   => $labels,
            'colors'   => array_column(array_values(self::MALADIES), 'color'),
            'series'   => [['name' => "Ventes ({$daysShort}j)", 'data' => $valeurs]],
            'risk'     => $risk,
            'score'    => $score,
            'maxScore' => $maxScore,
            'meta'     => $meta,
        ];
    }

    // ============================================================
    //  TENDANCES 6 MOIS
    //
    //  ✅ FIX : 6 requêtes (une par mois) au lieu de 42 (7×6)
    //  Chaque appel sumAllMaladiesBetween() calcule toutes les maladies
    //  en une seule requête SQL avec CASE WHEN.
    // ============================================================

    /**
     * @return array{
     *   categories: string[],
     *   series: array<int, array{name:string, data:int[], color:string}>
     * }
     */
    public function getTendances6Mois(): array
    {
        $categories     = [];
        $periodes       = [];
        $dataParPeriode = [];

        for ($i = 5; $i >= 0; $i--) {
            $debut        = \DateTimeImmutable::createFromMutable((new \DateTime("first day of -$i month"))->setTime(0, 0));
            $fin          = \DateTimeImmutable::createFromMutable((new \DateTime("last day of -$i month"))->setTime(23, 59, 59));
            $categories[] = $debut->format('M Y');
            $periodes[]   = ['debut' => $debut, 'fin' => $fin];
            // ✅ 1 requête SQL par mois, toutes maladies en même temps
            $dataParPeriode[] = $this->sumAllMaladiesBetween($debut, $fin);
        }

        $series = [];
        foreach (self::MALADIES as $maladie => $config) {
            $data = [];
            foreach ($dataParPeriode as $totaux) {
                $data[] = (int) ($totaux[$maladie] ?? 0);
            }
            $series[] = [
                'name'  => (string) $config['icon'] . ' ' . $maladie,
                'data'  => $data,
                'color' => (string) $config['color'],
            ];
        }

        return ['categories' => $categories, 'series' => $series];
    }

    // ============================================================
    //  TOP PRODUITS
    // ============================================================

    /**
     * @return array<int, array{
     *   maladie: string, icon: string, color: string,
     *   produits: array<int, array{nom:string, total: string|int|float}>
     * }>
     */
    public function getTopProduitsByMaladie(int $days = 30): array
    {
        $result = [];
        foreach (self::MALADIES as $maladie => $config) {
            $top = $this->getTopProduitsForKeywords($config['keywords'], $days, 3);
            if (!empty($top)) {
                $result[] = ['maladie' => $maladie, 'icon' => (string) $config['icon'], 'color' => (string) $config['color'], 'produits' => $top];
            }
        }
        return $result;
    }

    // ============================================================
    //  EXPORT CSV
    // ============================================================

    /**
     * @param array<int, array{label:string, last:int, baseline:int, ratio: float, level:string, variation: float|int}> $meta
     */
    public function exportSignalsCsv(array $meta): string
    {
        $csv = Writer::createFromString();
        $csv->insertOne(['Maladie', 'Ventes 7j', 'Baseline', 'Ratio', 'Niveau', 'Variation %']);
        foreach ($meta as $m) {
            $csv->insertOne([$m['label'], $m['last'], $m['baseline'], (string) $m['ratio'], $m['level'], (string) $m['variation'] . '%']);
        }
        return $csv->toString();
    }

    // ============================================================
    //  HELPERS BDD
    // ============================================================

    /**
     * ✅ UNE SEULE requête SQL pour TOUTES les maladies simultanément.
     * Utilise CASE WHEN par maladie → élimine les N requêtes séparées.
     *
     * @return array<string, float>  clé = nom maladie, valeur = quantité totale
     */
    private function sumAllMaladiesBetween(\DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        $conn   = $this->em->getConnection();
        $params = [
            'debut' => $debut->format('Y-m-d H:i:s'),
            'fin'   => $fin->format('Y-m-d H:i:s'),
        ];

        $selectParts = [];
        foreach (self::MALADIES as $maladie => $config) {
            $orParts = [];
            foreach ($config['keywords'] as $i => $w) {
                $paramKey         = 'k_' . md5($maladie) . '_' . $i;
                $params[$paramKey] = '%' . mb_strtolower($w) . '%';
                $orParts[]         = "(LOWER(p.nom_produit) LIKE :{$paramKey}"
                                   . " OR LOWER(p.description_produit) LIKE :{$paramKey}"
                                   . " OR LOWER(p.categorie_produit) LIKE :{$paramKey})";
            }
            $alias          = 'm_' . md5($maladie);
            $selectParts[]  = "COALESCE(SUM(CASE WHEN (" . implode(' OR ', $orParts) . ")"
                            . " THEN lc.quantite_commandee ELSE 0 END), 0) AS `{$alias}`";
        }

        $sql = "SELECT " . implode(",\n       ", $selectParts) . "
            FROM commande_produit lc
            INNER JOIN commande c ON c.id_commande = lc.commande_id
            INNER JOIN produit p  ON p.id_produit  = lc.produit_id
            WHERE c.paid_at IS NOT NULL
              AND c.date_creation_commande >= :debut
              AND c.date_creation_commande <= :fin";

        /** @var array<string, mixed>|false $row */
        $row = $conn->fetchAssociative($sql, $params);

        $result = [];
        foreach (self::MALADIES as $maladie => $config) {
            $alias           = 'm_' . md5($maladie);
            $result[$maladie] = (float) ($row[$alias] ?? 0);
        }

        return $result;
    }

    /**
     * @param list<string> $keywords
     * @return array<int, array{nom:string, total: string|int|float}>
     */
    private function getTopProduitsForKeywords(array $keywords, int $days, int $limit): array
    {
        $conn   = $this->em->getConnection();
        $since  = (new \DateTimeImmutable())->modify("-{$days} days");
        $or     = [];
        $params = ['since' => $since->format('Y-m-d H:i:s')];

        foreach ($keywords as $i => $w) {
            $or[]            = "(LOWER(p.nom_produit) LIKE :k{$i} OR LOWER(p.categorie_produit) LIKE :k{$i})";
            $params["k{$i}"] = '%' . mb_strtolower($w) . '%';
        }

        $sql = "
            SELECT p.nom_produit AS nom, SUM(lc.quantite_commandee) AS total
            FROM commande_produit lc
            INNER JOIN commande c ON c.id_commande = lc.commande_id
            INNER JOIN produit p  ON p.id_produit  = lc.produit_id
            WHERE c.paid_at IS NOT NULL
              AND c.date_creation_commande >= :since
              AND (" . implode(' OR ', $or) . ")
            GROUP BY p.id_produit, p.nom_produit
            ORDER BY total DESC
            LIMIT {$limit}
        ";

        /** @var array<int, array{nom:string, total: string|int|float}> $rows */
        $rows = $conn->fetchAllAssociative($sql, $params);

        return $rows;
    }
}