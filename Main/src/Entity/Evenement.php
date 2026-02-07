<?php

namespace App\Entity;

use App\Repository\EvenementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Regex;


#[ORM\Entity(repositoryClass: EvenementRepository::class)]
#[ORM\Table(name: 'evenement')]
class Evenement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $demandes_json = null;

    // ✅ Relation: 1 événement -> N ressources
    #[ORM\OneToMany(mappedBy: 'evenement', targetEntity: Ressource::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $ressources;

   #[ORM\Column(length: 255)]
   #[Assert\NotBlank(message: "Le titre est obligatoire.")]
   #[Assert\Length(
    min: 5,
    max: 120,
    minMessage: "Le titre doit contenir au moins {{ limit }} caractères.",
    maxMessage: "Le titre ne doit pas dépasser {{ limit }} caractères."
 )]

    private ?string $titre_event = null;


    #[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "Le slug est obligatoire.")]
#[Assert\Length(max: 255)]
#[Assert\Regex(
    pattern: "/^[a-z0-9]+(?:-[a-z0-9]+)*$/",
    message: "Slug invalide (ex: journee-don-du-sang)."
)]
private ?string $slug_event = null;
  

    #[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "Le type est obligatoire.")]
#[Assert\Choice(
    choices: ["Campagne", "Conférence", "Atelier", "Caritatif", "Autre"],
    message: "Type invalide."
)]
private ?string $type_event = null;


    #[ORM\Column(length: 255)]
 #[Assert\NotBlank(message: "La description est obligatoire.")]
 #[Assert\Length(
    min: 20,
    max: 255,
    minMessage: "La description doit contenir au moins {{ limit }} caractères.",
    maxMessage: "La description ne doit pas dépasser {{ limit }} caractères."
 )]

  private ?string $description_event = null;


   #[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "L'objectif est obligatoire.")]
#[Assert\Length(min: 10, max: 255)]
private ?string $objectif_event = null;


    #[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "Le statut est obligatoire.")]
#[Assert\Choice(choices: ["Brouillon","Publié","Annulé"], message: "Statut invalide.")]
private ?string $statut_event = null;


    #[ORM\Column(type: Types::DATE_MUTABLE)]
#[Assert\NotNull(message: "La date de début est obligatoire.")]
private ?\DateTimeInterface $date_debut_event = null;

#[ORM\Column(type: Types::DATE_MUTABLE)]
#[Assert\NotNull(message: "La date de fin est obligatoire.")]
private ?\DateTimeInterface $date_fin_event = null;


    #[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "Le nom du lieu est obligatoire.")]
#[Assert\Length(min: 3, max: 255)]
private ?string $nom_lieu_event = null;

#[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "L'adresse est obligatoire.")]
#[Assert\Length(min: 5, max: 255)]
private ?string $adresse_event = null;

#[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "La ville est obligatoire.")]
#[Assert\Length(min: 2, max: 60)]
#[Assert\Regex(
    pattern: "/^[\p{L}\s'-]+$/u",
    message: "Ville invalide (lettres uniquement)."
)]
private ?string $ville_event = null;


    #[ORM\Column(nullable: true)]
#[Assert\Positive(message: "Le nombre max de participants doit être > 0.")]
private ?int $nb_participants_max_event = null;


   #[ORM\Column]
#[Assert\NotNull(message: "Veuillez préciser si l'inscription est obligatoire.")]
private ?bool $inscription_obligatoire_event = null;


   #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
private ?\DateTimeInterface $date_limite_inscription_event = null;


   #[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "L'email contact est obligatoire.")]
#[Assert\Email(message: "Email invalide.")]
private ?string $email_contact_event = null;

   #[ORM\Column(length: 30)]
#[Assert\NotBlank(message: "Le téléphone contact est obligatoire.")]
#[Assert\Regex(
    pattern: "/^\+?\d{8,15}$/",
    message: "Téléphone invalide (ex: +21612345678)."
)]
private ?string $tel_contact_event = null;


 #[ORM\Column(length: 255)]
#[Assert\NotBlank(message: "Le nom de l'organisateur est obligatoire.")]
#[Assert\Length(min: 2, max: 255)]
private ?string $nom_organisateur_event = null;

    #[ORM\Column(length: 255, nullable: true)]
#[Assert\Url(message: "Lien image invalide.")]
private ?string $image_couverture_event = null;


   #[ORM\Column(length: 255, nullable: true)]
