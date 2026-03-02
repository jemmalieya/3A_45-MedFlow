<?php

namespace App\Entity;

use App\Repository\ReclamationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: ReclamationRepository::class)]
class Reclamation
{


    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id_reclamation")]
    private ?int $id_reclamation = null;

    #[ORM\Column(length: 30)]
    private ?string $referenceReclamation = null;

    // ✅ CONTROLE SAISIE (PHP) : contenu
    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: "Le contenu est obligatoire.")]
    #[Assert\Length(
        min: 5,
        minMessage: "Le contenu doit contenir au moins {{ limit }} caractères.",
        max: 150,
        maxMessage: "Le contenu ne doit pas dépasser {{ limit }} caractères."
    )]
    private ?string $contenu = null;

    // ✅ CONTROLE SAISIE (PHP) : description
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: "La description est obligatoire.")]
    #[Assert\Length(
        min: 10,
        minMessage: "La description doit contenir au moins {{ limit }} caractères."
    )]
    private ?string $description = null;

    // ✅ CONTROLE SAISIE (PHP) : type
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: "Le type est obligatoire.")]
    #[Assert\Length(
        min: 3,
        minMessage: "Le type doit contenir au moins {{ limit }} caractères.",
        max: 50,
        maxMessage: "Le type ne doit pas dépasser {{ limit }} caractères."
    )]
    #[Assert\Regex(
        pattern: "/^[a-zA-ZÀ-ÿ0-9\s'_-]+$/u",
        message: "Le type contient des caractères non autorisés."
    )]
    private ?string $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pieceJointePath = null;

    #[ORM\Column(length: 20, nullable: true)]
private ?string $pieceJointeResourceType = null; // image | raw

#[ORM\Column(length: 10, nullable: true)]
private ?string $pieceJointeFormat = null; // pdf, png...

#[ORM\Column(nullable: true)]
private ?int $pieceJointeBytes = null;

#[ORM\Column(length: 255, nullable: true)]
private ?string $pieceJointeOriginalName = null;
// pieceJointeResourceType
public function getPieceJointeResourceType(): ?string
{
    return $this->pieceJointeResourceType;
}

public function setPieceJointeResourceType(?string $pieceJointeResourceType): self
{
    $this->pieceJointeResourceType = $pieceJointeResourceType;
    return $this;
}

// pieceJointeFormat
public function getPieceJointeFormat(): ?string
{
    return $this->pieceJointeFormat;
}

public function setPieceJointeFormat(?string $pieceJointeFormat): self
{
    $this->pieceJointeFormat = $pieceJointeFormat;
    return $this;
}

// pieceJointeBytes
public function getPieceJointeBytes(): ?int
{
    return $this->pieceJointeBytes;
}

public function setPieceJointeBytes(?int $pieceJointeBytes): self
{
    $this->pieceJointeBytes = $pieceJointeBytes;
    return $this;
}

// pieceJointeOriginalName
public function getPieceJointeOriginalName(): ?string
{
    return $this->pieceJointeOriginalName;
}

public function setPieceJointeOriginalName(?string $pieceJointeOriginalName): self
{
    $this->pieceJointeOriginalName = $pieceJointeOriginalName;
    return $this;
}
#[ORM\Column(type: 'text', nullable: true)]
private ?string $contenuOriginal = null;

#[ORM\Column(type: 'text', nullable: true)]
private ?string $descriptionOriginal = null;

#[ORM\Column(length: 10, nullable: true)]
private ?string $langueOriginale = null; // "en", "ar", "fr"

#[ORM\Column(type: 'text', nullable: true)]
private ?string $contenuFrancais = null;

#[ORM\Column(type: 'text', nullable: true)]
private ?string $descriptionFrancais = null;

#[ORM\Column(nullable: true)]
private ?int $urgenceScore = null; // 0..100

#[ORM\Column(length: 20, nullable: true)]
private ?string $sentiment = null; // "NEGATIVE"/"NEUTRAL"/"POSITIVE"

#[ORM\Column(type: 'datetime_immutable', nullable: true)]
private ?\DateTimeImmutable $translatedAt = null;

#[ORM\Column(type: 'datetime_immutable', nullable: true)]
private ?\DateTimeImmutable $analysisAt = null;

// ====== contenuOriginal ======
public function getContenuOriginal(): ?string
{
    return $this->contenuOriginal;
}

