<?php

namespace App\Entity;

use App\Repository\EvenementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EvenementRepository::class)]
#[ORM\Table(name: 'evenement')]
class Evenement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    // ====== DEMANDES JSON ======
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $demandes_json = null;

    // ====== RELATION RESSOURCES ======
    /**
     * @var Collection<int, Ressource>
     */
    #[ORM\OneToMany(mappedBy: 'evenement', targetEntity: Ressource::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $ressources;

    // ====== CHAMPS OBLIGATOIRES ======
    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "Le titre est obligatoire.")]
    #[Assert\Length(
        min: 5,
        max: 120,
        minMessage: "Le titre doit contenir au moins {{ limit }} caractères.",
        maxMessage: "Le titre ne doit pas dépasser {{ limit }} caractères."
    )]
   private string $titre_event = '';

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "Le slug est obligatoire.")]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(
        pattern: "/^[a-z0-9]+(?:-[a-z0-9]+)*$/",
        message: "Slug invalide (ex: journee-don-du-sang)."
    )]
    private string $slug_event;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "Le type est obligatoire.")]
    #[Assert\Choice(
        choices: ["Campagne", "Conférence", "Atelier", "Caritatif", "Autre"],
        message: "Type invalide."
    )]
    private string $type_event;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "La description est obligatoire.")]
    #[Assert\Length(
        min: 20,
        max: 255,
        minMessage: "La description doit contenir au moins {{ limit }} caractères.",
        maxMessage: "La description ne doit pas dépasser {{ limit }} caractères."
    )]
    private string $description_event;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "L'objectif est obligatoire.")]
    #[Assert\Length(min: 10, max: 255)]
    private string $objectif_event;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "Le statut est obligatoire.")]
    #[Assert\Choice(choices: ["Brouillon", "Publié", "Annulé"], message: "Statut invalide.")]
    private string $statut_event;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: false)]
    #[Assert\NotNull(message: "La date de début est obligatoire.")]
    private \DateTimeInterface $date_debut_event;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: false)]
    #[Assert\NotNull(message: "La date de fin est obligatoire.")]
    private \DateTimeInterface $date_fin_event;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "Le nom du lieu est obligatoire.")]
    #[Assert\Length(min: 3, max: 255)]
    private string $nom_lieu_event;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "L'adresse est obligatoire.")]
    #[Assert\Length(min: 5, max: 255)]
    private string $adresse_event;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "La ville est obligatoire.")]
    #[Assert\Length(min: 2, max: 60)]
    #[Assert\Regex(
        pattern: "/^[\p{L}\s'-]+$/u",
        message: "Ville invalide (lettres uniquement)."
    )]
    private string $ville_event;

    #[ORM\Column(nullable: false)]
    #[Assert\NotNull(message: "Veuillez préciser si l'inscription est obligatoire.")]
    private bool $inscription_obligatoire_event = false;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "L'email contact est obligatoire.")]
    #[Assert\Email(message: "Email invalide.")]
    private string $email_contact_event;

    #[ORM\Column(length: 30, nullable: false)]
    #[Assert\NotBlank(message: "Le téléphone contact est obligatoire.")]
    #[Assert\Regex(
        pattern: "/^\+?\d{8,15}$/",
        message: "Téléphone invalide (ex: +21612345678)."
    )]
    private string $tel_contact_event;

    #[ORM\Column(length: 255, nullable: false)]
    #[Assert\NotBlank(message: "Le nom de l'organisateur est obligatoire.")]
    #[Assert\Length(min: 2, max: 255)]
    private string $nom_organisateur_event;

    // ====== CHAMPS OPTIONNELS ======
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: "Le nombre max de participants doit être > 0.")]
    private ?int $nb_participants_max_event = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $date_limite_inscription_event = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(message: "Lien image invalide.")]
    private ?string $image_couverture_event = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Choice(choices: ["Public", "Prive"], message: "Visibilité invalide.")]
    private ?string $visibilite_event = null;

    // ====== AUDIT DATES (OBLIGATOIRES) ======
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: false)]
    private \DateTimeInterface $date_creation_event;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: false)]
    private \DateTimeInterface $date_mise_a_jour_event;

    public function __construct()
    {
        $this->ressources = new ArrayCollection();

        // Defaults sécurisés (évite uninitialized + aide au dev)
        $today = new \DateTimeImmutable('today');
        $this->date_creation_event = $today;
        $this->date_mise_a_jour_event = $today;
        $this->date_debut_event = $today;
        $this->date_fin_event = $today;

        // Valeurs string obligatoires => init vide (validation NotBlank empêche l'enregistrement)
        $this->titre_event = '';
        $this->slug_event = '';
        $this->type_event = 'Autre';
        $this->description_event = '';
        $this->objectif_event = '';
        $this->statut_event = 'Brouillon';
        $this->nom_lieu_event = '';
        $this->adresse_event = '';
        $this->ville_event = '';
        $this->email_contact_event = '';
        $this->tel_contact_event = '';
        $this->nom_organisateur_event = '';
    }

    // ===================== RESSOURCES =====================
    /**
     * @return Collection<int, Ressource>
     */
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
        $this->ressources->removeElement($ressource);
        return $this;
    }

    // ===================== GETTERS / SETTERS =====================
