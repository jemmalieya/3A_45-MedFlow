<?php

namespace App\Tests\Service;

use App\Entity\Produit;
use App\Service\ProduitManager;
use PHPUnit\Framework\TestCase;

class ProduitManagerTest extends TestCase
{
    public function testValidProduit()
    {
        $produit = new Produit();
        $produit->setNomProduit('Paracetamol');
        $produit->setDescriptionProduit('Antalgique et antipyrétique pour douleurs et fièvre.');

        $produit->setPrixProduit(15);
        $produit->setQuantiteProduit(5);

        $manager = new ProduitManager();

        $this->assertTrue($manager->validate($produit));
    }

    public function testProduitWithoutName()
    {
        $this->expectException(\InvalidArgumentException::class);

        $produit = new Produit();
        $produit->setPrixProduit(10);
        $produit->setQuantiteProduit(5);

        $manager = new ProduitManager();
        $manager->validate($produit);
    }

    public function testProduitWithInvalidPrice()
    {
        $this->expectException(\InvalidArgumentException::class);

        $produit = new Produit();
        $produit->setNomProduit('Doliprane');
        $produit->setPrixProduit(0);
        $produit->setQuantiteProduit(5);

        $manager = new ProduitManager();
        $manager->validate($produit);
    }
    public function testProduitWithoutDescription()
{
    $this->expectException(\InvalidArgumentException::class);

    $produit = new Produit();
    $produit->setNomProduit('Aspirine');
    $produit->setPrixProduit(10);
    $produit->setQuantiteProduit(5);
    // pas de description

    $manager = new ProduitManager();
    $manager->validate($produit);
}

    public function testProduitWithNegativeQuantity()
    {
        $this->expectException(\InvalidArgumentException::class);

        $produit = new Produit();
        $produit->setNomProduit('Aspirine');
        $produit->setPrixProduit(10);
        $produit->setQuantiteProduit(-1);

        $manager = new ProduitManager();
        $manager->validate($produit);
    }
}