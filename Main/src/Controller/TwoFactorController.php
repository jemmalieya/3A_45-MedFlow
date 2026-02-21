<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\TotpService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class TwoFactorController extends AbstractController
{
    #[Route('/2fa', name: 'app_2fa_challenge', methods: ['GET'])]
    public function challenge(SessionInterface $session): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User || !$user->isTotpEnabled() || $user->getTotpSecret() === null) {
            $session->remove('2fa_passed');
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/two_factor.html.twig');
    }

    #[Route('/2fa/verify', name: 'app_2fa_verify', methods: ['POST'])]
    public function verify(
        Request $request,
        TotpService $totp,
        SessionInterface $session,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User || !$user->isTotpEnabled() || $user->getTotpSecret() === null) {
            return $this->redirectToRoute('app_home');
        }

        $code = (string) $request->request->get('code', '');

        if (!$totp->verifyCode($user->getTotpSecret(), $code)) {
            $this->addFlash('danger', 'Code 2FA invalide.');
            return $this->redirectToRoute('app_2fa_challenge');
        }

        $session->set('2fa_passed', true);

        $target = $session->get('2fa_target_path');
        $session->remove('2fa_target_path');

        if (is_string($target) && $target !== '') {
            return new RedirectResponse($target);
        }

        return $this->redirectToRoute('app_home');
    }
}
