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
    #[ORM\Column(name: "id_commande")]
    private ?int $id_commande = null;

    #[ORM\Column]
    private ?int $id_user = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date_creation_commande = null;

    #[ORM\Column(length: 150)]
    private ?string $statut_commande = null;

    #[ORM\Column]
    private ?float $montant_total = null;

    // ✅ Stripe
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripe_session_id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paid_at = null;

    /**
     * @var Collection<int, LigneCommande>
     */
    #[ORM\OneToMany(
        targetEntity: LigneCommande::class,
        mappedBy: 'commande',
        cascade: ['persist', 'remove'], // ✅ IMPORTANT (persist ajouté)
        orphanRemoval: true
    )]
    private Collection $ligne_commandes;

    public function __construct()
    {
        $this->ligne_commandes = new ArrayCollection();
    }

    // =========================
    //         GETTERS/SETTERS
    // =========================

    public function getIdCommande(): ?int
    {
        return $this->id_commande;
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

    // ✅ Stripe
    public function getStripeSessionId(): ?string
    {
        return $this->stripe_session_id;
    }

    public function setStripeSessionId(?string $stripe_session_id): static
    {
        $this->stripe_session_id = $stripe_session_id;
        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paid_at;
    }

    public function setPaidAt(?\DateTimeImmutable $paid_at): static
    {
        $this->paid_at = $paid_at;
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
            $ligneCommande->setCommande($this); // ✅ lien obligatoire
        }

        return $this;
    }

    public function removeLigneCommande(LigneCommande $ligneCommande): static
    {
        if ($this->ligne_commandes->removeElement($ligneCommande)) {
            if ($ligneCommande->getCommande() === $this) {
                $ligneCommande->setCommande(null);
            }
        }

        return $this;
    }
}