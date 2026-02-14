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

    public function checkCartInteractions(array $panier): array
    {
        // 0) Récupérer noms produits
        $rawNames = [];
        foreach ($panier as $id => $item) {
            $produit = $this->em->getRepository(Produit::class)->find($id);
            if (!$produit) continue;

            $rawNames[] = trim((string) $produit->getNomProduit());
        }

        $rawNames = array_values(array_unique(array_filter($rawNames)));

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

        // ✅ 1) NORMALISATION (hyper important)
        $drugNames = array_map([$this, 'normalizeDrugName'], $rawNames);
        $drugNames = array_values(array_unique(array_filter($drugNames)));

        // ✅ 2) Fallback local (sécurité) — paires connues
        // (Tu peux en ajouter d'autres)
        $localDangerPairs = [
            ['aspirin', 'warfarin'],
            ['acetylsalicylic acid', 'warfarin'],
            ['aspirin', 'coumadin'],
        ];

        $localInteractions = $this->checkLocalPairs($drugNames, $localDangerPairs);
        if (!empty($localInteractions)) {
            // danger direct
            return [
                'checked' => true,
                'api_available' => true,
                'severity' => 'danger',
                'message' => '🚨 ALERTE DANGER : Interaction dangereuse détectée (base locale).',
                'interactions' => $localInteractions,
                'not_found' => [],
            ];
        }

        // ✅ 3) Recherche OpenFDA pour chaque médicament
        $infos = [];
        $notFound = [];

        foreach ($drugNames as $name) {
            $info = $this->openFda->searchDrug($name);
            if (!($info['found'] ?? false)) {
                $notFound[] = $name;
                continue;
            }
            $infos[] = $info;
        }

        if (empty($infos)) {
            return [
                'checked' => true,
                'api_available' => false,
                'severity' => 'info',
                'message' => 'Système de vérification indisponible ou médicaments introuvables sur OpenFDA.',
                'interactions' => [],
                'not_found' => $notFound,
            ];
        }

        // ✅ 4) Comparer chaque paire dans LES DEUX SENS
        $interactions = [];
        $countInfos = count($infos);

        for ($i = 0; $i < $countInfos; $i++) {
            for ($j = $i + 1; $j < $countInfos; $j++) {
                $d1 = $infos[$i];
                $d2 = $infos[$j];

                // check A -> B
                $hit1 = $this->findPairInteraction($d1, $d2);
                // check B -> A (important)
                $hit2 = $this->findPairInteraction($d2, $d1);

                $best = $this->pickMostSevere($hit1, $hit2);

                if ($best) {
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

    // -----------------------
    // Helpers
    // -----------------------

    private function normalizeDrugName(string $name): string
    {
        $name = strtolower(trim($name));

        // enlever dosage et unités: 500mg, 5 mg, 2%, 10ml...
        $name = preg_replace('/\b\d+(\.\d+)?\s*(mg|mcg|g|ml|%)\b/i', '', $name);

        // enlever contenu entre parenthèses
        $name = preg_replace('/\([^)]*\)/', '', $name);

        // enlever mots "forme"
        $remove = ['tablet', 'tablets', 'capsule', 'capsules', 'syrup', 'solution', 'injection', 'oral', 'gel'];
        $name = str_replace($remove, '', $name);

        // nettoyer espaces
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    private function checkLocalPairs(array $names, array $pairs): array
    {
        $set = array_map('strtolower', $names);

        foreach ($pairs as [$a, $b]) {
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

    private function findPairInteraction(array $source, array $target): ?array
    {
        $texts = $source['drug_interactions'] ?? [];
        if (!is_array($texts)) $texts = [$texts];

        $targetGeneric = strtolower((string) ($target['generic_name'] ?? ''));
        $targetSubs = array_map('strtolower', (array) ($target['substance_name'] ?? []));
        $targetOriginal = (string) ($target['original_name'] ?? '');

        foreach ($texts as $t) {
            $t = $this->cleanText((string) $t);
            $low = strtolower($t);

            $hit = false;

            if ($targetGeneric && str_contains($low, $targetGeneric)) $hit = true;

            if (!$hit) {
                foreach ($targetSubs as $s) {
                    if ($s && str_contains($low, $s)) {
                        $hit = true;
                        break;
                    }
                }
            }

            // petit fallback : le nom original
            if (!$hit && $targetOriginal) {
                $orig = strtolower($this->normalizeDrugName($targetOriginal));
                if ($orig && str_contains($low, $orig)) $hit = true;
            }

            if ($hit) {
                $severity = $this->extractSeverity($low);

                return [
                    'drug1' => $source['original_name'] ?? 'Médicament 1',
                    'drug2' => $target['original_name'] ?? 'Médicament 2',
                    'severity' => $severity,
                    'description' => mb_substr($t, 0, 450),
                    'recommendation' => $this->recommendation(
                        (string) ($source['original_name'] ?? ''),
                        (string) ($target['original_name'] ?? ''),
                        $severity
                    ),
                ];
            }
        }

        return null;
    }

    private function pickMostSevere(?array $a, ?array $b): ?array
    {
        if (!$a && !$b) return null;
        if ($a && !$b) return $a;
        if (!$a && $b) return $b;

        // high > medium > low
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        return ($rank[$a['severity']] ?? 0) >= ($rank[$b['severity']] ?? 0) ? $a : $b;
    }

    private function cleanText(string $t): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $t));
    }

    private function extractSeverity(string $tLower): string
    {
        $high = ['contraindicated','contraindication','fatal','death','life-threatening','do not','avoid','severe','serious','bleeding','hemorrhage'];
        foreach ($high as $k) {
            if (str_contains($tLower, $k)) return 'high';
        }

        $medium = ['caution','monitor','adjust','risk','may increase','may decrease','potential'];
        foreach ($medium as $k) {
            if (str_contains($tLower, $k)) return 'medium';
        }

        return 'low';
    }

    private function globalSeverity(array $interactions): string
    {
        if (empty($interactions)) return 'safe';

        $sev = array_column($interactions, 'severity');
        if (in_array('high', $sev, true)) return 'danger';
        if (in_array('medium', $sev, true)) return 'warning';
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

    private function message(string $global, int $count): string
    {
        return match ($global) {
            'danger'  => "🚨 ALERTE DANGER : $count interaction(s) dangereuse(s) détectée(s) !",
            'warning' => "⚠️ ATTENTION : $count interaction(s) potentielle(s) détectée(s).",
            'caution' => "ℹ️ Info : $count interaction(s) mineure(s) détectée(s).",
            default   => "✅ Aucune interaction majeure détectée.",
        };
    }
}