#[Assert\Choice(choices: ["Public","Prive"], message: "Visibilité invalide.")]
private ?string $visibilite_event = null;


    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $date_creation_event = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $date_mise_a_jour_event = null;

    public function __construct()
    {
        $this->ressources = new ArrayCollection();
    }

    // ===================== ID =====================
    public function getId(): ?int
    {
        return $this->id;
    }

    // ===================== RESSOURCES =====================
    public function getRessources(): Collection
    {
        return $this->ressources;
    }

    public function addRessource(Ressource $ressource): static
    {
        if (!$this->ressources->contains($ressource)) {
            $this->ressources->add($ressource);
            $ressource->setEvenement($this);
        }
        return $this;
    }

    public function removeRessource(Ressource $ressource): static
    {
        if ($this->ressources->removeElement($ressource)) {
            // ⚠️ on NE met pas setEvenement(null) car JoinColumn(nullable:false)
        }
        return $this;
    }

    // ===================== GETTERS/SETTERS =====================
    public function getTitreEvent(): ?string
    {
        return $this->titre_event;
    }

    public function setTitreEvent(string $titre_event): static
    {
        $this->titre_event = $titre_event;
        return $this;
    }

    public function getSlugEvent(): ?string
    {
        return $this->slug_event;
    }

    public function setSlugEvent(string $slug_event): static
    {
        $this->slug_event = $slug_event;
        return $this;
    }

    public function getTypeEvent(): ?string
    {
        return $this->type_event;
    }

    public function setTypeEvent(string $type_event): static
    {
        $this->type_event = $type_event;
        return $this;
    }

    public function getDescriptionEvent(): ?string
    {
        return $this->description_event;
    }

    public function setDescriptionEvent(string $description_event): static
    {
        $this->description_event = $description_event;
        return $this;
    }

    public function getObjectifEvent(): ?string
    {
        return $this->objectif_event;
    }

    public function setObjectifEvent(string $objectif_event): static
    {
        $this->objectif_event = $objectif_event;
        return $this;
    }

    public function getStatutEvent(): ?string
    {
        return $this->statut_event;
    }

    public function setStatutEvent(string $statut_event): static
    {
        $this->statut_event = $statut_event;
        return $this;
    }

    public function getDateDebutEvent(): ?\DateTimeInterface
    {
        return $this->date_debut_event;
    }

    public function setDateDebutEvent(?\DateTimeInterface $date_debut_event): static
{
    $this->date_debut_event = $date_debut_event;
    return $this;
}


    public function getDateFinEvent(): ?\DateTimeInterface
    {
        return $this->date_fin_event;
    }

    public function setDateFinEvent(?\DateTimeInterface $date_fin_event): static
{
    $this->date_fin_event = $date_fin_event;
    return $this;
}

    public function getNomLieuEvent(): ?string
    {
        return $this->nom_lieu_event;
    }

    public function setNomLieuEvent(string $nom_lieu_event): static
    {
        $this->nom_lieu_event = $nom_lieu_event;
        return $this;
    }

    public function getAdresseEvent(): ?string
    {
        return $this->adresse_event;
    }

    public function setAdresseEvent(string $adresse_event): static
    {
        $this->adresse_event = $adresse_event;
        return $this;
    }

    public function getVilleEvent(): ?string
    {
        return $this->ville_event;
    }

    public function setVilleEvent(string $ville_event): static
    {
        $this->ville_event = $ville_event;
        return $this;
    }

    public function getNbParticipantsMaxEvent(): ?int
    {
        return $this->nb_participants_max_event;
    }

    public function setNbParticipantsMaxEvent(?int $nb_participants_max_event): static
    {
        $this->nb_participants_max_event = $nb_participants_max_event;
        return $this;
    }

    public function isInscriptionObligatoireEvent(): ?bool
    {
        return $this->inscription_obligatoire_event;
    }

    public function setInscriptionObligatoireEvent(?bool $inscription_obligatoire_event): static
{
    $this->inscription_obligatoire_event = $inscription_obligatoire_event;
    return $this;
}


    public function getDateLimiteInscriptionEvent(): ?\DateTimeInterface
    {
        return $this->date_limite_inscription_event;
    }

    public function setDateLimiteInscriptionEvent(?\DateTimeInterface $date_limite_inscription_event): static
    {
        $this->date_limite_inscription_event = $date_limite_inscription_event;
        return $this;
    }

    public function getEmailContactEvent(): ?string
    {
        return $this->email_contact_event;
    }

    public function setEmailContactEvent(string $email_contact_event): static
    {
        $this->email_contact_event = $email_contact_event;
        return $this;
    }

    public function getTelContactEvent(): ?string
    {
        return $this->tel_contact_event;
    }

    public function setTelContactEvent(string $tel_contact_event): static
    {
        $this->tel_contact_event = $tel_contact_event;
        return $this;
    }

    public function getNomOrganisateurEvent(): ?string
    {
        return $this->nom_organisateur_event;
    }

    public function setNomOrganisateurEvent(string $nom_organisateur_event): static
    {
        $this->nom_organisateur_event = $nom_organisateur_event;
        return $this;
    }

    public function getImageCouvertureEvent(): ?string
    {
        return $this->image_couverture_event;
    }

    public function setImageCouvertureEvent(?string $image_couverture_event): static
    {
        $this->image_couverture_event = $image_couverture_event;
        return $this;
    }

    public function getVisibiliteEvent(): ?string
    {
        return $this->visibilite_event;
    }

    public function setVisibiliteEvent(?string $visibilite_event): static
    {
        $this->visibilite_event = $visibilite_event;
        return $this;
    }

    public function getDateCreationEvent(): ?\DateTimeInterface
    {
        return $this->date_creation_event;
    }

    public function setDateCreationEvent(\DateTimeInterface $date_creation_event): static
    {
        $this->date_creation_event = $date_creation_event;
        return $this;
    }

    public function getDateMiseAJourEvent(): ?\DateTimeInterface
    {
        return $this->date_mise_a_jour_event;
    }

    public function setDateMiseAJourEvent(\DateTimeInterface $date_mise_a_jour_event): static
    {
        $this->date_mise_a_jour_event = $date_mise_a_jour_event;
        return $this;
    }

    


    public function getDemandesJson(): array
    {
         if (!$this->demandes_json) return [];
          $data = json_decode($this->demandes_json, true);
           return is_array($data) ? $data : [];
    }
    public function setDemandesJsonArray(array $demandes): static
    {
           $this->demandes_json = json_encode(array_values($demandes), JSON_UNESCAPED_UNICODE);
              return $this;
    }
    public function countDemandesByStatus(string $status): int
    {
           return count(array_filter($this->getDemandesJson(), fn($d) => ($d['status'] ?? '') === $status));
    }
    public function countAcceptedDemandes(): int
    {
            return $this->countDemandesByStatus('accepted');
    }
    public function canReceiveDemandes(): bool
    {
        // règles métier
        $now = new \DateTime();

        if ($this->getStatutEvent() && strtolower($this->getStatutEvent()) === 'annulé') return false;
        if ($this->getDateFinEvent() && $this->getDateFinEvent() < $now) return false;
        if ($this->isInscriptionObligatoireEvent() && $this->getDateLimiteInscriptionEvent() && $this->getDateLimiteInscriptionEvent() < $now) {
               return false;
        }
    if ($this->getNbParticipantsMaxEvent() !== null && $this->countAcceptedDemandes() >= $this->getNbParticipantsMaxEvent()) {
         return false;
         }
         return true;
    }
    public function addDemande(array $payload): static
    {
          $demandes = $this->getDemandesJson();
           $email = strtolower(trim($payload['email'] ?? ''));
            if (!$email) {
                    throw new \InvalidArgumentException("Email obligatoire.");
                     }
        // anti-duplicate: même email + même event
         foreach ($demandes as $d) {
              if (strtolower($d['email'] ?? '') === $email) {
                    throw new \RuntimeException("Une demande existe déjà avec cet email pour cet événement.");
                }
        }

    $demandes[] = [
        'id' => bin2hex(random_bytes(8)),
        'nom' => trim($payload['nom'] ?? ''),
        'email' => $email,
        'tel' => trim($payload['tel'] ?? ''),
        'message' => trim($payload['message'] ?? ''),
        'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        'status' => 'pending', // pending | accepted | refused
        'decision_at' => null,
        'decision_by' => null,
        'decision_note' => null,
    ];

    return $this->setDemandesJsonArray($demandes);
}

public function decideDemande(string $demandeId, string $status, ?string $decidedBy = null, ?string $note = null): static
{
    $allowed = ['accepted', 'refused'];
    if (!in_array($status, $allowed, true)) {
        throw new \InvalidArgumentException("Statut invalide.");
    }

    $demandes = $this->getDemandesJson();
    $found = false;

    foreach ($demandes as &$d) {
        if (($d['id'] ?? null) === $demandeId) {
            $d['status'] = $status;
            $d['decision_at'] = (new \DateTime())->format('Y-m-d H:i:s');
            $d['decision_by'] = $decidedBy;
            $d['decision_note'] = $note;
            $found = true;
            break;
        }
    }

    if (!$found) {
        throw new \RuntimeException("Demande introuvable.");
    }

    // règle: si accepted, vérifier max participants
    if ($status === 'accepted' && $this->getNbParticipantsMaxEvent() !== null) {
        if ($this->countAcceptedDemandes() > $this->getNbParticipantsMaxEvent()) {
            throw new \RuntimeException("Impossible: nombre max de participants dépassé.");
        }
    }

    return $this->setDemandesJsonArray($demandes);

    
}
public function __toString(): string
{
    return (string) ($this->titre_event ?? 'Événement #' . $this->id);
}

}