public function setContenuOriginal(?string $contenuOriginal): self
{
    $this->contenuOriginal = $contenuOriginal;
    return $this;
}

// ====== descriptionOriginal ======
public function getDescriptionOriginal(): ?string
{
    return $this->descriptionOriginal;
}

public function setDescriptionOriginal(?string $descriptionOriginal): self
{
    $this->descriptionOriginal = $descriptionOriginal;
    return $this;
}

// ====== langueOriginale ======
public function getLangueOriginale(): ?string
{
    return $this->langueOriginale;
}

public function setLangueOriginale(?string $langueOriginale): self
{
    $this->langueOriginale = $langueOriginale;
    return $this;
}

// ====== contenuFrancais ======
public function getContenuFrancais(): ?string
{
    return $this->contenuFrancais;
}

public function setContenuFrancais(?string $contenuFrancais): self
{
    $this->contenuFrancais = $contenuFrancais;
    return $this;
}

// ====== descriptionFrancais ======
public function getDescriptionFrancais(): ?string
{
    return $this->descriptionFrancais;
}

public function setDescriptionFrancais(?string $descriptionFrancais): self
{
    $this->descriptionFrancais = $descriptionFrancais;
    return $this;
}

// ====== urgenceScore ======
public function getUrgenceScore(): ?int
{
    return $this->urgenceScore;
}

public function setUrgenceScore(?int $urgenceScore): self
{
    $this->urgenceScore = $urgenceScore;
    return $this;
}

// ====== sentiment ======
public function getSentiment(): ?string
{
    return $this->sentiment;
}

public function setSentiment(?string $sentiment): self
{
    $this->sentiment = $sentiment;
    return $this;
}

// ====== translatedAt ======
public function getTranslatedAt(): ?\DateTimeImmutable
{
    return $this->translatedAt;
}

public function setTranslatedAt(?\DateTimeImmutable $translatedAt): self
{
    $this->translatedAt = $translatedAt;
    return $this;
}

// ====== analysisAt ======
public function getAnalysisAt(): ?\DateTimeImmutable
{
    return $this->analysisAt;
}

public function setAnalysisAt(?\DateTimeImmutable $analysisAt): self
{
    $this->analysisAt = $analysisAt;
    return $this;
}

    #[ORM\Column(length: 50)]
    private ?string $statutReclamation = null;

    #[ORM\Column(length: 50)]
    private ?string $priorite = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $date_limite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date_creation_r = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $date_modification_r = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $date_cloture_r = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'reclamations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    // Ajoute cette ligne dans l'entité Reclamation pour suivre l'état de la notification
#[ORM\Column(type: "boolean")]
private bool $notificationEnvoyee = false; // Par défaut, pas de notification envoyée

// Getter et Setter pour la notification
public function isNotificationEnvoyee(): bool
{
    return $this->notificationEnvoyee;
}

