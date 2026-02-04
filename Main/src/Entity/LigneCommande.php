<?php

namespace App\Entity;

use App\Repository\LigneCommandeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LigneCommandeRepository::class)]
#[ORM\Table(name: "commande_produit")]
class LigneCommande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id_ligne_commande")]
    private ?int $id_ligne_commande = null;

    #[ORM\Column(name: "quantite_commandee")]
    private int $quantite_commandee;

    #[ORM\ManyToOne(inversedBy: 'ligne_commandes')]
    #[ORM\JoinColumn(name: "id_commande", referencedColumnName: "id_commande", nullable: false)]
    private ?Commande $commande = null;

    #[ORM\ManyToOne(inversedBy: 'ligne_commandes')]
    #[ORM\JoinColumn(name: "id_produit", referencedColumnName: "id_produit", nullable: false)]
    private ?Produit $produit = null;

    public function getId_ligne_commande(): ?int
    {
        return $this->id_ligne_commande;
    }

    public function getQuantite_commandee(): int
    {
        return $this->quantite_commandee;
    }

    public function setQuantite_commandee(int $quantite_commandee): self
    {
        $this->quantite_commandee = $quantite_commandee;
        return $this;
    }

    public function getCommande(): ?Commande
    {
        return $this->commande;
    }

    public function setCommande(?Commande $commande): self
    {
        $this->commande = $commande;
        return $this;
    }

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): self
    {
        $this->produit = $produit;
        return $this;
    }
}