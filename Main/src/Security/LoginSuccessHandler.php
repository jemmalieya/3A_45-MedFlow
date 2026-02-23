<?php

namespace App\Security;

use App\Entity\User;
use App\Service\IpinfoClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use App\Security\TwoFactorRememberDeviceService;

class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly IpinfoClient $ipinfoClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly TwoFactorRememberDeviceService $rememberDevice,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        $user = $token->getUser();
        $appUser = $user instanceof User ? $user : null;

        $session = $request->getSession();
        if ($session instanceof SessionInterface) {
            if ($appUser instanceof User && $appUser->isTotpEnabled()) {
                $session->set('2fa_passed', false);
            } else {
                $session->remove('2fa_passed');
            }
        }

        if ($appUser instanceof User) {
            $geo = [];
            try {
                $geo = $this->ipinfoClient->lookupCurrentRequest();
            } catch (\Throwable) {
                // Fail-open if geo provider is down/misconfigured
                $geo = [];
            }

            $currentCountry = isset($geo['country']) ? strtoupper((string) $geo['country']) : null;
            $previousCountry = $appUser->getLastLoginCountry() ? strtoupper((string) $appUser->getLastLoginCountry()) : null;

            if ($previousCountry !== null && $currentCountry !== null && $previousCountry !== $currentCountry) {
                $flashSession = $request->getSession();
                if ($flashSession instanceof Session) {
                    $flashSession->getFlashBag()->add(
                        'danger',
                        sprintf('Connexion bloquée: pays de connexion inhabituel (%s).', $currentCountry)
                    );
                }

                $this->tokenStorage->setToken(null);
                return new RedirectResponse($this->router->generate('app_login'));
            }

            if ($currentCountry !== null) {
                $appUser->setLastLoginIp($geo['ip'] ?? $request->getClientIp());
                $appUser->setLastLoginCountry($currentCountry);
                $appUser->setLastLoginAt(new \DateTime());
                $this->entityManager->persist($appUser);
                $this->entityManager->flush();
            }
        }

        // Set patient_id or doctor_id in session based on user role
        if ($appUser instanceof User) {
            $roles = $token->getRoleNames();
            if (in_array('ROLE_PATIENT', $roles, true)) {
                $request->getSession()->set('patient_id', $appUser->getId());
            }
            // Doctor can be STAFF or ADMIN
            if (in_array('ROLE_STAFF', $roles, true) || in_array('ROLE_ADMIN', $roles, true)) {
                $request->getSession()->set('doctor_id', $appUser->getId());
            }
        }
        // ✅ Si l'utilisateur a été intercepté en voulant accéder à une page protégée
        // (ex: /admin), Symfony a mémorisé cette destination => on respecte ça.
        $roles = $token->getRoleNames();

        $redirectTo = null;
        if ($targetPath = $this->getTargetPath($request->getSession(), 'main')) {
            $redirectTo = $targetPath;
        } elseif (in_array('ROLE_ADMIN', $roles, true)) {
            $redirectTo = $this->router->generate('ad_commandes_liste');
        } elseif (in_array('ROLE_STAFF', $roles, true) && $appUser instanceof User) {
            $typeStaff = method_exists($appUser, 'getTypeStaff') ? $appUser->getTypeStaff() : null;
            if ($typeStaff === 'RESP_PATIENTS') {
                $redirectTo = $this->router->generate('app_fiche_by_staff', ['idStaff' => $appUser->getId()]);
            } elseif ($typeStaff === 'RESP_BLOG') {
                $redirectTo = $this->router->generate('admin_reclamations');
            } elseif ($typeStaff === 'RESP_USERS') {
                $redirectTo = $this->router->generate('staff_patients_list');
            } elseif ($typeStaff === 'RESP_PRODUCTS') {
                $redirectTo = $this->router->generate('admin_produits_index');
            } elseif ($typeStaff === 'RESP_EVEN') {
                $redirectTo = $this->router->generate('admin_evenement_cards');
            } else {
                $redirectTo = $this->router->generate('app_admin');
            }
        } else {
            $redirectTo = $this->router->generate('app_home');
        }

        // Enforce 2FA immediately after login to avoid timing/bypass issues.
        if ($appUser instanceof User && $appUser->isTotpEnabled() && $appUser->getTotpSecret() !== null && $session instanceof SessionInterface) {
            // Trusted device cookie: allow direct redirect without challenge.
            if ($this->rememberDevice->isRemembered($request, $appUser)) {
                $session->set('2fa_passed', true);
                return new RedirectResponse((string) $redirectTo);
            }
            if (is_string($redirectTo) && $redirectTo !== '') {
                $session->set('2fa_target_path', $redirectTo);
            }
            $session->set('2fa_passed', false);
            return new RedirectResponse($this->router->generate('app_2fa_challenge'));
        }

        return new RedirectResponse((string) $redirectTo);
    }
}
