<?php

namespace App\Entity;

use App\Repository\RessourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use App\Entity\Evenement;

#[ORM\Entity(repositoryClass: RessourceRepository::class)]
#[ORM\Table(name: 'ressource')]
#[ORM\HasLifecycleCallbacks]
#[Assert\Callback('validateByType')]
class Ressource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // 🔗 Plusieurs ressources appartiennent à un seul événement
    #[ORM\ManyToOne(inversedBy: 'ressources')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: "Veuillez choisir un événement.")]
    private ?Evenement $evenement = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "Le nom de la ressource est obligatoire.")]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: "Le nom doit contenir au moins {{ limit }} caractères.",
        maxMessage: "Le nom ne doit pas dépasser {{ limit }} caractères."
    )]
    #[Assert\Regex(
        pattern: "/^(?!.*(.)\1{5,}).+$/u",
        message: "Nom invalide (trop de répétitions)."
    )]
    private ?string $nom_ressource = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: "La catégorie est obligatoire.")]
    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: "La catégorie doit contenir au moins {{ limit }} caractères.",
        maxMessage: "La catégorie ne doit pas dépasser {{ limit }} caractères."
    )]
    #[Assert\Regex(
        pattern: "/^[\p{L}\p{N}\s\-_']+$/u",
        message: "Catégorie invalide (lettres/chiffres/espaces uniquement)."
    )]
    private ?string $categorie_ressource = null;

    // file | external_link | stock_item
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: "Le type est obligatoire.")]
    #[Assert\Choice(choices: ['file', 'external_link', 'stock_item'], message: "Type invalide.")]
    private ?string $type_ressource = null;

    // ===== FILE =====
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: "Le chemin ne doit pas dépasser {{ limit }} caractères.")]
    private ?string $chemin_fichier_ressource = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: "Le mime type ne doit pas dépasser {{ limit }} caractères.")]
    #[Assert\Regex(
        pattern: "/^[a-z0-9\-\.\+]+\/[a-z0-9\-\.\+]+$/i",
        message: "Mime type invalide (ex: application/pdf)."
    )]
    private ?string $mime_type_ressource = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: "La taille (KB) doit être >= 0.")]
    private ?int $taille_kb_ressource = null;

    // ===== LINK =====
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: "L'URL ne doit pas dépasser {{ limit }} caractères.")]
    #[Assert\Url(message: "Veuillez saisir une URL valide (ex: https://...).")]
    private ?string $url_externe_ressource = null;

    // ===== STOCK =====
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: "La quantité doit être >= 0.")]
    private ?int $quantite_disponible_ressource = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, maxMessage: "L'unité ne doit pas dépasser {{ limit }} caractères.")]
    #[Assert\Regex(
        pattern: "/^[\p{L}\s\.\-]+$/u",
        message: "Unité invalide (lettres/espaces uniquement)."
    )]
    private ?string $unite_ressource = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: "Le fournisseur ne doit pas dépasser {{ limit }} caractères.")]
    #[Assert\Regex(
        pattern: "/^[\p{L}\p{N}\s\-_'.]+$/u",
        message: "Fournisseur invalide."
    )]
    private ?string $fournisseur_ressource = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, nullable: true)]
    #[Assert\Regex(
        pattern: "/^\d{1,7}(\.\d{1,3})?$/",
        message: "Coût invalide (ex: 120.500)."
    )]
    private ?string $cout_estime_ressource = null;
    
    #[ORM\Column(length: 255, nullable: true)]
   private ?string $cloudinary_public_id = null;

  public function getCloudinaryPublicId(): ?string { return $this->cloudinary_public_id; }
   public function setCloudinaryPublicId(?string $id): static { $this->cloudinary_public_id = $id; return $this; }
    // ===== OTHER =====
    
    #[ORM\Column]
    private bool $est_publique_ressource = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: "Les notes ne doivent pas dépasser {{ limit }} caractères.")]
    private ?string $notes_ressource = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $date_creation_ressource = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $date_mise_a_jour_ressource = null;

    #[ORM\Column(length: 255, nullable: true)]
