<?php
namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;

#[UniqueEntity(fields: ['emailUser'], message: 'Cet email est déjà utilisé.')]
#[UniqueEntity(fields: ['cin'], message: 'Ce CIN est déjà utilisé.')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[Assert\NotBlank(message: "Le mot de passe est obligatoire.", groups: ["registration"])]
    #[Assert\Length(min: 8, minMessage: "Le mot de passe doit contenir au moins {{ limit }} caractères.", groups: ["registration"])]
    #[Assert\Regex(pattern: "/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[@$!%*?&]).{8,}$/", message: "Le mot de passe doit contenir: lettres (minuscules + majuscules), chiffres et caractères spéciaux (@$!%*?&).", groups: ["registration"])]
    private ?string $plainPassword = null;

    #[ORM\Column(length: 255, nullable: true)]
private ?string $googleId = null;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8, unique: true)]
    #[Assert\NotBlank(message: "Le CIN est obligatoire.")]
    #[Assert\Regex(pattern: "/^\d{8}$/", message: "Le CIN doit contenir exactement 8 chiffres.")]
    private ?string $cin = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $profilePicture = null;

   #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: "Le nom est obligatoire.")]
    #[Assert\Length(max: 100, maxMessage: "Le nom ne doit pas dépasser {{ limit }} caractères.")]
    #[Assert\Regex(pattern: "/^[a-zA-ZÀ-ÿ\s'-]+$/", message: "Le nom ne doit contenir que des lettres.")]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: "Le prénom est obligatoire.")]
    #[Assert\Length(max: 100, maxMessage: "Le prénom ne doit pas dépasser {{ limit }} caractères.")]
    #[Assert\Regex(pattern: "/^[a-zA-ZÀ-ÿ\s'-]+$/", message: "Le prénom ne doit contenir que des lettres.")]
    private ?string $prenom = null;
   #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: "La date de naissance est obligatoire.")]
    private ?\DateTimeInterface $dateNaissance = null;



   #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: "Le téléphone est obligatoire.")]
    #[Assert\Regex(
        pattern: "/^\+?\d{8,15}$/",
        message: "Téléphone invalide (ex: 54430709 ou +21654430709)."
    )]
    private ?string $telephoneUser = null;


    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: "L'email est obligatoire.")]
    #[Assert\Email(message: "Email invalide.")]
    private ?string $emailUser = null;

   #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180, maxMessage: "L'adresse ne doit pas dépasser {{ limit }} caractères.")]
    private ?string $adresseUser = null;
    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $derniereConnexion = null;

    #[ORM\Column]
    private bool $isVerified = false;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $statutCompte = null;
    
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $banReason = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $bannedAt = null;
        // ===== Sécurité / rôles système =====

    #[ORM\Column(length: 20)]
    private string $roleSysteme = 'PATIENT'; // PATIENT | STAFF_MEDICAL | ADMIN

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $typeStaff = null; // ex: MEDECIN, INFIRMIER...

    // ===== Vérification email =====
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $verificationToken = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $tokenExpiresAt = null;

    // ===== Réinitialisation de mot de passe =====
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $resetTokenExpiresAt = null;

    // ===== Demande staff (Option C sans nouvelle table) =====
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $staffRequestStatus = null; // PENDING | APPROVED | REJECTED

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $staffRequestType = null; // type staff demandé

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $staffRequestMessage = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $staffRequestedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $staffReviewedAt = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $staffRequestProofPath = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $staffDocuments = null; // Professional: metadata + files for staff request
    
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $staffRequestReason = null;

