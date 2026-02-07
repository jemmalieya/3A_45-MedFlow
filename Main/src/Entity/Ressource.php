<?php

namespace App\Entity;

use App\Repository\RessourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Evenement;



#[ORM\Entity(repositoryClass: RessourceRepository::class)]
#[ORM\Table(name: 'ressource')]
#[ORM\HasLifecycleCallbacks] 
class Ressource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;


    // 🔗 Plusieurs ressources appartiennent à un seul événement
    #[ORM\ManyToOne(inversedBy: 'ressources')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Evenement $evenement = null;

    #[ORM\Column(length: 255)]
    private ?string $nom_ressource = null;

    #[ORM\Column(length: 50)]
    private ?string $categorie_ressource = null;

    // file | external_link | stock_item
    #[ORM\Column(length: 30)]
    private ?string $type_ressource = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $chemin_fichier_ressource = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url_externe_ressource = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mime_type_ressource = null;

    #[ORM\Column(nullable: true)]
    private ?int $taille_kb_ressource = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantite_disponible_ressource = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $unite_ressource = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fournisseur_ressource = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, nullable: true)]
    private ?string $cout_estime_ressource = null;

    #[ORM\Column]
    private bool $est_publique_ressource = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes_ressource = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $date_creation_ressource = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $date_mise_a_jour_ressource = null;

    public function __construct()
{
    $this->est_publique_ressource = true;
}


    // ================= GETTERS / SETTERS =================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvenement(): ?Evenement
    {
        return $this->evenement;
    }

   public function setEvenement(?Evenement $evenement): static
   {
     $this->evenement = $evenement;
      return $this;
   }



    public function getNomRessource(): ?string
    {
        return $this->nom_ressource;
    }

    public function setNomRessource(string $nom): static
    {
        $this->nom_ressource = $nom;
        return $this;
    }

    public function getCategorieRessource(): ?string
    {
        return $this->categorie_ressource;
    }

    public function setCategorieRessource(string $categorie): static
    {
        $this->categorie_ressource = $categorie;
        return $this;
    }

    public function getTypeRessource(): ?string
    {
        return $this->type_ressource;
    }

    public function setTypeRessource(string $type): static
    {
        $this->type_ressource = $type;
        return $this;
    }

    public function getCheminFichierRessource(): ?string
    {
        return $this->chemin_fichier_ressource;
    }

    public function setCheminFichierRessource(?string $chemin): static
    {
        $this->chemin_fichier_ressource = $chemin;
        return $this;
    }

    public function getUrlExterneRessource(): ?string
    {
        return $this->url_externe_ressource;
    }

    public function setUrlExterneRessource(?string $url): static
    {
        $this->url_externe_ressource = $url;
        return $this;
    }

    public function getMimeTypeRessource(): ?string
    {
        return $this->mime_type_ressource;
    }

    public function setMimeTypeRessource(?string $mime): static
    {
        $this->mime_type_ressource = $mime;
        return $this;
    }

    public function getTailleKbRessource(): ?int
    {
        return $this->taille_kb_ressource;
    }

    public function setTailleKbRessource(?int $taille): static
    {
        $this->taille_kb_ressource = $taille;
        return $this;
    }

    public function getQuantiteDisponibleRessource(): ?int
    {
        return $this->quantite_disponible_ressource;
    }

    public function setQuantiteDisponibleRessource(?int $qte): static
    {
        $this->quantite_disponible_ressource = $qte;
        return $this;
    }

    public function getUniteRessource(): ?string
    {
        return $this->unite_ressource;
    }

    public function setUniteRessource(?string $unite): static
    {
        $this->unite_ressource = $unite;
        return $this;
    }

    public function getFournisseurRessource(): ?string
    {
        return $this->fournisseur_ressource;
    }

    public function setFournisseurRessource(?string $fournisseur): static
    {
        $this->fournisseur_ressource = $fournisseur;
        return $this;
    }

    public function getCoutEstimeRessource(): ?string
    {
        return $this->cout_estime_ressource;
    }

    public function setCoutEstimeRessource(?string $cout): static
    {
        $this->cout_estime_ressource = $cout;
        return $this;
    }

    public function isEstPubliqueRessource(): bool
    {
        return $this->est_publique_ressource;
    }

    public function setEstPubliqueRessource(bool $val): static
    {
        $this->est_publique_ressource = $val;
        return $this;
    }

    public function getNotesRessource(): ?string
    {
        return $this->notes_ressource;
    }

    public function setNotesRessource(?string $notes): static
    {
        $this->notes_ressource = $notes;
        return $this;
    }

    public function getDateCreationRessource(): ?\DateTimeImmutable
    {
        return $this->date_creation_ressource;
    }

    public function getDateMiseAJourRessource(): ?\DateTimeImmutable
    {
        return $this->date_mise_a_jour_ressource;
    }

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
