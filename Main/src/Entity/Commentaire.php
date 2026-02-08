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

    public function __construct()
    {
        $this->date_creation = new \DateTimeImmutable();
        $this->est_anonyme = false;
        $this->parametres_confidentialite = 'PUBLIC';
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
