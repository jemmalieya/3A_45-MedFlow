<?php

namespace App\Service;

use App\Entity\Reclamation;

class ReclamationManager
{
    public function validate(Reclamation $reclamation): bool
    {
        // 1) Vérification cohérence des dates
        if ($reclamation->getDateCreationR() !== null && $reclamation->getDateLimite() !== null) {
            if ($reclamation->getDateLimite() < $reclamation->getDateCreationR()) {
                throw new \InvalidArgumentException(
                    "La date limite ne peut pas être antérieure à la date de création."
                );
            }
        }

        // 2) Contenu obligatoire
        if (trim((string) $reclamation->getContenu()) === '') {
            throw new \InvalidArgumentException("Le contenu est obligatoire.");
        }

        // 3) Description obligatoire
        if (trim((string) $reclamation->getDescription()) === '') {
            throw new \InvalidArgumentException("La description est obligatoire.");
        }

        // 4) Type obligatoire
        if (trim((string) $reclamation->getType()) === '') {
            throw new \InvalidArgumentException("Le type est obligatoire.");
        }

        return true;
    }
}