<?php

namespace App\Service;

use App\Entity\Produit;
use Doctrine\ORM\EntityManagerInterface;

class DrugInteractionService
{
    public function __construct(
        private OpenFdaService $openFda,
        private EntityManagerInterface $em
    ) {}

    /**
     * @param array<int, array<string, mixed>> $panier
     * @return array{
     *   checked: bool,
     *   api_available: bool,
     *   severity: 'safe'|'info'|'caution'|'warning'|'danger',
     *   message: string,
     *   interactions: array<int, array{
     *     drug1: string,
     *     drug2: string,
     *     severity: 'low'|'medium'|'high',
     *     description: string,
     *     recommendation: string
     *   }>,
     *   not_found: string[]
     * }
     */
    public function checkCartInteractions(array $panier): array
    {
        $rawNames = [];

        foreach ($panier as $id => $item) {
            $produit = $this->em->getRepository(Produit::class)->find((int) $id);
            if (!$produit) {
                continue;
            }

            // ✅ FIX PHPStan: pas de comparaison avec null
            // On force string (si null -> '')
            $name = trim((string) $produit->getNomProduit());
            if ($name === '') {
                continue;
            }

            $rawNames[] = $name;
        }

        $rawNames = array_values(array_unique($rawNames));

        if (count($rawNames) < 2) {
            return [
                'checked' => true,
                'api_available' => true,
                'severity' => 'safe',
                'message' => 'Ajoutez au moins 2 médicaments pour vérifier les interactions.',
                'interactions' => [],
                'not_found' => [],
            ];
        }

        $drugNames = array_map([$this, 'normalizeDrugName'], $rawNames);
        $drugNames = array_values(array_unique(array_filter($drugNames, static fn(string $s): bool => $s !== '')));

        if (count($drugNames) < 2) {
            return [
                'checked' => true,
                'api_available' => true,
                'severity' => 'safe',
                'message' => 'Ajoutez au moins 2 médicaments pour vérifier les interactions.',
                'interactions' => [],
                'not_found' => [],
            ];
        }

        $localDangerPairs = [
            ['aspirin', 'warfarin'],
            ['acetylsalicylic acid', 'warfarin'],
            ['aspirin', 'coumadin'],
        ];

        $localInteractions = $this->checkLocalPairs($drugNames, $localDangerPairs);
        if ($localInteractions !== []) {
            return [
                'checked' => true,
                'api_available' => true,
                'severity' => 'danger',
                'message' => '🚨 ALERTE DANGER : Interaction dangereuse détectée (base locale).',
                'interactions' => $localInteractions,
                'not_found' => [],
            ];
        }

        /** @var array<int, array{
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
         * }> $infos
         */
        $infos = [];

        /** @var string[] $notFound */
        $notFound = [];

        foreach ($drugNames as $name) {
            $info = $this->openFda->searchDrug($name);

            if ($info['found'] === false) {
                $notFound[] = $name;
                continue;
            }

            $infos[] = $info;
        }

        if ($infos === []) {
            return [
                'checked' => true,
                'api_available' => false,
                'severity' => 'info',
                'message' => 'Système de vérification indisponible ou médicaments introuvables sur OpenFDA.',
                'interactions' => [],
                'not_found' => $notFound,
            ];
        }

        /** @var array<int, array{
         *   drug1: string,
         *   drug2: string,
         *   severity: 'low'|'medium'|'high',
         *   description: string,
         *   recommendation: string
         * }> $interactions
         */
        $interactions = [];

        $countInfos = count($infos);

        for ($i = 0; $i < $countInfos; $i++) {
            for ($j = $i + 1; $j < $countInfos; $j++) {
                $d1 = $infos[$i];
                $d2 = $infos[$j];

                $hit1 = $this->findPairInteraction($d1, $d2);
                $hit2 = $this->findPairInteraction($d2, $d1);

                $best = $this->pickMostSevere($hit1, $hit2);

                if ($best !== null) {
                    $interactions[] = $best;
                }
            }
        }

        $global = $this->globalSeverity($interactions);

        return [
            'checked' => true,
            'api_available' => true,
            'severity' => $global,
            'message' => $this->message($global, count($interactions)),
            'interactions' => $interactions,
            'not_found' => $notFound,
        ];
    }

    private function normalizeDrugName(string $name): string
    {
        $name = strtolower(trim($name));

        $name = preg_replace('/\b\d+(\.\d+)?\s*(mg|mcg|g|ml|%)\b/i', '', $name) ?? '';
        $name = preg_replace('/\([^)]*\)/', '', $name) ?? '';

        $remove = ['tablet', 'tablets', 'capsule', 'capsules', 'syrup', 'solution', 'injection', 'oral', 'gel'];
        $name = str_replace($remove, '', $name);

        $name = preg_replace('/\s+/', ' ', $name) ?? '';
        $name = trim($name);

        return $name;
    }

    /**
     * @param string[] $names
     * @param array<int, array{0:string,1:string}> $pairs
     * @return array<int, array{
     *   drug1: string,
     *   drug2: string,
     *   severity: 'low'|'medium'|'high',
     *   description: string,
     *   recommendation: string
     * }>
     */
    private function checkLocalPairs(array $names, array $pairs): array
    {
        $set = array_map('strtolower', $names);

        foreach ($pairs as $pair) {
            [$a, $b] = $pair;

            $a = strtolower($a);
            $b = strtolower($b);

            if (in_array($a, $set, true) && in_array($b, $set, true)) {
                return [[
                    'drug1' => $a,
                    'drug2' => $b,
                    'severity' => 'high',
                    'description' => 'Interaction connue : risque hémorragique accru.',
                    'recommendation' => $this->recommendation($a, $b, 'high'),
                ]];
            }
        }

        return [];
    }

