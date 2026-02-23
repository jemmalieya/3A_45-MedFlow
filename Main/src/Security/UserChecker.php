<?php

namespace App\Security;

use App\Entity\User;
use App\Service\RecaptchaService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RecaptchaService $recaptcha,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$this->recaptcha->isEnabled()) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        // Only enforce captcha on the login check POST.
        if (!$request->isMethod('POST')) {
            return;
        }
        $route = (string) $request->attributes->get('_route', '');
        $path = (string) $request->getPathInfo();
        if ($route !== 'app_login' && $path !== '/login') {
            return;
        }

        if (!$this->recaptcha->verifyRequest($request)) {
            throw new CustomUserMessageAuthenticationException('Veuillez valider le reCAPTCHA.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // ✅ Blocage login si compte en attente de validation admin
        // (utilisé pour l'inscription Staff Medical)
        $status = strtoupper((string) $user->getStatutCompte());
        if ($status === 'EN_ATTENTE_ADMIN') {
            throw new CustomUserMessageAuthenticationException(
                "Votre compte est en attente de validation par un administrateur."
            );
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
