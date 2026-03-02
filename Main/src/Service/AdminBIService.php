<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class AdminBIService
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Dashboard BI Admin (KPI + charts + alertes)
     *
     * @return array{
     *   period: array{
     *     from: string,
     *     to: string,
     *     days: int,
     *     cat: string|null,
     *     categories: list<string>
     *   },
     *   kpi: array{
     *     ca: float,
     *     caPrev: float,
     *     variationCA: float|null,
     *     nbCommandes: int,
     *     variationCommandes: float|null,
     *     panierMoyen: float,
     *     variationPanier: float|null,
     *     nbValidees: int,
     *     tauxConversion: float,
     *     nbEnAttente: int,
     *     tauxRupture: float,
     *     quantiteTotale: int
     *   },
     *   charts: array{
     *     ventesJour: list<array{jour: string, ca: numeric-string, nb_commandes: numeric-string, quantite: numeric-string}>,
     *     categories: list<array{categorie: string, quantite: numeric-string, ca: numeric-string}>,
     *     statuts: list<array{label: string, value: numeric-string}>
     *   },
     *   topProduits: list<array{produit: string, categorie: string, quantite: numeric-string, ca: numeric-string, stock_actuel: numeric-string}>,
     *   stocksBas: list<array{nom_produit: string, categorie_produit: string, quantite_produit: numeric-string, status_produit: string}>,
     *   alerts: list<array{type: string, title: string, message: string}>,
     *   tips: list<string>
     * }
     */
    public function buildDashboard(
        int $days = 30,
        ?string $from = null,
        ?string $to = null,
        ?string $cat = null
    ): array {
        $conn = $this->em->getConnection();

        // Normaliser cat
        $cat = (is_string($cat) && trim($cat) !== '') ? trim($cat) : null;

        // =========================
        // Période DATETIME (index-friendly)
        // =========================
        if ($from && $to) {
            $fromDate = (new \DateTimeImmutable($from))->setTime(0, 0, 0);
            $toDate   = (new \DateTimeImmutable($to))->setTime(23, 59, 59);
        } else {
            $toDate   = (new \DateTimeImmutable('today'))->setTime(23, 59, 59);
            $fromDate = $toDate->modify("-{$days} days")->setTime(0, 0, 0);
        }

        $fromStr = $fromDate->format('Y-m-d H:i:s');
        $toStr   = $toDate->format('Y-m-d H:i:s');

        // =========================
        // ✅ LIMIT catégories
        // =========================
        /** @var list<mixed> $rawCategories */
        $rawCategories = $conn->fetchFirstColumn("
            SELECT DISTINCT categorie_produit
            FROM produit
            ORDER BY categorie_produit
            LIMIT 99
        ");

        /** @var list<string> $categories */
        $categories = [];
        foreach ($rawCategories as $c) {
            if (is_string($c) && $c !== '') {
                $categories[] = $c;
            }
        }

        // =========================
        // Période précédente
        // =========================
        $diffDays = max(1, (int) $fromDate->diff($toDate)->days);
        $prevToDate   = $fromDate->modify('-1 day')->setTime(23, 59, 59);
        $prevFromDate = $prevToDate->modify("-{$diffDays} days")->setTime(0, 0, 0);

        $prevFromStr = $prevFromDate->format('Y-m-d H:i:s');
        $prevToStr   = $prevToDate->format('Y-m-d H:i:s');

        // =========================
        // ✅ KPI période courante
        // =========================
        $statutsValides = ['En cours', 'En livraison', 'Expédiée', 'Livrée'];

        $placeholdersValid = implode(', ', array_map(
            static fn(int $i): string => ":sv{$i}",
            array_keys($statutsValides)
        ));

        $paramsKpi = [
            'from' => $fromStr,
            'to' => $toStr,
            'statut_attente' => 'En attente',
        ];
        foreach ($statutsValides as $i => $s) {
            $paramsKpi["sv{$i}"] = $s;
        }

        $kpiSql = "
            SELECT
                COALESCE(SUM(montant_total_cents), 0) / 100.0 AS ca,
                COUNT(*) AS nb_commandes,
                SUM(CASE WHEN statut_commande IN ({$placeholdersValid}) THEN 1 ELSE 0 END) AS nb_validees,
                SUM(CASE WHEN statut_commande = :statut_attente THEN 1 ELSE 0 END) AS nb_en_attente
            FROM commande
            WHERE date_creation_commande BETWEEN :from AND :to
        ";

        /** @var array<int, mixed>|false $kpiNum */
        $kpiNum = $conn->executeQuery($kpiSql, $paramsKpi)->fetchNumeric();

        $caPeriode   = (float) ($kpiNum[0] ?? 0.0);
        $nbPeriode   = (int)   ($kpiNum[1] ?? 0);
        $nbValidees  = (int)   ($kpiNum[2] ?? 0);
        $nbEnAttente = (int)   ($kpiNum[3] ?? 0);

        // Quantité totale (éviter doublons JOIN)
        $quantiteTotale = (int) $conn->fetchOne("
            SELECT COALESCE(SUM(cp.quantite_commandee), 0)
            FROM commande_produit cp
            WHERE cp.commande_id IN (
                SELECT id_commande
                FROM commande
                WHERE date_creation_commande BETWEEN :from AND :to
            )
        ", ['from' => $fromStr, 'to' => $toStr]);

        $panierMoyen    = $nbPeriode > 0 ? $caPeriode / $nbPeriode : 0.0;
        $tauxConversion = $nbPeriode > 0 ? ($nbValidees / $nbPeriode) * 100.0 : 0.0;

        // =========================
        // KPI période précédente
        // =========================
        $kpiPrevSql = "
            SELECT
                COALESCE(SUM(montant_total_cents), 0) / 100.0 AS ca,
                COUNT(*) AS nb_commandes
            FROM commande
            WHERE date_creation_commande BETWEEN :from AND :to
        ";

        /** @var array<int, mixed>|false $kpiPrevNum */
        $kpiPrevNum = $conn->executeQuery($kpiPrevSql, [
            'from' => $prevFromStr,
            'to'   => $prevToStr,
        ])->fetchNumeric();

        $caPrev = (float) ($kpiPrevNum[0] ?? 0.0);
        $nbPrev = (int)   ($kpiPrevNum[1] ?? 0);

        $panierMoyenPrev = $nbPrev > 0 ? $caPrev / $nbPrev : 0.0;

        $variationCA        = $caPrev > 0 ? (($caPeriode - $caPrev) / $caPrev) * 100.0 : null;
        $variationCommandes = $nbPrev > 0 ? (($nbPeriode - $nbPrev) / $nbPrev) * 100.0 : null;
        $variationPanier    = $panierMoyenPrev > 0 ? (($panierMoyen - $panierMoyenPrev) / $panierMoyenPrev) * 100.0 : null;

        // =========================
        // ✅ Ventes/jour
        // =========================
        $chartDays = min($days, 90);
        $chartFromDate = $toDate->modify("-{$chartDays} days")->setTime(0, 0, 0);
        $chartFromStr  = $chartFromDate->format('Y-m-d H:i:s');

        $sqlVentesJour = "
            SELECT
                DATE(c.date_creation_commande) AS jour,
                (COALESCE(SUM(c.montant_total_cents), 0) / 100.0) AS ca,
                COUNT(DISTINCT c.id_commande) AS nb_commandes,
                COALESCE(SUM(cp.quantite_commandee), 0) AS quantite
            FROM commande c
            LEFT JOIN commande_produit cp ON cp.commande_id = c.id_commande
        ";

        $paramsVentes = ['from' => $chartFromStr, 'to' => $toStr];

        if ($cat !== null) {
            $sqlVentesJour .= "
                LEFT JOIN produit p ON p.id_produit = cp.produit_id
                WHERE c.date_creation_commande BETWEEN :from AND :to
                  AND p.categorie_produit = :cat
            ";
            $paramsVentes['cat'] = $cat;
        } else {
            $sqlVentesJour .= "
                WHERE c.date_creation_commande BETWEEN :from AND :to
            ";
        }

        $sqlVentesJour .= "
            GROUP BY DATE(c.date_creation_commande)
            ORDER BY jour ASC
            LIMIT 90
        ";

        /** @var list<array{jour: string, ca: numeric-string, nb_commandes: numeric-string, quantite: numeric-string}> $ventesParJour */
        $ventesParJour = $conn->fetchAllAssociative($sqlVentesJour, $paramsVentes);

        // =========================
        // Répartition catégories
        // =========================
        $sqlCategories = "
            SELECT
                p.categorie_produit AS categorie,
                COALESCE(SUM(cp.quantite_commandee), 0) AS quantite,
                COALESCE(SUM(cp.quantite_commandee * p.prix_produit), 0) AS ca
            FROM commande_produit cp
            INNER JOIN produit p ON p.id_produit = cp.produit_id
            WHERE cp.commande_id IN (
                SELECT id_commande
                FROM commande
                WHERE date_creation_commande BETWEEN :from AND :to
            )
        ";
        $paramsCategories = ['from' => $fromStr, 'to' => $toStr];

        if ($cat !== null) {
            $sqlCategories .= " AND p.categorie_produit = :cat ";
            $paramsCategories['cat'] = $cat;
        }

        $sqlCategories .= "
            GROUP BY p.categorie_produit
            ORDER BY ca DESC
            LIMIT 50
        ";

        /** @var list<array{categorie: string, quantite: numeric-string, ca: numeric-string}> $repartitionCategories */
        $repartitionCategories = $conn->fetchAllAssociative($sqlCategories, $paramsCategories);

        // =========================
        // Top produits
        // =========================
        $sqlTop = "
            SELECT
                p.nom_produit AS produit,
                p.categorie_produit AS categorie,
                COALESCE(SUM(cp.quantite_commandee), 0) AS quantite,
                COALESCE(SUM(cp.quantite_commandee * p.prix_produit), 0) AS ca,
                p.quantite_produit AS stock_actuel
            FROM commande_produit cp
            INNER JOIN produit p ON p.id_produit = cp.produit_id
            WHERE cp.commande_id IN (
                SELECT id_commande
                FROM commande
                WHERE date_creation_commande BETWEEN :from AND :to
            )
        ";
        $paramsTop = ['from' => $fromStr, 'to' => $toStr];

        if ($cat !== null) {
            $sqlTop .= " AND p.categorie_produit = :cat ";
            $paramsTop['cat'] = $cat;
        }

        $sqlTop .= "
            GROUP BY p.id_produit, p.nom_produit, p.categorie_produit, p.quantite_produit
            ORDER BY quantite DESC
            LIMIT 10
        ";

        /** @var list<array{produit: string, categorie: string, quantite: numeric-string, ca: numeric-string, stock_actuel: numeric-string}> $topProduits */
        $topProduits = $conn->fetchAllAssociative($sqlTop, $paramsTop);

        // =========================
        // Statuts
        // =========================
        /** @var list<array{label: string, value: numeric-string}> $statuts */
        $statuts = $conn->fetchAllAssociative("
            SELECT statut_commande AS label, COUNT(*) AS value
            FROM commande
            WHERE date_creation_commande BETWEEN :from AND :to
            GROUP BY statut_commande
            ORDER BY value DESC
            LIMIT 20
        ", ['from' => $fromStr, 'to' => $toStr]);

        // =========================
        // Stock critique
        // =========================
        $seuilStock = 10;

        /** @var list<array{nom_produit: string, categorie_produit: string, quantite_produit: numeric-string, status_produit: string}> $stocksBas */
        $stocksBas = $conn->fetchAllAssociative("
            SELECT nom_produit, categorie_produit, quantite_produit, status_produit
            FROM produit
            WHERE quantite_produit <= :seuil
            ORDER BY quantite_produit ASC
            LIMIT 10
        ", ['seuil' => $seuilStock]);

        /** @var array<string, mixed>|false $stockRow */
        $stockRow = $conn->fetchAssociative("
            SELECT
                COUNT(*) AS nb_produits,
                SUM(CASE WHEN status_produit = :statut_rupture THEN 1 ELSE 0 END) AS nb_rupture
            FROM produit
        ", ['statut_rupture' => 'Rupture']);

        $nbProduits  = (int) ($stockRow['nb_produits'] ?? 0);
        $nbRupture   = (int) ($stockRow['nb_rupture']  ?? 0);
        $tauxRupture = $nbProduits > 0 ? ($nbRupture / $nbProduits) * 100.0 : 0.0;

        // =========================
        // Alertes
        // =========================
        /** @var list<array{type: string, title: string, message: string}> $alerts */
        $alerts = [];

        if ($variationCA !== null && $variationCA <= -15) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Baisse du CA',
                'message' => sprintf('Diminution de %.1f%% par rapport à la période précédente', abs($variationCA)),
            ];
        } elseif ($variationCA !== null && $variationCA >= 15) {
            $alerts[] = [
                'type' => 'success',
                'title' => 'Croissance du CA',
                'message' => sprintf('Augmentation de %.1f%% par rapport à la période précédente', $variationCA),
            ];
        }

        if ($tauxRupture >= 10) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Taux de rupture élevé',
                'message' => sprintf('%.1f%% des produits sont en rupture', $tauxRupture),
            ];
        }

        if (count($stocksBas) >= 5) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Stock critique',
                'message' => sprintf('%d produits ont un stock faible', count($stocksBas)),
            ];
        }

        if ($nbEnAttente >= 5) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Commandes en attente',
                'message' => sprintf('%d commandes en attente de validation', $nbEnAttente),
            ];
        }

        // =========================
        // Conseils
        // =========================
        /** @var list<string> $tips */
        $tips = [];

        if ($variationCA !== null && $variationCA <= -15) {
            $tips[] = "Analyser les produits les plus performants et lancer des promotions ciblées";
        }
        if ($tauxConversion < 70) {
            $tips[] = "Simplifier/accélérer le processus de validation pour augmenter la conversion";
        }
        if ($tauxRupture >= 10) {
            $tips[] = "Prioriser le réapprovisionnement des produits en rupture";
        }
        if ($tips === []) {
            $tips[] = "Les indicateurs sont stables. Continuer le suivi régulier";
        }

        return [
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
                'days' => $days,
                'cat' => $cat,
                'categories' => $categories,
            ],
            'kpi' => [
                'ca' => $caPeriode,
                'caPrev' => $caPrev,
                'variationCA' => $variationCA,
                'nbCommandes' => $nbPeriode,
                'variationCommandes' => $variationCommandes,
                'panierMoyen' => $panierMoyen,
                'variationPanier' => $variationPanier,
                'nbValidees' => $nbValidees,
                'tauxConversion' => $tauxConversion,
                'nbEnAttente' => $nbEnAttente,
                'tauxRupture' => $tauxRupture,
                'quantiteTotale' => $quantiteTotale,
            ],
            'charts' => [
                'ventesJour' => $ventesParJour,
                'categories' => $repartitionCategories,
                'statuts' => $statuts,
            ],
            'topProduits' => $topProduits,
            'stocksBas' => $stocksBas,
            'alerts' => $alerts,
            'tips' => $tips,
        ];
    }
}