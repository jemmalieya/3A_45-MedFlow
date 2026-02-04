<?php

namespace App\Entity;

use App\Repository\CommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandeRepository::class)]
class Commande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id_commande  = null;

    #[ORM\Column]
    private ?int $id_user = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date_creation_commande = null;

    #[ORM\Column(length: 150)]
    private ?string $statut_commande = null;

    #[ORM\Column]
    private ?float $montant_total = null;

    /**
     * @var Collection<int, LigneCommande>
     */
    #[ORM\OneToMany(targetEntity: LigneCommande::class, mappedBy: 'commande', cascade: ['remove'], orphanRemoval: true)]
    private Collection $ligne_commandes;

    public function __construct()
    {
        $this->ligne_commandes = new ArrayCollection();
    }

    public function getIdCommande(): ?int
    {
        return $this->id_commande ;
    }

    public function getIdUser(): ?int
    {
        return $this->id_user;
    }

    public function setIdUser(int $id_user): static
    {
        $this->id_user = $id_user;

        return $this;
    }

    public function getDateCreationCommande(): ?\DateTimeImmutable
    {
        return $this->date_creation_commande;
    }

    public function setDateCreationCommande(\DateTimeImmutable $date_creation_commande): static
    {
        $this->date_creation_commande = $date_creation_commande;

        return $this;
    }

    public function getStatutCommande(): ?string
    {
        return $this->statut_commande;
    }

    public function setStatutCommande(string $statut_commande): static
    {
        $this->statut_commande = $statut_commande;

        return $this;
    }

    public function getMontantTotal(): ?float
    {
        return $this->montant_total;
    }

    public function setMontantTotal(float $montant_total): static
    {
        $this->montant_total = $montant_total;

        return $this;
    }

    /**
     * @return Collection<int, LigneCommande>
     */
    public function getLigneCommandes(): Collection
    {
        return $this->ligne_commandes;
    }

    public function addLigneCommande(LigneCommande $ligneCommande): static
    {
        if (!$this->ligne_commandes->contains($ligneCommande)) {
            $this->ligne_commandes->add($ligneCommande);
            $ligneCommande->setCommande($this);
        }

        return $this;
    }

    public function removeLigneCommande(LigneCommande $ligneCommande): static
    {
        if ($this->ligne_commandes->removeElement($ligneCommande)) {
            // set the owning side to null (unless already changed)
            if ($ligneCommande->getCommande() === $this) {
                $ligneCommande->setCommande(null);
            }
        }

        return $this;
    }
}
