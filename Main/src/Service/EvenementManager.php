<?php

namespace App\Service;

use App\Entity\Evenement;

class EvenementManager
{
    public function validate(Evenement $evenement): bool
    {
        if (trim((string) $evenement->getTitreEvent()) === '') {
            throw new \InvalidArgumentException("Le titre de l'événement est obligatoire");
        }

        if (trim((string) $evenement->getSlugEvent()) === '') {
            throw new \InvalidArgumentException("Le slug de l'événement est obligatoire");
        }

        $debut = $evenement->getDateDebutEvent();
        $fin   = $evenement->getDateFinEvent();

        // ✅ plus de && inutiles
        if ($fin < $debut) {
            throw new \InvalidArgumentException("La date de fin doit être après la date de début");
        }

        if ($evenement->isInscriptionObligatoireEvent() && !$evenement->getDateLimiteInscriptionEvent()) {
            throw new \InvalidArgumentException("La date limite est obligatoire si l'inscription est obligatoire");
        }

        return true;
    }
}