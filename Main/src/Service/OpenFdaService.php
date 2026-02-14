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

            // OpenFDA retourne souvent 404 quand rien trouvé -> on fait fuzzy
            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($status !== 200 || isset($data['error'])) {
                return $this->fuzzySearch($drugName);
            }

            if (!isset($data['results'][0])) {
                return $this->fuzzySearch($drugName);
            }

            return $this->mapResult($drugName, $data['results'][0]);

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

    private function fuzzySearch(string $drugName): array
    {
        $term = $this->escapeForOpenFda($drugName);

        // Recherche floue
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

            if ($status !== 200 || isset($data['error']) || !isset($data['results'][0])) {
                return [
                    'found' => false,
                    'original_name' => $drugName,
                    'error' => 'Médicament introuvable sur OpenFDA',
                ];
            }

            return $this->mapResult($drugName, $data['results'][0]);

        } catch (\Throwable $e) {
            return [
                'found' => false,
                'original_name' => $drugName,
                'error' => 'Erreur OpenFDA: ' . $e->getMessage(),
            ];
        }
    }

    private function mapResult(string $originalName, array $result): array
    {
        $openfda = $result['openfda'] ?? [];

        return [
            'found' => true,
            'original_name' => $originalName,
            'generic_name' => $openfda['generic_name'][0] ?? $originalName,
            'brand_names' => $openfda['brand_name'] ?? [],
            'substance_name' => $openfda['substance_name'] ?? [],
            'manufacturer' => $openfda['manufacturer_name'] ?? [],
            'warnings' => $result['warnings'] ?? [],
            'drug_interactions' => $result['drug_interactions'] ?? [],
            'contraindications' => $result['contraindications'] ?? [],
        ];
    }

    /**
     * OpenFDA query: éviter de casser le search (guillemets, backslashes)
     */
    private function escapeForOpenFda(string $value): string
    {
        // on supprime les guillemets dangereux, et on normalise
        $value = trim($value);
        $value = str_replace(['\\', '"'], [' ', ' '], $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return $value ?? '';
    }
}
