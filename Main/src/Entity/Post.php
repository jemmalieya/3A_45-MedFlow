<?php

namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: PostRepository::class)]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $contenu = null;

    #[ORM\Column(length: 255)]
    private ?string $localisation = null;

    #[ORM\Column(length: 255)]
    private ?string $img_post = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $hashtags = null;

    #[ORM\Column(length: 50)]
    private ?string $visibilite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date_creation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $date_modification = null;

    #[ORM\Column]
    private ?bool $est_anonyme = null;

    #[ORM\Column(length: 255)]
    private ?string $categorie = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $humeur = null;

    #[ORM\Column(nullable: true)]
    private ?int $nbr_reactions = null;

    #[ORM\Column(nullable: true)]
    private ?int $nbr_commentaires = null;

     #[ORM\OneToMany(targetEntity: Commentaire::class, mappedBy: 'post')]
    private $commentaires;

    public function __construct()
    {
        $this->commentaires = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = $contenu;

        return $this;
    }

    public function getLocalisation(): ?string
    {
        return $this->localisation;
    }

    public function setLocalisation(string $localisation): static
    {
        $this->localisation = $localisation;

        return $this;
    }

    public function getImgPost(): ?string
    {
        return $this->img_post;
    }

    public function setImgPost(string $img_post): static
    {
        $this->img_post = $img_post;

        return $this;
    }

    public function getHashtags(): ?string
    {
        return $this->hashtags;
    }

    public function setHashtags(?string $hashtags): static
    {
        $this->hashtags = $hashtags;

        return $this;
    }

    public function getVisibilite(): ?string
    {
        return $this->visibilite;
    }

    public function setVisibilite(string $visibilite): static
    {
        $this->visibilite = $visibilite;

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

    public function getDateModification(): ?\DateTimeImmutable
    {
        return $this->date_modification;
    }

    public function setDateModification(?\DateTimeImmutable $date_modification): static
    {
        $this->date_modification = $date_modification;

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

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(string $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getHumeur(): ?string
    {
        return $this->humeur;
    }

    public function setHumeur(?string $humeur): static
    {
        $this->humeur = $humeur;

        return $this;
    }

    public function getNbrReactions(): ?int
    {
        return $this->nbr_reactions;
    }

    public function setNbrReactions(?int $nbr_reactions): static
    {
        $this->nbr_reactions = $nbr_reactions;

        return $this;
    }

    public function getNbrCommentaires(): ?int
    {
        return $this->nbr_commentaires;
    }

    public function setNbrCommentaires(?int $nbr_commentaires): static
    {
        $this->nbr_commentaires = $nbr_commentaires;

        return $this;
    }
}
