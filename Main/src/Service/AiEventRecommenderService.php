<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiEventRecommenderService
{
    public function __construct(
        private HttpClientInterface $http,
        private string $groqKey,
        private string $groqModel = 'llama-3.1-70b-versatile'
    ) {}

    /**
     * @param array $current   infos event courant
     * @param array $user      infos user/session
     * @param array $cands     liste candidats
     * @param int   $limit     top N
     */
    public function recommend(array $current, array $user, array $cands, int $limit = 6): array
    {
        if (count($cands) === 0) return [];

        // Prompt JSON STRICT (important)
        $system = "Tu es un moteur de recommandation d'événements. Tu DOIS répondre en JSON strict uniquement.";
        $prompt = [
            'current_event' => $current,
            'user_profile'  => $user,
            'candidates'    => $cands,
            'task' => "Classer les candidats du plus pertinent au moins pertinent pour cet utilisateur. "
                    . "Retourne EXACTEMENT un JSON strict sous forme de tableau: "
                    . "[{\"id\":123,\"score\":0-10,\"reasons\":[\"...\"]}, ...]. "
                    . "Score 0-10, reasons max 3, reasons en FR. "
                    . "Ne renvoie aucun texte hors JSON. Limit {$limit}."
        ];

        $res = $this->http->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->groqKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->groqModel,
                'temperature' => 0.2,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($prompt, JSON_UNESCAPED_UNICODE)],
                ],
            ],
            'timeout' => 20,
        ]);

        $data = $res->toArray(false);
        $content = $data['choices'][0]['message']['content'] ?? '[]';

        // Sécurité : extraire JSON même si l'IA ajoute du texte
        $json = $this->extractJsonArray($content);
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) return [];

        // normaliser
        $out = [];
        foreach ($decoded as $row) {
            if (!isset($row['id'])) continue;
            $out[] = [
                'id' => (int)$row['id'],
                'score' => isset($row['score']) ? (float)$row['score'] : 0,
                'reasons' => isset($row['reasons']) && is_array($row['reasons']) ? array_slice($row['reasons'], 0, 3) : [],
            ];
            if (count($out) >= $limit) break;
        }

        return $out;
    }

    private function extractJsonArray(string $text): string
    {
        // cherche le premier '[' et le dernier ']'
        $start = strpos($text, '[');
        $end   = strrpos($text, ']');
        if ($start === false || $end === false || $end <= $start) return '[]';
        return substr($text, $start, $end - $start + 1);
    }
}