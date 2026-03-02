<?php

namespace App\Service;

use App\Entity\User;

class UserManager
{
    public function validate(User $user): bool
    {
        // 1) Prénom obligatoire
        if (empty($user->getPrenom())) {
            throw new \InvalidArgumentException('Le prénom est obligatoire');
        }

        // 2) Email valide (chez toi: emailUser)
        $email = $user->getEmailUser();
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email invalide');
        }

        // 3) Mot de passe (plain si présent, sinon password)
        $pwd = $user->getPlainPassword() ?: $user->getPassword();
        if (empty($pwd) || strlen($pwd) < 8) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins 8 caractères');
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&]).{8,}$/', $pwd)) {
            throw new \InvalidArgumentException(
                'Le mot de passe doit contenir: minuscules + majuscules + chiffres + caractères spéciaux (@$!%*?&).'
            );
        }

        // 4) Date de naissance (obligatoire + passée + >= 18 ans)
        // ⚠️ Remplace getDateNaissance() si ton getter a un autre nom
        $dn = $user->getDateNaissance();

        if (!$dn) {
            throw new \InvalidArgumentException('La date de naissance est obligatoire');
        }

        $today = new \DateTimeImmutable('today');

        // doit être dans le passé
        if ($dn > $today) {
            throw new \InvalidArgumentException('La date de naissance doit être dans le passé');
        }

        // âge >= 18
        $age = $dn->diff($today)->y;
        if ($age < 18) {
            throw new \InvalidArgumentException('Vous devez avoir au moins 18 ans');
        }

        return true;
    }
}