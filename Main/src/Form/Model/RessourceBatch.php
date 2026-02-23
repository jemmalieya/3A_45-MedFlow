<?php

namespace App\Form\Model;

use App\Entity\Ressource;
use Symfony\Component\Validator\Constraints as Assert;

class RessourceBatch
{
    /**
     * @var Ressource[]
     */
    #[Assert\Count(min: 1, minMessage: "Ajoute au moins une ressource.")]
    #[Assert\Valid]
    public array $ressources = [];
}
