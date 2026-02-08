<?php

namespace App\Service;

use App\Entity\Produit;
use App\Repository\ProduitRepository;

class ProduitService
{
    private ProduitRepository $produitRepository;

    public function __construct(ProduitRepository $produitRepository)
    {
        $this->produitRepository = $produitRepository;
    }

    /**
     * Récupère les produits filtrés avec enrichissement des données
     */
    public function getFilteredProducts(?string $search = null, ?string $category = null, ?string $sortPrice = null): array
    {
        // Récupération depuis le repository
        $produits = $this->produitRepository->findFiltered($search, $category, $sortPrice);

        // Enrichissement des données (logique métier)
        foreach ($produits as $produit) {
            // Ajouter un indicateur de stock faible
            if ($produit->getQuantiteProduit() < 10 && $produit->getStatusProduit() === 'Disponible') {
                $produit->lowStockWarning = true;
            }

            // Calcul du pourcentage de stock
            $produit->stockPercentage = $this->calculateStockPercentage($produit);
        }

        return $produits;
    }

    /**
     * Récupère les statistiques de la pharmacie
     */
    public function getPharmacieStatistics(): array
    {
        $allProducts = $this->produitRepository->findAll();
        $availableProducts = $this->produitRepository->countAvailableProducts();
        $lowStockProducts = $this->produitRepository->findLowStockProducts();
        $categories = $this->produitRepository->findAllCategories();

        return [
            'total' => count($allProducts),
            'disponibles' => $availableProducts,
            'rupture_stock' => count($lowStockProducts),
            'nombre_categories' => count($categories),
            'categories' => $categories,
            'taux_disponibilite' => count($allProducts) > 0 
                ? round(($availableProducts / count($allProducts)) * 100, 2) 
                : 0,
        ];
    }

    /**
     * Vérifie si un produit peut être commandé
     */
    public function canOrderProduct(int $produitId, int $quantity): bool
    {
        $produit = $this->produitRepository->find($produitId);

        if (!$produit) {
            return false;
        }

        // Logique métier : vérifications multiples
        return $produit->getStatusProduit() === 'Disponible' 
            && $produit->getQuantiteProduit() >= $quantity
            && $quantity > 0
            && $quantity <= 100; // Limite max par commande
    }

    /**
     * Récupère les produits recommandés
     */
    public function getRecommendedProducts(int $limit = 4): array
    {
        return $this->produitRepository->findBy(
            ['statusProduit' => 'Disponible'], 
            ['quantiteProduit' => 'DESC'],
            $limit
        );
    }

    /**
     * Récupère les alertes de stock
     */
    public function getStockAlerts(): array
    {
        return $this->produitRepository->findLowStockProducts(10);
    }

    /**
     * Calcule le pourcentage de stock
     */
    private function calculateStockPercentage(Produit $produit): int
    {
        $maxStock = 100; // Valeur fictive de stock maximum
        $currentStock = $produit->getQuantiteProduit();
        
        return min(100, (int) (($currentStock / $maxStock) * 100));
    }

    /**
     * Prépare les données pour l'affichage front
     */
    public function getFrontDisplayData(?string $search = null, ?string $category = null, ?string $sortPrice = null): array
    {
        return [
            'produits' => $this->getFilteredProducts($search, $category, $sortPrice),
            'categories' => $this->produitRepository->findAllCategories(),
            'statistics' => $this->getPharmacieStatistics(),
            'recommendations' => $this->getRecommendedProducts(4),
        ];
    }

    /**
     * Prépare les données pour l'affichage admin
     */
    public function getAdminDisplayData(): array
    {
        return [
            'produits' => $this->produitRepository->findAll(),
            'statistics' => $this->getPharmacieStatistics(),
            'stock_alerts' => $this->getStockAlerts(),
        ];
    }
}