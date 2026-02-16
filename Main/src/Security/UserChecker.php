<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        // Rien ici (on garde simple)
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // ✅ Blocage login si email non vérifié
        if (!$user->isVerified()) {
            throw new CustomUserMessageAuthenticationException(
                'Veuillez vérifier votre email avant de vous connecter.'
            );
        }



      // ✅ Blocage login si compte bloqué (affiche la raison écrite par l'admin)
      $status = strtoupper((string) $user->getStatutCompte());
      if ($status === 'BLOQUE') {
          $reason = trim((string) $user->getBanReason());
          $msg = $reason !== '' ? ('Compte bloqué: '.$reason) : 'Compte bloqué.';
          throw new CustomUserMessageAuthenticationException($msg);
      }

    }
}