// ====== TITRE ======
public function getTitreEvent(): string
{
    return $this->titre_event;
}
public function setTitreEvent(?string $v): static
{
    $this->titre_event = trim($v ?? '');
    return $this;
}
// ====== SLUG ======
public function getSlugEvent(): string
{
    return $this->slug_event;
}

public function setSlugEvent(?string $v): static
{
    $this->slug_event = trim($v ?? '');
    return $this;
}

// ====== TYPE ======
public function getTypeEvent(): string
{
    return $this->type_event;
}

public function setTypeEvent(?string $v): static
{
    $this->type_event = trim($v ?? '');
    return $this;
}

// ====== DESCRIPTION ======
public function getDescriptionEvent(): string
{
    return $this->description_event;
}

public function setDescriptionEvent(?string $v): static
{
    $this->description_event = trim($v ?? '');
    return $this;
}

// ====== OBJECTIF ======
public function getObjectifEvent(): string
{
    return $this->objectif_event;
}

public function setObjectifEvent(?string $v): static
{
    $this->objectif_event = trim($v ?? '');
    return $this;
}

// ====== STATUT ======
public function getStatutEvent(): string
{
    return $this->statut_event;
}

public function setStatutEvent(?string $v): static
{
    $this->statut_event = trim($v ?? '');
    return $this;
}

    public function getDateDebutEvent(): \DateTimeInterface { return $this->date_debut_event; }
    public function setDateDebutEvent(\DateTimeInterface $date_debut_event): static { $this->date_debut_event = $date_debut_event; return $this; }

    public function getDateFinEvent(): \DateTimeInterface { return $this->date_fin_event; }
    public function setDateFinEvent(\DateTimeInterface $date_fin_event): static { $this->date_fin_event = $date_fin_event; return $this; }

   // ====== LIEU / ADRESSE / VILLE ======
public function getNomLieuEvent(): string
{
    return $this->nom_lieu_event;
}

public function setNomLieuEvent(?string $v): static
{
    $this->nom_lieu_event = trim($v ?? '');
    return $this;
}

public function getAdresseEvent(): string
{
    return $this->adresse_event;
}

public function setAdresseEvent(?string $v): static
{
    $this->adresse_event = trim($v ?? '');
    return $this;
}

public function getVilleEvent(): string
{
    return $this->ville_event;
}

public function setVilleEvent(?string $v): static
{
    $this->ville_event = trim($v ?? '');
    return $this;
}
    public function getNbParticipantsMaxEvent(): ?int { return $this->nb_participants_max_event; }
    public function setNbParticipantsMaxEvent(?int $nb_participants_max_event): static { $this->nb_participants_max_event = $nb_participants_max_event; return $this; }

    public function isInscriptionObligatoireEvent(): bool { return $this->inscription_obligatoire_event; }
    public function setInscriptionObligatoireEvent(bool $inscription_obligatoire_event): static { $this->inscription_obligatoire_event = $inscription_obligatoire_event; return $this; }

    public function getDateLimiteInscriptionEvent(): ?\DateTimeInterface { return $this->date_limite_inscription_event; }
    public function setDateLimiteInscriptionEvent(?\DateTimeInterface $date_limite_inscription_event): static { $this->date_limite_inscription_event = $date_limite_inscription_event; return $this; }
// ====== CONTACT ======
public function getEmailContactEvent(): string
{
    return $this->email_contact_event;
}

public function setEmailContactEvent(?string $v): static
{
    $this->email_contact_event = trim($v ?? '');
    return $this;
}

public function getTelContactEvent(): string
{
    return $this->tel_contact_event;
}

public function setTelContactEvent(?string $v): static
{
    $this->tel_contact_event = trim($v ?? '');
    return $this;
}

