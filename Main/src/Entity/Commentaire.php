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
    private ?\DateTime $date_creation = null;
   
    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'commentaires')]
    #[ORM\JoinColumn(name: 'id_post', referencedColumnName: 'id', nullable: false)]
    private ?Post $post = null;

    #[ORM\Column]
    private ?bool $est_anonyme = null;

    #[ORM\Column(length: 60)]
    private ?string $parametres_confidentialite = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDateCreation(): ?\DateTime
    {
        return $this->date_creation;
    }

    public function setDateCreation(\DateTime $date_creation): static
    {
        $this->date_creation = $date_creation;

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
