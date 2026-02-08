<?php

namespace App\Entity;

use App\Repository\PostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PostRepository::class)]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "Le titre est obligatoire.")]
    #[Assert\Length(
        min: 5,
        max: 255,
        minMessage: "Le titre doit contenir au moins {{ limit }} caractères.",
        maxMessage: "Le titre ne doit pas dépasser {{ limit }} caractères."
    )]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: "Le contenu est obligatoire.")]
    #[Assert\Length(
        min: 20,
        minMessage: "Le contenu doit contenir au moins {{ limit }} caractères."
    )]
    private ?string $contenu = null;

    #[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "La localisation est obligatoire.")]
#[Assert\Length(
    min: 3,
    max: 80,
    minMessage: "La localisation doit contenir au moins {{ limit }} caractères.",
    maxMessage: "La localisation ne doit pas dépasser {{ limit }} caractères."
)]
#[Assert\Regex(
    pattern: "/^[\p{L}0-9\s,'-]+$/u",
    message: "La localisation contient des caractères invalides."
)]
private ?string $localisation = null;


    #[ORM\Column(length: 255)]
    private ?string $img_post = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Regex(
        pattern: "/^(#\w+(\s#\w+)*)?$/u",
        message: "Hashtags invalides. Exemple : #news #sante #rdv"
    )]
    #[Assert\Length(
        max: 255,
        maxMessage: "Les hashtags ne doivent pas dépasser {{ limit }} caractères."
    )]
    private ?string $hashtags = null;

    // ex: PUBLIC | PRIVE | AMIS
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: "La visibilité est obligatoire.")]
    #[Assert\Choice(
        choices: ["PUBLIC", "AMIS", "PRIVE"],
        message: "Visibilité invalide. Choisir : PUBLIC, AMIS ou PRIVE."
    )]
    private ?string $visibilite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date_creation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $date_modification = null;

    #[ORM\Column]
    private ?bool $est_anonyme = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "La catégorie est obligatoire.")]
    #[Assert\Choice(
        choices: ["Actualité","Service","Rendez-vous","Laboratoire","Santé","Conseils","Urgence","Business"],
        message: "Catégorie invalide."
    )]
    private ?string $categorie = null;

     #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "L'humeur' est obligatoire.")]
    #[Assert\Choice(
        choices: ["Heureux","Stressé","Motivé","Calme","Confiant","Fatigué","Triste","En colère","inquiet"],
        message: "humeur invalide."
    )]
    private ?string $humeur= null;

    #[ORM\Column]
    #[Assert\PositiveOrZero(message: "Le nombre de réactions doit être >= 0.")]
    private int $nbr_reactions = 0;

    #[ORM\Column]
    #[Assert\PositiveOrZero(message: "Le nombre de commentaires doit être >= 0.")]
    private int $nbr_commentaires = 0;

    #[ORM\OneToMany(mappedBy: 'post', targetEntity: Commentaire::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['date_creation' => 'DESC'])]
    private Collection $commentaires;

    public function __construct()
    {
        $this->commentaires = new ArrayCollection();

        // valeurs par défaut
        $this->date_creation = new \DateTimeImmutable();
        $this->est_anonyme = false;
        $this->nbr_reactions = 0;
        $this->nbr_commentaires = 0;
        $this->visibilite = 'PUBLIC';
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

    public function touchDateModification(): static
    {
        $this->date_modification = new \DateTimeImmutable();
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

    public function getNbrReactions(): int
    {
        return $this->nbr_reactions;
    }

    public function setNbrReactions(int $nbr_reactions): static
    {
        $this->nbr_reactions = max(0, $nbr_reactions);
        return $this;
    }

    public function incrementReactions(int $by = 1): static
    {
        $this->nbr_reactions += max(1, $by);
        return $this;
    }

    public function getNbrCommentaires(): int
    {
        return $this->nbr_commentaires;
    }

    public function setNbrCommentaires(int $nbr_commentaires): static
    {
        $this->nbr_commentaires = max(0, $nbr_commentaires);
        return $this;
    }

    /**
     * @return Collection<int, Commentaire>
     */
    public function getCommentaires(): Collection
    {
        return $this->commentaires;
    }

    public function addCommentaire(Commentaire $commentaire): static
    {
        if (!$this->commentaires->contains($commentaire)) {
            $this->commentaires->add($commentaire);
            $commentaire->setPost($this);

            // compteur
            $this->nbr_commentaires++;
        }
        return $this;
    }

    public function removeCommentaire(Commentaire $commentaire): static
    {
        if ($this->commentaires->removeElement($commentaire)) {
            if ($commentaire->getPost() === $this) {
                $commentaire->setPost(null);
            }

            // compteur
            $this->nbr_commentaires = max(0, $this->nbr_commentaires - 1);
        }
        return $this;
    }
  public function updateNbrCommentaires(): self
{
    $this->nbr_commentaires = $this->commentaires->count();
    return $this;
}
}
