<?php

namespace App\Entity;

use App\Repository\ReponseReclamationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: ReponseReclamationRepository::class)]
class ReponseReclamation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id_reponse")]
    private ?int $id_reponse = null;

    #[ORM\Column(type: "text")]
    #[Assert\NotBlank(message: "Le message de la réponse est obligatoire.")]
    #[Assert\Length(min: 10, minMessage: "Le message doit contenir au moins {{ limit }} caractères.", max: 1000, maxMessage: "Le message ne doit pas dépasser {{ limit }} caractères.")]
    private ?string $message = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: "Le type de réponse est obligatoire.")]
    #[Assert\Choice(choices: ["REPONSE", "DEMANDE_INFO", "REFUS"], message: "Type de réponse invalide.")]
    private ?string $typeReponse = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date_creation_rep = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date_modification_rep = null;

    #[ORM\ManyToOne(inversedBy: 'reponses')]
    #[ORM\JoinColumn(name: "id_reclamation", referencedColumnName: "id_reclamation", nullable: false, onDelete: "CASCADE")]
    private ?Reclamation $reclamation = null;

    // ✅ lifecycle create
    #[ORM\PrePersist]
    public function onCreate(): void
    {
        $now = new \DateTimeImmutable();
        if ($this->date_creation_rep === null) {
            $this->date_creation_rep = $now;
        }
        $this->date_modification_rep = $now;
    }
    #[ORM\Column(type: 'boolean')]
private bool $isRead = false;

public function isRead(): bool
{
    return $this->isRead;
}

public function setIsRead(bool $isRead): self
{
    $this->isRead = $isRead;
    return $this;
}


    // ✅ lifecycle update
    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->date_modification_rep = new \DateTimeImmutable();
    }

    // ===== GETTERS / SETTERS =====
    public function getIdReponse(): ?int { return $this->id_reponse; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getTypeReponse(): ?string { return $this->typeReponse; }
    public function setTypeReponse(string $typeReponse): static
    {
        $this->typeReponse = $typeReponse;
        return $this;
    }

    public function getDateCreationRep(): ?\DateTimeImmutable { return $this->date_creation_rep; }
    public function getDateModificationRep(): ?\DateTimeImmutable { return $this->date_modification_rep; }

    public function getReclamation(): ?Reclamation { return $this->reclamation; }
    public function setReclamation(?Reclamation $reclamation): static
    {
        $this->reclamation = $reclamation;
        return $this;
    }
}
