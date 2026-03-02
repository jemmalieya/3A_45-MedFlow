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
     * Récupère les produits filtrés
     *
     * @return Produit[]
     */
    public function getFilteredProducts(?string $search = null, ?string $category = null, ?string $sortPrice = null): array
    {
        // Ton repo accepte aussi sortStock -> on met null par défaut
        return $this->produitRepository->findFiltered($search, $category, $sortPrice, null);
    }

    /**
     * Récupère les statistiques de la pharmacie
     *
     * @return array{
     *   total:int,
     *   disponibles:int,
     *   rupture_stock:int,
     *   nombre_categories:int,
     *   categories:string[],
     *   taux_disponibilite:float
     * }
     */
    public function getPharmacieStatistics(): array
    {
        $allProducts = $this->produitRepository->findAll();
        $availableProducts = $this->produitRepository->countAvailableProducts();
        $lowStockProducts = $this->produitRepository->findLowStockProducts();
        $categories = $this->produitRepository->findAllCategories();

        $total = count($allProducts);

        return [
            'total' => $total,
            'disponibles' => $availableProducts,
            'rupture_stock' => count($lowStockProducts),
            'nombre_categories' => count($categories),
            'categories' => $categories,
            'taux_disponibilite' => $total > 0
                ? round(($availableProducts / $total) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Vérifie si un produit peut être commandé
     */
    public function canOrderProduct(int $produitId, int $quantity): bool
    {
        $produit = $this->produitRepository->find($produitId);

        if (!$produit instanceof Produit) {
            return false;
        }

        return $produit->getStatusProduit() === 'Disponible'
            && $produit->getQuantiteProduit() >= $quantity
            && $quantity > 0
            && $quantity <= 100;
    }

    /**
     * Récupère les produits recommandés
     *
     * @return Produit[]
     */
    public function getRecommendedProducts(int $limit = 4): array
    {
        // Pour éviter problèmes de champs "statusProduit" / "quantiteProduit"
        // on utilise un QueryBuilder "safe" via repo: tri par quantite_produit
        return $this->produitRepository->findFiltered(null, null, null, 'desc');
    }

    /**
     * Récupère les alertes de stock (quantité < 10)
     *
     * @return Produit[]
     */
    public function getStockAlerts(): array
    {
        return $this->produitRepository->findLowStockProducts(10);
    }

    /**
     * Prépare les données pour l'affichage front
     *
     * @return array{
     *   produits: Produit[],
     *   categories: string[],
     *   statistics: array{
     *     total:int,
     *     disponibles:int,
     *     rupture_stock:int,
     *     nombre_categories:int,
     *     categories:string[],
     *     taux_disponibilite:float
     *   },
     *   recommendations: Produit[]
     * }
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
     *
     * @return array{
     *   produits: Produit[],
     *   statistics: array{
     *     total:int,
     *     disponibles:int,
     *     rupture_stock:int,
     *     nombre_categories:int,
     *     categories:string[],
     *     taux_disponibilite:float
     *   },
     *   stock_alerts: Produit[]
     * }
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