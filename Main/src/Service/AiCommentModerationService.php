<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiCommentModerationService
{
    public function __construct(
        private HttpClientInterface $http,
        private string $hfToken,
        private string $hfModel,
        private float $threshold = 0.70
    ) {}

    /**
 * @return array{
 *   allow: bool,
 *   score: float,
 *   label: string,
 *   raw: array<string, mixed>|list<mixed>
 * }
 */
public function moderate(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [
                'allow' => false,
                'score' => 1.0,
                'label' => 'EMPTY',
                'raw' => ['error' => 'Empty text'],
            ];
        }

        try {
            // ✅ Nouveau endpoint Hugging Face
            // Router endpoint : https://router.huggingface.co/hf-inference/models/{model}
            $url = 'https://router.huggingface.co/hf-inference/models/' . rawurlencode($this->hfModel);

            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->hfToken,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'inputs' => $text,
                ],
                'timeout' => 20,
            ]);

            $status = $response->getStatusCode();
            $raw = $response->toArray(false);

            // ✅ HF peut renvoyer {error: "..."} même en 200
           if ($status >= 400 || isset($raw['error'])) {
                return [
                    'allow' => true,         // (tu peux mettre false si tu veux bloquer en cas d'erreur)
                    'score' => 0.0,
                    'label' => 'HF-ERROR',
                    'raw'   => $raw,
                ];
            }

            // Normalement : array de résultats
            // Exemple: [[{"label":"toxic","score":0.9},{"label":"non-toxic","score":0.1}]]
            $best = $this->extractBestLabelScore($raw);

           $label = isset($best['label']) ? $best['label'] : 'unknown';
$score = isset($best['score']) ? (float) $best['score'] : 0.0;

            // ✅ décision
            $allow = true;

            // Si label ressemble à toxic/hate/insult/etc on bloque selon threshold
            $isBad = preg_match('/toxic|hate|insult|abusive|offensive/i', (string)$label) === 1;
            if ($isBad && $score >= $this->threshold) {
                $allow = false;
            }

            return [
                'allow' => $allow,
                'score' => $score,
                'label' => (string)$label,
                'raw'   => $raw,
            ];
        } catch (\Throwable $e) {
            return [
                'allow' => true,   // pour ne pas casser le site (tu peux mettre false pour stricte modération)
                'score' => 0.0,
                'label' => 'HF-EXCEPTION',
                'raw'   => ['error' => $e->getMessage()],
            ];
        }
    }

/**
 * @param array<string, mixed>|list<mixed> $raw
 * @return array{label?: string, score?: float}
 */
private function extractBestLabelScore(array $raw): array
    {
        // Cas attendu: [[{label,score}, {label,score}]]
        if (isset($raw[0]) && is_array($raw[0])) {
            // parfois $raw[0] est la liste
            $list = $raw[0];
            if (isset($list[0]) && is_array($list[0]) && isset($list[0]['label'])) {
                // choisir le plus grand score
                usort($list, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
                return $list[0];
            }
        }

        // Autres cas (fallback)
        return [];
    }
}