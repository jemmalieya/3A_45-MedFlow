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
    private int $id_ligne_commande = 0;

    #[ORM\Column(name: "quantite_commandee")]
    private int $quantite_commandee = 0;

    #[ORM\ManyToOne(inversedBy: 'ligne_commandes')]
    #[ORM\JoinColumn(
        name: "commande_id",
        referencedColumnName: "id_commande",
        nullable: false,
        onDelete: "CASCADE"
    )]
    private ?Commande $commande = null;

    #[ORM\ManyToOne(inversedBy: 'ligne_commandes')]
    #[ORM\JoinColumn(
        name: "produit_id",
        referencedColumnName: "id_produit",
        nullable: false,
        onDelete: "CASCADE"
    )]
    private ?Produit $produit = null;

    public function getId_ligne_commande(): ?int
    {
        return $this->id_ligne_commande > 0 ? $this->id_ligne_commande : null;
    }

    public function getQuantite_commandee(): int
    {
        return $this->quantite_commandee;
    }

    public function setQuantite_commandee(int $quantite_commandee): self
    {
        $this->quantite_commandee = max(0, $quantite_commandee);
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