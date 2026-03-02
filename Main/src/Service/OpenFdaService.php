<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenFdaService
{
    private const BASE_URL = 'https://api.fda.gov/drug/label.json';

    public function __construct(
        private HttpClientInterface $client,
        private ?string $apiKey = null
    ) {}

    /**
     * @return array{
     *   found: bool,
     *   original_name: string,
     *   error?: string,
     *   generic_name?: string,
     *   brand_names?: string[],
     *   substance_name?: string[],
     *   manufacturer?: string[],
     *   warnings?: string[],
     *   drug_interactions?: string[],
     *   contraindications?: string[]
     * }
     */
    public function searchDrug(string $drugName): array
    {
        $drugName = trim($drugName);

        if ($drugName === '') {
            return [
                'found' => false,
                'original_name' => '',
                'error' => 'Nom vide',
            ];
        }

        $term = $this->escapeForOpenFda($drugName);

        // Recherche stricte (exact match)
        $query = sprintf(
            '(openfda.generic_name:"%1$s" OR openfda.brand_name:"%1$s" OR openfda.substance_name:"%1$s")',
            $term
        );

        /** @var array<string, string|int> $params */
        $params = [
            'search' => $query,
            'limit'  => 1,
        ];

        if (!empty($this->apiKey)) {
            $params['api_key'] = $this->apiKey;
        }

        try {
            $response = $this->client->request('GET', self::BASE_URL, [
                'query'   => $params,
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($status !== 200 || isset($data['error'])) {
                return $this->fuzzySearch($drugName);
            }

            if (!isset($data['results'][0]) || !is_array($data['results'][0])) {
                return $this->fuzzySearch($drugName);
            }

            /** @var array<string, mixed> $firstResult */
            $firstResult = $data['results'][0];

            return $this->mapResult($drugName, $firstResult);

        } catch (TransportExceptionInterface $e) {
            return [
                'found' => false,
                'original_name' => $drugName,
                'error' => 'Erreur réseau OpenFDA: ' . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'found' => false,
                'original_name' => $drugName,
                'error' => 'Erreur OpenFDA: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{
     *   found: bool,
     *   original_name: string,
     *   error?: string,
     *   generic_name?: string,
     *   brand_names?: string[],
     *   substance_name?: string[],
     *   manufacturer?: string[],
     *   warnings?: string[],
     *   drug_interactions?: string[],
     *   contraindications?: string[]
     * }
     */
    private function fuzzySearch(string $drugName): array
    {
        $term = $this->escapeForOpenFda($drugName);

        /** @var array<string, string|int> $params */
        $params = [
            'search' => sprintf('(openfda.generic_name:*%1$s* OR openfda.brand_name:*%1$s*)', $term),
            'limit'  => 1,
        ];

        if (!empty($this->apiKey)) {
            $params['api_key'] = $this->apiKey;
        }

        try {
            $response = $this->client->request('GET', self::BASE_URL, [
                'query'   => $params,
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($status !== 200 || isset($data['error']) || !isset($data['results'][0]) || !is_array($data['results'][0])) {
                return [
                    'found' => false,
                    'original_name' => $drugName,
                    'error' => 'Médicament introuvable sur OpenFDA',
                ];
            }

            /** @var array<string, mixed> $firstResult */
            $firstResult = $data['results'][0];

            return $this->mapResult($drugName, $firstResult);

        } catch (\Throwable $e) {
            return [
                'found' => false,
                'original_name' => $drugName,
                'error' => 'Erreur OpenFDA: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array{
     *   found: true,
     *   original_name: string,
     *   generic_name: string,
     *   brand_names: string[],
     *   substance_name: string[],
     *   manufacturer: string[],
     *   warnings: string[],
     *   drug_interactions: string[],
     *   contraindications: string[]
     * }
     */
    private function mapResult(string $originalName, array $result): array
    {
        $openfda = $result['openfda'] ?? [];

        /** @var array<string, mixed> $openfdaArr */
        $openfdaArr = is_array($openfda) ? $openfda : [];

        /** @var string[] $brandNames */
        $brandNames = isset($openfdaArr['brand_name']) && is_array($openfdaArr['brand_name']) ? array_map('strval', $openfdaArr['brand_name']) : [];

        /** @var string[] $substances */
        $substances = isset($openfdaArr['substance_name']) && is_array($openfdaArr['substance_name']) ? array_map('strval', $openfdaArr['substance_name']) : [];

        /** @var string[] $manufacturers */
        $manufacturers = isset($openfdaArr['manufacturer_name']) && is_array($openfdaArr['manufacturer_name']) ? array_map('strval', $openfdaArr['manufacturer_name']) : [];

        $generic = $originalName;
        if (isset($openfdaArr['generic_name']) && is_array($openfdaArr['generic_name']) && isset($openfdaArr['generic_name'][0])) {
            $generic = (string) $openfdaArr['generic_name'][0];
        }

        /** @var string[] $warnings */
        $warnings = isset($result['warnings']) && is_array($result['warnings']) ? array_map('strval', $result['warnings']) : [];

        /** @var string[] $interactions */
        $interactions = isset($result['drug_interactions']) && is_array($result['drug_interactions']) ? array_map('strval', $result['drug_interactions']) : [];

        /** @var string[] $contra */
        $contra = isset($result['contraindications']) && is_array($result['contraindications']) ? array_map('strval', $result['contraindications']) : [];

        return [
            'found' => true,
            'original_name' => $originalName,
            'generic_name' => $generic,
            'brand_names' => $brandNames,
            'substance_name' => $substances,
            'manufacturer' => $manufacturers,
            'warnings' => $warnings,
            'drug_interactions' => $interactions,
            'contraindications' => $contra,
        ];
    }

    /**
     * OpenFDA query: éviter de casser le search (guillemets, backslashes)
     */
    private function escapeForOpenFda(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['\\', '"'], [' ', ' '], $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return $value ?? '';
    }
}