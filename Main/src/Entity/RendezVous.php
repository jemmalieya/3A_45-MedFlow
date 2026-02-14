<?php

namespace App\Entity;

use App\Repository\RendezVousRepository;
use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RendezVousRepository::class)]
class RendezVous
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    #[ORM\Column(nullable: true)]
    #[Assert\NotNull(message: 'La date et l\'heure sont obligatoires')]
    #[Assert\GreaterThan('now', message: 'La date et l\'heure doivent être dans le futur')]
    private ?\DateTime $datetime = null;

    #[ORM\Column(length: 50)]
    private ?string $statut = 'Demande';

    #[ORM\Column(length: 50)]
    private ?string $mode = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le motif est obligatoire')]
    #[Assert\Length(
        max: 150,
        maxMessage: 'Le motif ne peut pas dépasser 150 caractères'
    )]
    private ?string $motif = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: "idPatient", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private ?User $patient = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: "idStaff", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private ?User $staff = null;
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDatetime(): ?\DateTime
    {
        return $this->datetime;
    }

    public function setDatetime(?\DateTime $datetime): static
    {
        $this->datetime = $datetime;
        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(string $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): static
    {
        $this->motif = $motif;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getPatient(): ?User
    {
        return $this->patient;
    }

    public function setPatient(?User $patient): static
    {
        $this->patient = $patient;
        return $this;
    }

    public function getStaff(): ?User
    {
        return $this->staff;
    }

    public function setStaff(?User $staff): static
    {
        $this->staff = $staff;
        return $this;
    }
}