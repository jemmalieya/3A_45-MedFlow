<?php

namespace App\Service;

use App\Entity\Produit;

class ProduitManager
{
    public function validate(Produit $produit): bool
    {
        if (empty($produit->getNomProduit())) {
            throw new \InvalidArgumentException("Le nom du produit est obligatoire");
        }

        if ($produit->getPrixProduit() <= 0) {
            throw new \InvalidArgumentException("Le prix doit être supérieur à zéro");
        }

        if ($produit->getQuantiteProduit() < 0) {
            throw new \InvalidArgumentException("La quantité ne peut pas être négative");
        }
        if (empty($produit->getDescriptionProduit())) {
            throw new \InvalidArgumentException("La description du produit est obligatoire");
        }

        return true;
    }
}