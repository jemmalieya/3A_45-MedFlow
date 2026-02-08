<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class AdminBIService
{
    public function __construct(private EntityManagerInterface $em) {}

    public function buildDashboard(
        int $days = 30,
        ?string $from = null,
        ?string $to = null,
        ?string $cat = null
    ): array
    {
        $conn = $this->em->getConnection();

        // Période (TOUJOURS utiliser from/to s'ils sont fournis)
        if ($from && $to) {
            $from = (new \DateTimeImmutable($from))->format('Y-m-d');
            $to   = (new \DateTimeImmutable($to))->format('Y-m-d');
        } else {
            $toObj = new \DateTimeImmutable('today');
            $fromObj = $toObj->modify("-{$days} days");
            $from = $fromObj->format('Y-m-d');
            $to   = $toObj->format('Y-m-d');
        }

        // Catégories
        $categories = $conn->fetchFirstColumn("
            SELECT DISTINCT categorie_produit
            FROM produit
            ORDER BY categorie_produit
        ");

        // Période précédente
        $fromObj = new \DateTimeImmutable($from);
        $toObj   = new \DateTimeImmutable($to);
        $diffDays = max(1, (int)$fromObj->diff($toObj)->days);
        $prevTo   = $fromObj->modify('-1 day');
        $prevFrom = $prevTo->modify("-{$diffDays} days");

        // KPI période actuelle
        $caPeriode = (float) $conn->fetchOne("
            SELECT COALESCE(SUM(montant_total), 0)
            FROM commande
            WHERE DATE(date_creation_commande) BETWEEN :from AND :to
        ", ['from' => $from, 'to' => $to]);

        $nbPeriode = (int) $conn->fetchOne("
            SELECT COUNT(*)
            FROM commande
            WHERE DATE(date_creation_commande) BETWEEN :from AND :to
        ", ['from' => $from, 'to' => $to]);

        $nbValidees = (int) $conn->fetchOne("
            SELECT COUNT(*)
            FROM commande
            WHERE DATE(date_creation_commande) BETWEEN :from AND :to
              AND statut_commande IN ('Validée', 'Livrée', 'En préparation')
        ", ['from' => $from, 'to' => $to]);

        $nbEnAttente = (int) $conn->fetchOne("
            SELECT COUNT(*)
            FROM commande
            WHERE statut_commande = 'En attente'
              AND DATE(date_creation_commande) BETWEEN :from AND :to
        ", ['from' => $from, 'to' => $to]);

        $quantiteTotale = (int) $conn->fetchOne("
            SELECT COALESCE(SUM(cp.quantite_commandee), 0)
            FROM commande_produit cp
            INNER JOIN commande c ON c.id_commande = cp.id_commande
            WHERE DATE(c.date_creation_commande) BETWEEN :from AND :to
        ", ['from' => $from, 'to' => $to]);

        $panierMoyen = $nbPeriode > 0 ? $caPeriode / $nbPeriode : 0;
        $tauxConversion = $nbPeriode > 0 ? ($nbValidees / $nbPeriode) * 100 : 0;

        // KPI période précédente
        $caPrev = (float) $conn->fetchOne("
            SELECT COALESCE(SUM(montant_total), 0)
            FROM commande
            WHERE DATE(date_creation_commande) BETWEEN :from AND :to
        ", ['from' => $prevFrom->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d')]);

        $nbPrev = (int) $conn->fetchOne("
            SELECT COUNT(*)
            FROM commande
            WHERE DATE(date_creation_commande) BETWEEN :from AND :to
        ", ['from' => $prevFrom->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d')]);

        $panierMoyenPrev = $nbPrev > 0 ? $caPrev / $nbPrev : 0;

        // Variations
        $variationCA = $caPrev > 0 ? (($caPeriode - $caPrev) / $caPrev) * 100 : null;
        $variationCommandes = $nbPrev > 0 ? (($nbPeriode - $nbPrev) / $nbPrev) * 100 : null;
        $variationPanier = $panierMoyenPrev > 0 ? (($panierMoyen - $panierMoyenPrev) / $panierMoyenPrev) * 100 : null;

        // Ventes par jour (CA + quantité + nb commandes)
        $sqlVentesJour = "
            SELECT 
                DATE(c.date_creation_commande) AS jour,
                SUM(c.montant_total) AS ca,
                COUNT(DISTINCT c.id_commande) AS nb_commandes,
                COALESCE(SUM(cp.quantite_commandee), 0) AS quantite
            FROM commande c
            LEFT JOIN commande_produit cp ON cp.id_commande = c.id_commande
        ";

        if (!empty($cat)) {
            $sqlVentesJour .= "
                LEFT JOIN produit p ON p.id_produit = cp.id_produit
                WHERE DATE(c.date_creation_commande) BETWEEN :from AND :to
                  AND p.categorie_produit = :cat
            ";
            $paramsVentes = ['from' => $from, 'to' => $to, 'cat' => $cat];
        } else {
            $sqlVentesJour .= "
                WHERE DATE(c.date_creation_commande) BETWEEN :from AND :to
            ";
            $paramsVentes = ['from' => $from, 'to' => $to];
        }

        $sqlVentesJour .= "
            GROUP BY DATE(c.date_creation_commande)
            ORDER BY jour ASC
        ";

        $ventesParJour = $conn->fetchAllAssociative($sqlVentesJour, $paramsVentes);

        // Répartition par catégorie
        $sqlCategories = "
            SELECT 
                p.categorie_produit AS categorie,
                SUM(cp.quantite_commandee) AS quantite,
                SUM(cp.quantite_commandee * p.prix_produit) AS ca
            FROM commande_produit cp
            INNER JOIN produit p ON p.id_produit = cp.id_produit
            INNER JOIN commande c ON c.id_commande = cp.id_commande
            WHERE DATE(c.date_creation_commande) BETWEEN :from AND :to
        ";
        $paramsCategories = ['from' => $from, 'to' => $to];

        if (!empty($cat)) {
            $sqlCategories .= " AND p.categorie_produit = :cat ";
            $paramsCategories['cat'] = $cat;
        }

        $sqlCategories .= "
            GROUP BY p.categorie_produit
            ORDER BY ca DESC
        ";

        $repartitionCategories = $conn->fetchAllAssociative($sqlCategories, $paramsCategories);

        // Top produits
        $sqlTop = "
            SELECT 
                p.nom_produit AS produit,
                p.categorie_produit AS categorie,
                SUM(cp.quantite_commandee) AS quantite,
                SUM(cp.quantite_commandee * p.prix_produit) AS ca,
                p.quantite_produit AS stock_actuel
            FROM commande_produit cp
            INNER JOIN produit p ON p.id_produit = cp.id_produit
            INNER JOIN commande c ON c.id_commande = cp.id_commande
            WHERE DATE(c.date_creation_commande) BETWEEN :from AND :to
        ";
        $paramsTop = ['from' => $from, 'to' => $to];

        if (!empty($cat)) {
            $sqlTop .= " AND p.categorie_produit = :cat ";
            $paramsTop['cat'] = $cat;
        }

        $sqlTop .= "
            GROUP BY p.id_produit, p.nom_produit, p.categorie_produit, p.quantite_produit
            ORDER BY quantite DESC
            LIMIT 10
        ";

        $topProduits = $conn->fetchAllAssociative($sqlTop, $paramsTop);

        // Commandes par statut
        $statuts = $conn->fetchAllAssociative("
            SELECT statut_commande AS label, COUNT(*) AS value
            FROM commande
            WHERE DATE(date_creation_commande) BETWEEN :from AND :to
            GROUP BY statut_commande
            ORDER BY value DESC
        ", ['from' => $from, 'to' => $to]);

        // Stock critique
        $seuilStock = 10;
        $stocksBas = $conn->fetchAllAssociative("
            SELECT nom_produit, categorie_produit, quantite_produit, status_produit
            FROM produit
            WHERE quantite_produit <= :seuil
            ORDER BY quantite_produit ASC
            LIMIT 10
        ", ['seuil' => $seuilStock]);

        $nbProduits = (int) $conn->fetchOne("SELECT COUNT(*) FROM produit");
        $nbRupture = (int) $conn->fetchOne("SELECT COUNT(*) FROM produit WHERE status_produit = 'Rupture'");
        $tauxRupture = $nbProduits > 0 ? ($nbRupture / $nbProduits) * 100 : 0;

        // Alertes
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

        // Conseils
        $tips = [];
        if ($variationCA !== null && $variationCA <= -15) {
            $tips[] = "Analyser les produits les plus performants et mettre en place des promotions ciblées";
        }
        if ($tauxConversion < 70) {
            $tips[] = "Améliorer le processus de validation pour augmenter le taux de conversion";
        }
        if ($tauxRupture >= 10) {
            $tips[] = "Planifier le réapprovisionnement prioritaire des produits en rupture";
        }
        if (empty($tips)) {
            $tips[] = "Les indicateurs sont stables. Continuer le suivi régulier";
        }

        return [
            'period' => [
                'from' => $from,
                'to' => $to,
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