private ?string $signature_url = null;

#[ORM\Column(length: 255, nullable: true)]
private ?string $signature_public_id = null;

public function getSignatureUrl(): ?string { return $this->signature_url; }
public function setSignatureUrl(?string $url): static { $this->signature_url = $url; return $this; }

public function getSignaturePublicId(): ?string { return $this->signature_public_id; }
public function setSignaturePublicId(?string $id): static { $this->signature_public_id = $id; return $this; }

    public function __construct()
    {
        $this->est_publique_ressource = true;
    }

    // ================= VALIDATION DYNAMIQUE =================
    public function validateByType(ExecutionContextInterface $context): void
    {
        $type = $this->type_ressource;

        // ✅ FILE
     // ✅ FILE
if ($type === 'file') {

    // 1) Interdire URL externe + champs stock
    if ($this->url_externe_ressource) {
        $context->buildViolation("L'URL externe doit être vide pour le type Fichier.")
            ->atPath('url_externe_ressource')->addViolation();
    }

    if ($this->quantite_disponible_ressource !== null || $this->unite_ressource || $this->fournisseur_ressource || $this->cout_estime_ressource) {
        $context->buildViolation("Les champs Stock doivent être vides pour le type Fichier.")
            ->atPath('quantite_disponible_ressource')->addViolation();
    }

    // 2) IMPORTANT : ne pas bloquer la soumission si chemin est vide,
    // car il sera rempli après upload Cloudinary dans le controller.
    // On ne valide le format que si un chemin existe déjà (édition / données existantes).
    if ($this->chemin_fichier_ressource) {

        // Accepte Cloudinary (https://...) ou /uploads/...
        $isHttp = preg_match('#^https?://#i', $this->chemin_fichier_ressource);
        $isUploads = preg_match('#^/?uploads/#i', $this->chemin_fichier_ressource);

        if (!$isHttp && !$isUploads) {
            $context->buildViolation("Chemin invalide. Doit être une URL (Cloudinary) ou /uploads/...")
                ->atPath('chemin_fichier_ressource')->addViolation();
        }
    }
}

        // ✅ LINK
        if ($type === 'external_link') {
            if (!$this->url_externe_ressource) {
                $context->buildViolation("L'URL externe est obligatoire pour le type Lien.")
                    ->atPath('url_externe_ressource')->addViolation();
            }

            if ($this->chemin_fichier_ressource) {
                $context->buildViolation("Le chemin fichier doit être vide pour le type Lien.")
                    ->atPath('chemin_fichier_ressource')->addViolation();
            }

            // champs file/stock doivent être vides
            if ($this->mime_type_ressource || $this->taille_kb_ressource !== null) {
                $context->buildViolation("Mime type et taille doivent être vides pour le type Lien.")
                    ->atPath('mime_type_ressource')->addViolation();
            }
            if ($this->quantite_disponible_ressource !== null || $this->unite_ressource || $this->fournisseur_ressource || $this->cout_estime_ressource) {
                $context->buildViolation("Les champs Stock doivent être vides pour le type Lien.")
                    ->atPath('quantite_disponible_ressource')->addViolation();
            }
        }

        // ✅ STOCK
        if ($type === 'stock_item') {
            if ($this->quantite_disponible_ressource === null) {
                $context->buildViolation("La quantité est obligatoire pour le type Stock.")
                    ->atPath('quantite_disponible_ressource')->addViolation();
            }

            // Ici tu as demandé "tous les champs contrôlés" => unité obligatoire
            if (!$this->unite_ressource) {
                $context->buildViolation("L'unité est obligatoire pour le type Stock (ex: pcs, boîte...).")
                    ->atPath('unite_ressource')->addViolation();
            }

            // Interdire URL / chemin (stock n’est ni fichier ni lien)
            if ($this->chemin_fichier_ressource) {
                $context->buildViolation("Le chemin fichier doit être vide pour le type Stock.")
                    ->atPath('chemin_fichier_ressource')->addViolation();
            }
            if ($this->url_externe_ressource) {
                $context->buildViolation("L'URL externe doit être vide pour le type Stock.")
                    ->atPath('url_externe_ressource')->addViolation();
            }
            if ($this->mime_type_ressource || $this->taille_kb_ressource !== null) {
                $context->buildViolation("Mime type et taille doivent être vides pour le type Stock.")
                    ->atPath('mime_type_ressource')->addViolation();
            }

            // Coût : si renseigné, doit être >= 0 (regex déjà ok, on double en logique)
            if ($this->cout_estime_ressource !== null && is_numeric($this->cout_estime_ressource) && (float)$this->cout_estime_ressource < 0) {
                $context->buildViolation("Le coût ne peut pas être négatif.")
                    ->atPath('cout_estime_ressource')->addViolation();
            }
        }
    }

    // ================= GETTERS / SETTERS =================

    public function getId(): ?int { return $this->id; }

    public function getEvenement(): ?Evenement { return $this->evenement; }
    public function setEvenement(?Evenement $evenement): static { $this->evenement = $evenement; return $this; }

    public function getNomRessource(): ?string { return $this->nom_ressource; }
    public function setNomRessource(string $nom): static { $this->nom_ressource = $nom; return $this; }

    public function getCategorieRessource(): ?string { return $this->categorie_ressource; }
    public function setCategorieRessource(string $categorie): static { $this->categorie_ressource = $categorie; return $this; }

    public function getTypeRessource(): ?string { return $this->type_ressource; }
    public function setTypeRessource(string $type): static { $this->type_ressource = $type; return $this; }

    public function getCheminFichierRessource(): ?string { return $this->chemin_fichier_ressource; }
    public function setCheminFichierRessource(?string $chemin): static { $this->chemin_fichier_ressource = $chemin; return $this; }

    public function getUrlExterneRessource(): ?string { return $this->url_externe_ressource; }
    public function setUrlExterneRessource(?string $url): static { $this->url_externe_ressource = $url; return $this; }

    public function getMimeTypeRessource(): ?string { return $this->mime_type_ressource; }
    public function setMimeTypeRessource(?string $mime): static { $this->mime_type_ressource = $mime; return $this; }

    public function getTailleKbRessource(): ?int { return $this->taille_kb_ressource; }
    public function setTailleKbRessource(?int $taille): static { $this->taille_kb_ressource = $taille; return $this; }

    public function getQuantiteDisponibleRessource(): ?int { return $this->quantite_disponible_ressource; }
    public function setQuantiteDisponibleRessource(?int $qte): static { $this->quantite_disponible_ressource = $qte; return $this; }

    public function getUniteRessource(): ?string { return $this->unite_ressource; }
    public function setUniteRessource(?string $unite): static { $this->unite_ressource = $unite; return $this; }

    public function getFournisseurRessource(): ?string { return $this->fournisseur_ressource; }
    public function setFournisseurRessource(?string $fournisseur): static { $this->fournisseur_ressource = $fournisseur; return $this; }

    public function getCoutEstimeRessource(): ?string { return $this->cout_estime_ressource; }
    public function setCoutEstimeRessource(?string $cout): static { $this->cout_estime_ressource = $cout; return $this; }

    public function isEstPubliqueRessource(): bool { return $this->est_publique_ressource; }
    public function setEstPubliqueRessource(bool $val): static { $this->est_publique_ressource = $val; return $this; }

    public function getNotesRessource(): ?string { return $this->notes_ressource; }
    public function setNotesRessource(?string $notes): static { $this->notes_ressource = $notes; return $this; }

    public function getDateCreationRessource(): ?\DateTimeImmutable { return $this->date_creation_ressource; }
    public function getDateMiseAJourRessource(): ?\DateTimeImmutable { return $this->date_mise_a_jour_ressource; }

    public function setDateMiseAJourRessource(\DateTimeImmutable $date): static
    {
        $this->date_mise_a_jour_ressource = $date;
        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->date_creation_ressource = $now;
        $this->date_mise_a_jour_ressource = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->date_mise_a_jour_ressource = new \DateTimeImmutable();
    }
}