public function getNomOrganisateurEvent(): string
{
    return $this->nom_organisateur_event;
}

public function setNomOrganisateurEvent(?string $v): static
{
    $this->nom_organisateur_event = trim($v ?? '');
    return $this;
}
    public function getImageCouvertureEvent(): ?string { return $this->image_couverture_event; }
    public function setImageCouvertureEvent(?string $image_couverture_event): static { $this->image_couverture_event = $image_couverture_event; return $this; }

    public function getVisibiliteEvent(): ?string { return $this->visibilite_event; }
    public function setVisibiliteEvent(?string $visibilite_event): static { $this->visibilite_event = $visibilite_event; return $this; }

    public function getDateCreationEvent(): \DateTimeInterface { return $this->date_creation_event; }
    public function setDateCreationEvent(\DateTimeInterface $date_creation_event): static { $this->date_creation_event = $date_creation_event; return $this; }

    public function getDateMiseAJourEvent(): \DateTimeInterface { return $this->date_mise_a_jour_event; }
    public function setDateMiseAJourEvent(\DateTimeInterface $date_mise_a_jour_event): static { $this->date_mise_a_jour_event = $date_mise_a_jour_event; return $this; }

    // ===================== DEMANDES JSON =====================
    /**
     * @return list<array<string, mixed>>
     */
    public function getDemandesJson(): array
    {
        if ($this->demandes_json === null || $this->demandes_json === '') {
            return [];
        }

        $raw = json_decode($this->demandes_json, true);

        if (!is_array($raw)) {
            return [];
        }

        /** @var list<array<string, mixed>> $out */
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $demandes
     */
    public function setDemandesJsonArray(array $demandes): static
    {
        $json = json_encode($demandes, JSON_UNESCAPED_UNICODE);
        $this->demandes_json = ($json === false) ? '[]' : $json;
        return $this;
    }

    public function countDemandesByStatus(string $status): int
    {
        return count(array_filter(
            $this->getDemandesJson(),
            fn($d) => ((string)($d['status'] ?? '')) === $status
        ));
    }

    public function countAcceptedDemandes(): int
    {
        return $this->countDemandesByStatus('accepted');
    }

    public function canReceiveDemandes(): bool
    {
        $now = new \DateTime();

        if (strtolower($this->getStatutEvent()) === 'annulé') return false;
        if ($this->getDateFinEvent() < $now) return false;

        if (
            $this->isInscriptionObligatoireEvent()
            && $this->getDateLimiteInscriptionEvent()
            && $this->getDateLimiteInscriptionEvent() < $now
        ) {
            return false;
        }

        if ($this->getNbParticipantsMaxEvent() !== null && $this->countAcceptedDemandes() >= $this->getNbParticipantsMaxEvent()) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addDemande(array $payload): static
    {
        $demandes = $this->getDemandesJson();

        $email = strtolower(trim((string)($payload['email'] ?? '')));
        if ($email === '') {
            throw new \InvalidArgumentException("Email obligatoire.");
        }

        foreach ($demandes as $d) {
            if (strtolower((string)($d['email'] ?? '')) === $email) {
                throw new \RuntimeException("Une demande existe déjà avec cet email pour cet événement.");
            }
        }

        $demandes[] = [
            'id' => bin2hex(random_bytes(8)),
            'nom' => trim((string)($payload['nom'] ?? '')),
            'email' => $email,
            'tel' => trim((string)($payload['tel'] ?? '')),
            'message' => trim((string)($payload['message'] ?? '')),
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'status' => 'pending',
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
            if (((string)($d['id'] ?? '')) === $demandeId) {
                $d['status'] = $status;
                $d['decision_at'] = (new \DateTime())->format('Y-m-d H:i:s');
                $d['decision_by'] = $decidedBy;
                $d['decision_note'] = $note;
                $found = true;
                break;
            }
        }
        unset($d);

        if (!$found) {
            throw new \RuntimeException("Demande introuvable.");
        }

        if ($status === 'accepted' && $this->getNbParticipantsMaxEvent() !== null) {
            if ($this->countAcceptedDemandes() > $this->getNbParticipantsMaxEvent()) {
                throw new \RuntimeException("Impossible: nombre max de participants dépassé.");
            }
        }

        return $this->setDemandesJsonArray($demandes);
    }
    
    public function __toString(): string
{
    $titre = trim($this->titre_event); // pas besoin de ?? '' car string non-null

    if ($titre !== '') {
        return $titre;
    }

    return 'Événement #' . (string) ($this->id ?? '');
}
}