    /**
     * @param array{
     *   found: bool,
     *   original_name: string,
     *   generic_name?: string,
     *   substance_name?: string[],
     *   drug_interactions?: string[]
     * } $source
     * @param array{
     *   found: bool,
     *   original_name: string,
     *   generic_name?: string,
     *   substance_name?: string[]
     * } $target
     * @return array{
     *   drug1: string,
     *   drug2: string,
     *   severity: 'low'|'medium'|'high',
     *   description: string,
     *   recommendation: string
     * }|null
     */
    private function findPairInteraction(array $source, array $target): ?array
    {
        $texts = $source['drug_interactions'] ?? [];

        $targetGeneric  = strtolower((string) ($target['generic_name'] ?? ''));
        $targetSubs     = array_map('strtolower', (array) ($target['substance_name'] ?? []));
        $targetOriginal = $target['original_name'];

        foreach ($texts as $t) {
            $t = $this->cleanText((string) $t);
            $low = strtolower($t);

            $hit = false;

            if ($targetGeneric !== '' && str_contains($low, $targetGeneric)) {
                $hit = true;
            }

            if (!$hit) {
                foreach ($targetSubs as $s) {
                    if ($s !== '' && str_contains($low, $s)) {
                        $hit = true;
                        break;
                    }
                }
            }

            if (!$hit && $targetOriginal !== '') {
                $orig = strtolower($this->normalizeDrugName($targetOriginal));
                if ($orig !== '' && str_contains($low, $orig)) {
                    $hit = true;
                }
            }

            if ($hit) {
                $severity = $this->extractSeverity($low);

                $drug1 = $source['original_name'] !== '' ? $source['original_name'] : 'Médicament 1';
                $drug2 = $target['original_name'] !== '' ? $target['original_name'] : 'Médicament 2';

                return [
                    'drug1' => $drug1,
                    'drug2' => $drug2,
                    'severity' => $severity,
                    'description' => mb_substr($t, 0, 450),
                    'recommendation' => $this->recommendation($drug1, $drug2, $severity),
                ];
            }
        }

        return null;
    }

    /**
     * @param array{
     *   drug1: string,
     *   drug2: string,
     *   severity: 'low'|'medium'|'high',
     *   description: string,
     *   recommendation: string
     * }|null $a
     * @param array{
     *   drug1: string,
     *   drug2: string,
     *   severity: 'low'|'medium'|'high',
     *   description: string,
     *   recommendation: string
     * }|null $b
     * @return array{
     *   drug1: string,
     *   drug2: string,
     *   severity: 'low'|'medium'|'high',
     *   description: string,
     *   recommendation: string
     * }|null
     */
    private function pickMostSevere(?array $a, ?array $b): ?array
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];

        $ra = $rank[$a['severity']];
        $rb = $rank[$b['severity']];

        return $ra >= $rb ? $a : $b;
    }

    private function cleanText(string $t): string
    {
        $clean = preg_replace('/\s+/', ' ', $t);
        return trim($clean ?? '');
    }

    /**
     * @return 'low'|'medium'|'high'
     */
    private function extractSeverity(string $tLower): string
    {
        $high = ['contraindicated','contraindication','fatal','death','life-threatening','do not','avoid','severe','serious','bleeding','hemorrhage'];
        foreach ($high as $k) {
            if (str_contains($tLower, $k)) {
                return 'high';
            }
        }

        $medium = ['caution','monitor','adjust','risk','may increase','may decrease','potential'];
        foreach ($medium as $k) {
            if (str_contains($tLower, $k)) {
                return 'medium';
            }
        }

        return 'low';
    }

    /**
     * @param array<int, array{severity:'low'|'medium'|'high'}> $interactions
     * @return 'safe'|'caution'|'warning'|'danger'
     */
    private function globalSeverity(array $interactions): string
    {
        if ($interactions === []) {
            return 'safe';
        }

        $sev = array_column($interactions, 'severity');

        if (in_array('high', $sev, true)) {
            return 'danger';
        }
        if (in_array('medium', $sev, true)) {
            return 'warning';
        }

        return 'caution';
    }

    private function recommendation(string $d1, string $d2, string $sev): string
    {
        if ($sev === 'high') {
            return "🚨 DANGER : Ne prenez PAS $d1 et $d2 ensemble. Consultez un médecin/pharmacien.";
        }
        if ($sev === 'medium') {
            return "⚠️ ATTENTION : interaction possible entre $d1 et $d2. Surveillez et demandez conseil.";
        }
        return "ℹ️ Interaction mineure possible entre $d1 et $d2. Informez votre pharmacien.";
    }

    /**
     * @param 'safe'|'info'|'caution'|'warning'|'danger' $global
     */
    private function message(string $global, int $count): string
    {
        return match ($global) {
            'danger'  => "🚨 ALERTE DANGER : $count interaction(s) dangereuse(s) détectée(s) !",
            'warning' => "⚠️ ATTENTION : $count interaction(s) potentielle(s) détectée(s).",
            'caution' => "ℹ️ Info : $count interaction(s) mineure(s) détectée(s).",
            'info'    => "ℹ️ Vérification indisponible ou données insuffisantes.",
            default   => "✅ Aucune interaction majeure détectée.",
        };
    }
}