public function setNotificationEnvoyee(bool $notificationEnvoyee): static
{
    $this->notificationEnvoyee = $notificationEnvoyee;
    return $this;
}

    /**
     * @var Collection<int, ReponseReclamation>
     */
    #[ORM\OneToMany(targetEntity: ReponseReclamation::class, mappedBy: 'reclamation',cascade:["persist", "remove"], orphanRemoval: true)]
    private Collection $reponses;

    public function __construct()
    {
        $this->reponses = new ArrayCollection();

        // valeurs par défaut
        $this->date_creation_r = new \DateTimeImmutable();
        $this->statutReclamation = 'EN_ATTENTE';
        $this->priorite = 'NORMALE';
    }

    public function getIdReclamation(): ?int
    {
        return $this->id_reclamation;
    }

    public function getReferenceReclamation(): ?string
    {
        return $this->referenceReclamation;
    }

    public function setReferenceReclamation(string $referenceReclamation): static
    {
        $this->referenceReclamation = $referenceReclamation;
        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    // ✅ Trim côté PHP (anti espaces)
    public function setContenu(string $contenu): static
    {
        $this->contenu = trim($contenu);
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    // ✅ Trim côté PHP
    public function setDescription(string $description): static
    {
        $this->description = trim($description);
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    // ✅ Trim côté PHP
    public function setType(string $type): static
    {
        $this->type = trim($type);
        return $this;
    }

    public function getPieceJointePath(): ?string
    {
        return $this->pieceJointePath;
    }

    public function setPieceJointePath(?string $pieceJointePath): static
    {
        $this->pieceJointePath = $pieceJointePath;
        return $this;
    }

    public function getStatutReclamation(): ?string
    {
        return $this->statutReclamation;
    }

    public function setStatutReclamation(string $statutReclamation): static
    {
        $this->statutReclamation = $statutReclamation;
        return $this;
    }

    public function getPriorite(): ?string
    {
        return $this->priorite;
    }

    public function setPriorite(string $priorite): static
    {
        $this->priorite = $priorite;
        return $this;
    }

    public function getDateLimite(): ?\DateTimeImmutable
    {
        return $this->date_limite;
    }

    public function setDateLimite(?\DateTimeImmutable $date_limite): static
    {
        $this->date_limite = $date_limite;
        return $this;
    }

    public function getDateCreationR(): ?\DateTimeImmutable
    {
        return $this->date_creation_r;
    }

    public function setDateCreationR(\DateTimeImmutable $date_creation_r): static
    {
        $this->date_creation_r = $date_creation_r;
        return $this;
    }

    public function getDateModificationR(): ?\DateTimeImmutable
    {
        return $this->date_modification_r;
    }

    public function setDateModificationR(?\DateTimeImmutable $date_modification_r): static
    {
        $this->date_modification_r = $date_modification_r;
        return $this;
    }

    public function getDateClotureR(): ?\DateTimeImmutable
    {
        return $this->date_cloture_r;
    }

    public function setDateClotureR(?\DateTimeImmutable $date_cloture_r): static
    {
        $this->date_cloture_r = $date_cloture_r;
        return $this;
    }

    /**
     * @return Collection<int, ReponseReclamation>
     */
    public function getReponses(): Collection
    {
        return $this->reponses;
    }

    public function addReponse(ReponseReclamation $reponse): static
    {
        if (!$this->reponses->contains($reponse)) {
            $this->reponses->add($reponse);
            $reponse->setReclamation($this);
        }

        return $this;
    }

    public function removeReponse(ReponseReclamation $reponse): static
    {
        if ($this->reponses->removeElement($reponse)) {
            if ($reponse->getReclamation() === $this) {
                $reponse->setReclamation(null);
            }
        }

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

#[ORM\PrePersist]
public function setDatesAutomatiquement(): void
{
    // Date de création
    if ($this->date_creation_r === null) {
        $this->date_creation_r = new \DateTimeImmutable();
    }
     if (
        $this->statutReclamation === 'TRAITEE'
        && $this->date_cloture_r === null
    ) {
        $this->date_cloture_r = new \DateTimeImmutable();
    }
    // ✅ Date limite = +48h (2 jours)
    if ($this->date_limite === null) {
        $this->date_limite = $this->date_creation_r->modify('+48 hours');
    }
}

public function isUrgente(): bool
{
    if ($this->date_limite === null) {
        return false;
    }

    $now = new \DateTimeImmutable();
    $interval = $now->diff($this->date_limite);

    // date dépassée OU ≤ 3 jours
    return $interval->invert === 1 || $interval->days <= 3;
}

#[ORM\PreUpdate]
public function onUpdate(): void
{
    $this->date_modification_r = new \DateTimeImmutable();
}

public function updatePrioriteFromSentiment(): self
{
    // Normalisation
    $score = max(0, min(100, (int)($this->urgenceScore ?? 0)));
    $sent  = strtoupper(trim((string)($this->sentiment ?? 'NEUTRAL')));

    /**
     * Règles PRO (simple + défendable en soutenance)
     * - Sentiment NEGATIVE augmente fortement la priorité
     * - Sentiment POSITIVE diminue (si pas urgent)
     * - Score d’urgence détermine le niveau final
     */

    // Base score selon émotion
    $emotionBoost = match ($sent) {
        'NEGATIVE' => 20,
        'POSITIVE' => -10,
        default    => 0, // NEUTRAL ou inconnu
    };

    $final = max(0, min(100, $score + $emotionBoost));

    // Mapping score -> priorité
    if ($final >= 75)      $this->priorite = 'CRITIQUE';
    elseif ($final >= 45)  $this->priorite = 'ELEVEE';
    elseif ($final >= 15)  $this->priorite = 'NORMALE';
    else                   $this->priorite = 'BASSE';

    return $this;
}

}