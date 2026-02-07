<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class AdminBIService
{
    public function __construct(private EntityManagerInterface $em) {}

    public function buildDashboard(int $days = 30): array
    {
        $conn = $this->em->getConnection();

        // =========================
        // ✅ KPI Global
        // =========================
        $caTotal = (float) $conn->fetchOne("SELECT COALESCE(SUM(montant_total),0) FROM commande");
        $nbCommandes = (int) $conn->fetchOne("SELECT COUNT(*) FROM commande");
        $panierMoyen = $nbCommandes > 0 ? $caTotal / $nbCommandes : 0;

        // =========================
        // ✅ KPI Période (N derniers jours)
        // =========================
        $caPeriode = (float) $conn->fetchOne("
            SELECT COALESCE(SUM(montant_total),0)
            FROM commande
            WHERE date_creation_commande >= (CURRENT_DATE - INTERVAL :days DAY)
        ", ['days' => $days]);

        $nbPeriode = (int) $conn->fetchOne("
            SELECT COUNT(*)
            FROM commande
            WHERE date_creation_commande >= (CURRENT_DATE - INTERVAL :days DAY)
        ", ['days' => $days]);

        // =========================
        // ✅ Comparaison CA période précédente (ex: 30j vs 30j avant)
        // =========================
        $caPrev = (float) $conn->fetchOne("
            SELECT COALESCE(SUM(montant_total),0)
            FROM commande
            WHERE date_creation_commande >= (CURRENT_DATE - INTERVAL :days2 DAY)
              AND date_creation_commande <  (CURRENT_DATE - INTERVAL :days DAY)
        ", ['days' => $days, 'days2' => $days * 2]);

        $variationCA = $caPrev > 0 ? (($caPeriode - $caPrev) / $caPrev) * 100 : null;

        // =========================
        // ✅ Commandes par statut (donut)
        // =========================
        $statuts = $conn->fetchAllAssociative("
            SELECT statut_commande AS label, COUNT(*) AS value
            FROM commande
            WHERE date_creation_commande >= (CURRENT_DATE - INTERVAL :days DAY)
            GROUP BY statut_commande
            ORDER BY value DESC
        ", ['days' => $days]);

        // =========================
        // ✅ CA par jour (courbe)
        // =========================
        $caParJour = $conn->fetchAllAssociative("
            SELECT DATE(date_creation_commande) AS jour,
                   SUM(montant_total) AS total
            FROM commande
            WHERE date_creation_commande >= (CURRENT_DATE - INTERVAL :days DAY)
            GROUP BY DATE(date_creation_commande)
            ORDER BY jour ASC
        ", ['days' => $days]);

        // =========================
        // ✅ Top 5 produits vendus (bar)
        // table lignes = commande_produit (selon ton entity LigneCommande)
        // =========================
        $topProduits = $conn->fetchAllAssociative("
            SELECT p.nom_produit AS produit,
                   SUM(cp.quantite_commandee) AS qte
            FROM commande_produit cp
            INNER JOIN produit p ON p.id_produit = cp.id_produit
            INNER JOIN commande c ON c.id_commande = cp.id_commande
            WHERE c.date_creation_commande >= (CURRENT_DATE - INTERVAL :days DAY)
            GROUP BY p.nom_produit
            ORDER BY qte DESC
            LIMIT 5
        ", ['days' => $days]);

        // =========================
        // ✅ Stock bas + Rupture
        // =========================
        $seuilStock = 5;

        $stocksBas = $conn->fetchAllAssociative("
            SELECT nom_produit, quantite_produit
            FROM produit
            WHERE quantite_produit <= :seuil
            ORDER BY quantite_produit ASC
            LIMIT 5
        ", ['seuil' => $seuilStock]);

        $nbProduits = (int) $conn->fetchOne("SELECT COUNT(*) FROM produit");
        $nbRupture = (int) $conn->fetchOne("SELECT COUNT(*) FROM produit WHERE status_produit = 'Rupture'");
        $tauxRupture = $nbProduits > 0 ? ($nbRupture / $nbProduits) * 100 : 0;

        // =========================
        // ✅ Commandes en attente (pour détecter blocage paiement/process)
        // =========================
        $nbEnAttente = (int) $conn->fetchOne("
            SELECT COUNT(*)
            FROM commande
            WHERE statut_commande = 'En attente'
              AND date_creation_commande >= (CURRENT_DATE - INTERVAL :days DAY)
        ", ['days' => $days]);

        // =========================
        // ✅ Alertes + Conseils automatiques (BI)
        // =========================
        $alerts = [];
        $tips = [];

        // 1) Stock bas
        if (!empty($stocksBas)) {
            $alerts[] = [
                'type' => 'warning',
                'title' => "Stock bas",
                'message' => "Certains produits ont un stock ≤ {$seuilStock}.",
            ];
            $tips[] = "Réapprovisionne les produits critiques pour éviter la rupture (perte de ventes).";
        }

        // 2) Trop de ruptures
        if ($tauxRupture >= 15) {
            $alerts[] = [
                'type' => 'danger',
                'title' => "Trop de ruptures",
                'message' => sprintf("%.1f%% des produits sont en rupture.", $tauxRupture),
            ];
            $tips[] = "Beaucoup de ruptures réduisent le CA : priorise les produits les plus vendus.";
        }

        // 3) Baisse / hausse du CA
        if ($variationCA !== null && $variationCA <= -20) {
            $alerts[] = [
                'type' => 'danger',
                'title' => "Baisse du chiffre d’affaires",
                'message' => sprintf("CA en baisse de %.1f%% vs période précédente.", abs($variationCA)),
            ];
            $tips[] = "Relance ventes : promo courte (48h), mise en avant top produits, vérifie disponibilité/stock.";
        } elseif ($variationCA !== null && $variationCA >= 20) {
            $alerts[] = [
                'type' => 'success',
                'title' => "Bonne performance",
                'message' => sprintf("CA en hausse de %.1f%% vs période précédente ✅", $variationCA),
            ];
            $tips[] = "Continue sur cette lancée : garde les top produits en stock et optimise la livraison.";
        }

        // 4) Trop de commandes en attente
        if ($nbEnAttente >= 5) {
            $alerts[] = [
                'type' => 'info',
                'title' => "Commandes en attente",
                'message' => "{$nbEnAttente} commandes sont encore 'En attente' sur la période.",
            ];
            $tips[] = "Vérifie Stripe (retours success/cancel) et simplifie le checkout (moins d’abandons).";
        }

        // 5) Faible activité (peu de commandes)
        if ($nbPeriode < 5) {
            $alerts[] = [
                'type' => 'info',
                'title' => "Faible activité",
                'message' => "Moins de 5 commandes sur la période.",
            ];
            $tips[] = "Proposition : code promo -10% (48h) + bannière sur la page produits.";
        }

        if (empty($tips)) {
            $tips[] = "Tout est stable ✅ Continue à surveiller le CA, les statuts et le stock.";
        }

        // =========================
        // ✅ Format data pour Chart.js
        // =========================
        $labelsDays = array_map(fn($r) => $r['jour'], $caParJour);
        $valuesDays = array_map(fn($r) => (float) $r['total'], $caParJour);

        $statusLabels = array_map(fn($r) => $r['label'], $statuts);
        $statusValues = array_map(fn($r) => (int) $r['value'], $statuts);

        $topLabels = array_map(fn($r) => $r['produit'], $topProduits);
        $topValues = array_map(fn($r) => (int) $r['qte'], $topProduits);

        return [
            'kpi' => [
                'caTotal' => $caTotal,
                'caPeriode' => $caPeriode,
                'nbTotal' => $nbCommandes,
                'nbPeriode' => $nbPeriode,
                'panierMoyen' => $panierMoyen,
                'variationCA' => $variationCA,
                'nbEnAttente' => $nbEnAttente,
                'tauxRupture' => $tauxRupture,
            ],
            'charts' => [
                'ca' => ['labels' => $labelsDays, 'values' => $valuesDays],
                'statuts' => ['labels' => $statusLabels, 'values' => $statusValues],
                'top' => ['labels' => $topLabels, 'values' => $topValues],
            ],
            'topProduits' => $topProduits,
            'stocksBas' => $stocksBas,
            'alerts' => $alerts,
            'tips' => $tips,
            'days' => $days,
        ];
    }
}
