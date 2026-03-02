<?php

namespace App\Service;

use App\Entity\RendezVous;

class RendezVousManager
{
    public function validate(RendezVous $rdv): bool
    {
        // 1) Motif obligatoire + taille
        $motif = $rdv->getMotif();
        if ($motif === null || trim($motif) === '') {
            throw new \InvalidArgumentException('Le motif est obligatoire');
        }
        $len = mb_strlen(trim($motif));
        if ($len < 5 || $len > 150) {
            throw new \InvalidArgumentException('Le motif doit être entre 5 et 150 caractères');
        }

        // 2) Date/heure obligatoire + futur
        $dt = $rdv->getDatetime();
        if ($dt === null) {
            throw new \InvalidArgumentException('La date et l\'heure sont obligatoires');
        }
        $now = new \DateTimeImmutable('now');
        if ($dt <= $now) {
            throw new \InvalidArgumentException('La date et l\'heure doivent être dans le futur');
        }

        // 3) Mode obligatoire
        $mode = $rdv->getMode();
        if ($mode === null || trim($mode) === '') {
            throw new \InvalidArgumentException('Le mode est obligatoire');
        }

        // (Optionnel) urgence contrôlée si renseignée
        $urg = $rdv->getUrgencyLevel();
        if ($urg !== null) {
            $allowed = ['low', 'medium', 'high'];
            if (!in_array($urg, $allowed, true)) {
                throw new \InvalidArgumentException('UrgencyLevel invalide (low|medium|high)');
            }
        }

        return true;
    }
}