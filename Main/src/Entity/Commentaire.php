<?php

namespace App\Entity;

use App\Repository\CommentaireRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentaireRepository::class)]
class Commentaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $contenu = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date_creation = null;

    #[ORM\ManyToOne(inversedBy: 'commentaires')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Post $post = null;

    #[ORM\Column]
    private ?bool $est_anonyme = null;

    // ex: PUBLIC | PRIVE | AMIS
    #[ORM\Column(length: 60)]
    private ?string $parametres_confidentialite = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'commentaires')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;
 
    // src/Entity/Commentaire.php

#[ORM\Column(length: 20)]
private string $status = 'published'; // published | blocked | pending

#[ORM\Column(nullable: true)]
private ?float $moderationScore = null;

#[ORM\Column(length: 50, nullable: true)]
private ?string $moderationLabel = null;

#[ORM\Column(nullable: true)]
private ?\DateTimeImmutable $moderatedAt = null;

public function getStatus(): string
{
    return $this->status;
}

public function setStatus(string $status): self
{
    $this->status = $status;
    return $this;
}

public function getModerationScore(): ?float
{
    return $this->moderationScore;
}

public function setModerationScore(?float $moderationScore): self
{
    $this->moderationScore = $moderationScore;
    return $this;
}

public function getModerationLabel(): ?string
{
    return $this->moderationLabel;
}

public function setModerationLabel(?string $moderationLabel): self
{
    $this->moderationLabel = $moderationLabel;
    return $this;
}

public function getModeratedAt(): ?\DateTimeImmutable
{
    return $this->moderatedAt;
}

public function setModeratedAt(?\DateTimeImmutable $moderatedAt): self
{
    $this->moderatedAt = $moderatedAt;
    return $this;
}

    public function __construct()
    {
        $this->date_creation = new \DateTimeImmutable();
        $this->est_anonyme = false;
        $this->parametres_confidentialite = 'PUBLIC';
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

   public function setContenu(?string $contenu): self
{
    $this->contenu = $contenu ?? '';
    return $this;
}


    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->date_creation;
    }

    public function setDateCreation(\DateTimeImmutable $date_creation): static
    {
        $this->date_creation = $date_creation;
        return $this;
    }

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function setPost(?Post $post): static
    {
        $this->post = $post;
        return $this;
    }

    public function isEstAnonyme(): ?bool
    {
        return $this->est_anonyme;
    }

    public function setEstAnonyme(bool $est_anonyme): static
    {
        $this->est_anonyme = $est_anonyme;
        return $this;
    }

    public function getParametresConfidentialite(): ?string
    {
        return $this->parametres_confidentialite;
    }

    public function setParametresConfidentialite(string $parametres_confidentialite): static
    {
        $this->parametres_confidentialite = $parametres_confidentialite;
        return $this;
    }
}