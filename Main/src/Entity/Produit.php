<?php

namespace App\Entity;

use App\Repository\ProduitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProduitRepository::class)]
class Produit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id_produit = null;

    // ✅ Nom du produit
    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: "Le nom du produit est obligatoire.")]
    #[Assert\Length(
        min: 3,
        max: 150,
        minMessage: "Le nom doit contenir au moins {{ limit }} caractères.",
        maxMessage: "Le nom ne peut pas dépasser {{ limit }} caractères."
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-ZàâäéèêëïîôùûüÿçÀÂÄÉÈÊËÏÎÔÙÛÜŸÇ\s\-]+$/u',
        message: "Le nom ne peut contenir que des lettres, espaces et tirets."
    )]
    private ?string $nom_produit = null;

    // ✅ Description
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "La description est obligatoire.")]
    #[Assert\Length(
        min: 10,
        max: 255,
        minMessage: "La description doit contenir au moins {{ limit }} caractères.",
        maxMessage: "La description ne peut pas dépasser {{ limit }} caractères."
    )]
    private ?string $description_produit = null;

    // ✅ Prix
    #[ORM\Column]
    #[Assert\NotNull(message: "Le prix est obligatoire.")]
    #[Assert\Positive(message: "Le prix doit être positif.")]
    #[Assert\Range(
        min: 0.01,
        max: 999999.99,
        notInRangeMessage: "Le prix doit être entre {{ min }} et {{ max }} DT."
    )]
    private ?float $prix_produit = null;

    // ✅ Quantité
    #[ORM\Column]
    #[Assert\NotNull(message: "La quantité est obligatoire.")]
    #[Assert\PositiveOrZero(message: "La quantité ne peut pas être négative.")]
    #[Assert\Range(
        min: 0,
        max: 100000,
        notInRangeMessage: "La quantité doit être entre {{ min }} et {{ max }}."
    )]
    #[Assert\Type(
        type: 'integer',
        message: "La quantité doit être un nombre entier."
    )]
    private ?int $quantite_produit = null;

    // ✅ Image (URL)
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "L'URL de l'image est obligatoire.")]
    #[Assert\Url(
        message: "L'URL de l'image n'est pas valide. Elle doit commencer par http:// ou https://",
        protocols: ['http', 'https']
    )]
    #[Assert\Length(
        max: 255,
        maxMessage: "L'URL ne peut pas dépasser {{ limit }} caractères."
    )]
    private ?string $image_produit = null;

    // ✅ Catégorie
    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: "La catégorie est obligatoire.")]
    #[Assert\Choice(
        choices: [
            'Médicaments',
            'Vitamines & Compléments',
            'Soins & Hygiène',
            'Matériel médical',
            'Pansements & Bandages',
            'Premiers soins',
            'Nutrition & Diététique',
            'Bébé & Maman',
            'Beauté & Cosmétique',
            'Accessoires'
        ],
        message: "Veuillez sélectionner une catégorie valide."
    )]
    private ?string $categorie_produit = null;

    // ✅ Statut
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: "Le statut est obligatoire.")]
    #[Assert\Choice(
        choices: ['Disponible', 'Rupture', 'Indisponible'],
        message: "Le statut doit être : Disponible, Rupture ou Indisponible."
    )]
    private ?string $status_produit = null;

    /**
     * @var Collection<int, LigneCommande>
     */
    #[ORM\OneToMany(targetEntity: LigneCommande::class, mappedBy: 'produit', cascade: ['remove'], orphanRemoval: true)]
    private Collection $ligne_commandes;

    public function __construct()
    {
        $this->ligne_commandes = new ArrayCollection();
    }

    public function getId_produit(): ?int
    {
        return $this->id_produit;
    }

    public function getNomProduit(): ?string
    {
        return $this->nom_produit;
    }

    // ✅ accepte null pour éviter crash en edit
    public function setNomProduit(?string $nom_produit): static
    {
        $this->nom_produit = $nom_produit !== null ? trim($nom_produit) : null;
        return $this;
    }

    public function getDescriptionProduit(): ?string
    {
        return $this->description_produit;
    }

    // ✅ accepte null pour éviter crash en edit
    public function setDescriptionProduit(?string $description_produit): static
    {
        $this->description_produit = $description_produit !== null ? trim($description_produit) : null;
        return $this;
    }

    public function getPrixProduit(): ?float
    {
        return $this->prix_produit;
    }

    // ✅ FIX: accepter null pour éviter "float, null given" en edit
    public function setPrixProduit(?float $prix_produit): static
    {
        $this->prix_produit = ($prix_produit !== null) ? round($prix_produit, 2) : null;
        return $this;
    }

    public function getQuantiteProduit(): ?int
    {
        return $this->quantite_produit;
    }

    // ✅ FIX: accepter null pour éviter "int, null given" en edit
    public function setQuantiteProduit(?int $quantite_produit): static
    {
        $this->quantite_produit = $quantite_produit;
        return $this;
    }

    public function getImageProduit(): ?string
    {
        return $this->image_produit;
    }

    public function setImageProduit(?string $image_produit): static
    {
        $this->image_produit = $image_produit !== null ? trim($image_produit) : null;
        return $this;
    }

    public function getCategorieProduit(): ?string
    {
        return $this->categorie_produit;
    }

    // ✅ FIX IMPORTANT: accepter null
    public function setCategorieProduit(?string $categorie_produit): static
    {
        $this->categorie_produit = $categorie_produit !== null ? trim($categorie_produit) : null;
        return $this;
    }

    public function getStatusProduit(): ?string
    {
        return $this->status_produit;
    }

    // ✅ FIX IMPORTANT: accepter null
    public function setStatusProduit(?string $status_produit): static
    {
        $this->status_produit = $status_produit !== null ? trim($status_produit) : null;
        return $this;
    }

    public function getLigneCommandes(): Collection
    {
        return $this->ligne_commandes;
    }

    public function addLigneCommande(LigneCommande $ligneCommande): static
    {
        if (!$this->ligne_commandes->contains($ligneCommande)) {
            $this->ligne_commandes->add($ligneCommande);
            $ligneCommande->setProduit($this);
        }
        return $this;
    }

    public function removeLigneCommande(LigneCommande $ligneCommande): static
    {
        if ($this->ligne_commandes->removeElement($ligneCommande)) {
            if ($ligneCommande->getProduit() === $this) {
                $ligneCommande->setProduit(null);
            }
        }
        return $this;
    }
}
