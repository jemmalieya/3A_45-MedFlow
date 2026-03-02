<?php

namespace App\Entity;

use App\Repository\CommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandeRepository::class)]
#[ORM\Table(name: 'commande')]
class Commande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id_commande")]
    private int $id_commande = 0; // ✅ Doctrine l’assigne

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'commandes')]
    #[ORM\JoinColumn(name: "user_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private User $user;

    #[ORM\Column(name: "date_creation_commande", type: "datetime_immutable", nullable: false)]
    private \DateTimeImmutable $date_creation_commande;

    #[ORM\Column(name: "statut_commande", length: 150, nullable: false)]
    private string $statut_commande;

    #[ORM\Column(name: "montant_total_cents", type: "integer", nullable: false)]
    private int $montant_total_cents = 0;

    #[ORM\Column(name: "stripe_session_id", length: 255, nullable: true)]
    private ?string $stripe_session_id = null;

    #[ORM\Column(name: "paid_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $paid_at = null;

    /**
     * @var Collection<int, LigneCommande>
     */
    #[ORM\OneToMany(
        targetEntity: LigneCommande::class,
        mappedBy: 'commande',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $ligne_commandes;

    public function __construct()
    {
        $this->ligne_commandes = new ArrayCollection();
        $this->date_creation_commande = new \DateTimeImmutable();
        $this->statut_commande = 'En attente';
    }

    public function getIdCommande(): ?int
    {
        return $this->id_commande > 0 ? $this->id_commande : null;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getDateCreationCommande(): \DateTimeImmutable
    {
        return $this->date_creation_commande;
    }

    public function setDateCreationCommande(\DateTimeImmutable $date_creation_commande): self
    {
        $this->date_creation_commande = $date_creation_commande;
        return $this;
    }

    public function getStatutCommande(): string
    {
        return $this->statut_commande;
    }

    public function setStatutCommande(string $statut_commande): self
    {
        $this->statut_commande = $statut_commande;
        return $this;
    }

    public function getMontantTotalCents(): int
    {
        return $this->montant_total_cents;
    }

    public function setMontantTotalCents(int $cents): self
    {
        $this->montant_total_cents = max(0, $cents);
        return $this;
    }

    public function getMontantTotal(): float
    {
        return $this->montant_total_cents / 100;
    }

    public function setMontantTotal(float $montant_total): self
    {
        $this->montant_total_cents = max(0, (int) round($montant_total * 100));
        return $this;
    }

    public function getStripeSessionId(): ?string
    {
        return $this->stripe_session_id;
    }

    public function setStripeSessionId(?string $stripe_session_id): self
    {
        $this->stripe_session_id = $stripe_session_id;
        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paid_at;
    }

    public function setPaidAt(?\DateTimeImmutable $paid_at): self
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

    public function addLigneCommande(LigneCommande $ligneCommande): self
    {
        if (!$this->ligne_commandes->contains($ligneCommande)) {
            $this->ligne_commandes->add($ligneCommande);
            $ligneCommande->setCommande($this);
        }
        return $this;
    }

    public function removeLigneCommande(LigneCommande $ligneCommande): self
    {
        if ($this->ligne_commandes->removeElement($ligneCommande)) {
            if ($ligneCommande->getCommande() === $this) {
                $ligneCommande->setCommande(null);
            }
        }
        return $this;
    }
}