#[ORM\OneToMany(mappedBy: 'user', targetEntity: Post::class)]
private Collection $posts;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Commentaire::class, cascade: ['persist', 'remove'])]
    private Collection $commentaires;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Reclamation::class, cascade: ['persist', 'remove'])]
    private Collection $reclamations;


    #[ORM\Column(nullable: true)]
    private ?int $staffReviewedBy = null; // id admin (simple)

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Commande::class)]
    private Collection $commandes;
    
    public function __construct()
    {
        $this->commandes = new ArrayCollection();
            
        $this->posts = new ArrayCollection();
        $this->commentaires = new ArrayCollection();
        $this->reclamations = new ArrayCollection();
    }
    
    public function getCommandes(): Collection
    {
        return $this->commandes;
    }
    public function getProfilePicture(): ?string
    {
        return $this->profilePicture;
    }

    public function setProfilePicture(?string $profilePicture): self
    {
        $this->profilePicture = $profilePicture;
        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCin(): ?string
    {
        return $this->cin;
    }

    public function setCin(string $cin): self
    {
        $this->cin = $cin;
        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): self
    {
        $this->prenom = $prenom;
        return $this;
    }

    public function getDateNaissance(): ?\DateTimeInterface
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(\DateTimeInterface $dateNaissance): self
    {
        $this->dateNaissance = $dateNaissance;
        return $this;
    }

    public function getTelephoneUser(): ?string
    {
        return $this->telephoneUser;
    }

    public function setTelephoneUser(string $telephoneUser): self
    {
        $this->telephoneUser = $telephoneUser;
        return $this;
    }

    public function getEmailUser(): ?string
    {
        return $this->emailUser;
    }

    public function setEmailUser(string $emailUser): self
    {
        $this->emailUser = $emailUser;
        return $this;
    }

    public function getAdresseUser(): ?string
    {
        return $this->adresseUser;
    }

    public function setAdresseUser(?string $adresseUser): self
    {
        $this->adresseUser = $adresseUser;
        return $this;
    }

    public function getStatutCompte(): ?string
    {
        return $this->statutCompte;
    }

    public function setStatutCompte(?string $statutCompte): self
    {
        $this->statutCompte = $statutCompte;
        return $this;
    }

    public function getBanReason(): ?string
    {
        return $this->banReason;
    }

    public function setBanReason(?string $banReason): self
    {
        $this->banReason = $banReason;
        return $this;
    }

    public function getBannedAt(): ?\DateTimeInterface
    {
        return $this->bannedAt;
    }

    public function setBannedAt(?\DateTimeInterface $bannedAt): self
    {
        $this->bannedAt = $bannedAt;
        return $this;
    }

    public function getDerniereConnexion(): ?\DateTimeInterface
    {
        return $this->derniereConnexion;
    }

    public function setDerniereConnexion(?\DateTimeInterface $derniereConnexion): self
    {
        $this->derniereConnexion = $derniereConnexion;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->emailUser;
    }

       public function getRoles(): array
{
    $roles = ['ROLE_USER'];

    // 1) Rôle système principal
    if ($this->roleSysteme === 'ADMIN') {
        $roles[] = 'ROLE_ADMIN';
    } elseif ($this->roleSysteme === 'STAFF') {
        $roles[] = 'ROLE_STAFF';

        // 2) Spécialité staff (type_staff)
        // (utile pour la suite : MEDECIN, INFIRMIER, RESP_PATIENTS, etc.)
        if ($this->typeStaff) {
            $roles[] = 'ROLE_' . strtoupper($this->typeStaff);
        }
    } else {
        // PATIENT
        $roles[] = 'ROLE_PATIENT';
    }
    

    return array_values(array_unique($roles));
}




    public function eraseCredentials(): void
    {
        // Clear sensitive data if needed
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;
        return $this;
    }
        public function getRoleSysteme(): string
    {
        return $this->roleSysteme;
    }

    public function setRoleSysteme(string $role): self
    {
        $this->roleSysteme = $role;
        return $this;
    }

    public function getTypeStaff(): ?string
    {
        return $this->typeStaff;
    }

    public function setTypeStaff(?string $typeStaff): self
    {
        $this->typeStaff = $typeStaff;
        return $this;
    }

    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(?string $verificationToken): self
    {
        $this->verificationToken = $verificationToken;
        return $this;
    }

    public function getTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeInterface $tokenExpiresAt): self
    {
        $this->tokenExpiresAt = $tokenExpiresAt;
        return $this;
    }

    public function getStaffRequestStatus(): ?string
    {
        return $this->staffRequestStatus;
    }

    public function setStaffRequestStatus(?string $staffRequestStatus): self
    {
        $this->staffRequestStatus = $staffRequestStatus;
        return $this;
    }

    public function getStaffRequestType(): ?string
    {
        return $this->staffRequestType;
    }

    public function setStaffRequestType(?string $staffRequestType): self
    {
        $this->staffRequestType = $staffRequestType;
        return $this;
    }

    public function getStaffRequestMessage(): ?string
    {
        return $this->staffRequestMessage;
    }

    public function setStaffRequestMessage(?string $staffRequestMessage): self
    {
        $this->staffRequestMessage = $staffRequestMessage;
        return $this;
    }

    public function getStaffRequestedAt(): ?\DateTimeInterface
    {
        return $this->staffRequestedAt;
    }

    public function setStaffRequestedAt(?\DateTimeInterface $staffRequestedAt): self
    {
        $this->staffRequestedAt = $staffRequestedAt;
        return $this;
    }

    public function getStaffReviewedAt(): ?\DateTimeInterface
    {
        return $this->staffReviewedAt;
    }

    public function setStaffReviewedAt(?\DateTimeInterface $staffReviewedAt): self
    {
        $this->staffReviewedAt = $staffReviewedAt;
        return $this;
    }

    public function getStaffReviewedBy(): ?int
    {
        return $this->staffReviewedBy;
    }

    public function setStaffReviewedBy(?int $staffReviewedBy): self
    {
        $this->staffReviewedBy = $staffReviewedBy;
        return $this;
    }

    public function getStaffRequestProofPath(): ?string
    {
        return $this->staffRequestProofPath;
    }

    public function setStaffRequestProofPath(?string $path): self
    {
        $this->staffRequestProofPath = $path;
        return $this;
    }

    public function getStaffDocuments(): ?array
    {
        return $this->staffDocuments;
    }

    public function setStaffDocuments(?array $docs): self
    {
        $this->staffDocuments = $docs;
        return $this;
    }

    public function getStaffRequestReason(): ?string
    {
        return $this->staffRequestReason;
    }

    public function setStaffRequestReason(?string $reason): self
    {
        $this->staffRequestReason = $reason;
        return $this;
    }

     /**
     * @return Collection<int, Post>
     */
    public function getPosts(): Collection
    {
        return $this->posts;
    }

    public function addPost(Post $post): static
    {
        if (!$this->posts->contains($post)) {
            $this->posts->add($post);
            $post->setUser($this);
        }
        return $this;
    }

    public function removePost(Post $post): static
    {
        if ($this->posts->removeElement($post)) {
            if ($post->getUser() === $this) {
                $post->setUser(null);
            }
        }
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
            $commentaire->setUser($this);
        }

        return $this;
    }

    public function removeCommentaire(Commentaire $commentaire): static
    {
        if ($this->commentaires->removeElement($commentaire)) {
            if ($commentaire->getUser() === $this) {
                $commentaire->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Reclamation>
     */
    public function getReclamations(): Collection
    {
        return $this->reclamations;
    }

    public function addReclamation(Reclamation $reclamation): static
    {
        if (!$this->reclamations->contains($reclamation)) {
            $this->reclamations->add($reclamation);
            $reclamation->setUser($this);
        }

        return $this;
    }

    public function removeReclamation(Reclamation $reclamation): static
    {
        if ($this->reclamations->removeElement($reclamation)) {
            if ($reclamation->getUser() === $this) {
                $reclamation->setUser(null);
            }
        }

        return $this;
    }
    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): self
    {
        $this->resetToken = $resetToken;
        return $this;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeInterface $resetTokenExpiresAt): self
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
        return $this;
    }

    // In your User entity, add the method
public function setGoogleId(?string $googleId): self
{
    $this->googleId = $googleId;
    return $this;
}



    
}