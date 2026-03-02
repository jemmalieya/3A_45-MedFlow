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

        // Session is already SessionInterface if it exists.
        // Use hasSession() to avoid PHPStan "instanceof always true".
        if ($request->hasSession()) {
            $session = $request->getSession();

            if ($appUser instanceof User && $appUser->isTotpEnabled()) {
                $session->set('2fa_passed', false);
            } else {
                $session->remove('2fa_passed');
            }
        } else {
            // Fallback (should not happen in normal web login)
            $session = null;
        }

        // Geo / unusual country check
        if ($appUser instanceof User) {
            $geo = [];
            try {
                $geo = $this->ipinfoClient->lookupCurrentRequest();
            } catch (\Throwable) {
                $geo = [];
            }

            $currentCountry = isset($geo['country']) ? strtoupper((string) $geo['country']) : null;
            $previousCountry = $appUser->getLastLoginCountry() ? strtoupper((string) $appUser->getLastLoginCountry()) : null;

            if ($previousCountry !== null && $currentCountry !== null && $previousCountry !== $currentCountry) {
                // Flash requires a real Session (concrete) for FlashBag
                if ($request->hasSession()) {
                    $flashSession = $request->getSession();
                    if ($flashSession instanceof Session) {
                        $flashSession->getFlashBag()->add(
                            'danger',
                            sprintf('Connexion bloquée: pays de connexion inhabituel (%s).', $currentCountry)
                        );
                    }
                }

                $this->tokenStorage->setToken(null);
                return new RedirectResponse($this->router->generate('app_login'));
            }

            if ($currentCountry !== null) {
                $appUser->setLastLoginIp($geo['ip'] ?? $request->getClientIp());
                $appUser->setLastLoginCountry($currentCountry);
                $appUser->touchLastLoginAt();
                $this->entityManager->persist($appUser);
                $this->entityManager->flush();
            }
        }

        // Set patient_id or doctor_id in session based on user role
        if ($appUser instanceof User && $request->hasSession()) {
            $roles = $token->getRoleNames();
            $sess = $request->getSession();

            if (in_array('ROLE_PATIENT', $roles, true)) {
                $sess->set('patient_id', $appUser->getId());
            }
            // Doctor can be STAFF or ADMIN
            if (in_array('ROLE_STAFF', $roles, true) || in_array('ROLE_ADMIN', $roles, true)) {
                $sess->set('doctor_id', $appUser->getId());
            }
        }

        // Redirect logic
        $roles = $token->getRoleNames();
        $redirectTo = null;

        if ($request->hasSession()) {
            $targetPath = $this->getTargetPath($request->getSession(), 'main');
            if (is_string($targetPath) && $targetPath !== '') {
                $redirectTo = $targetPath;
            }
        }

        if ($redirectTo === null) {
            if (in_array('ROLE_ADMIN', $roles, true)) {
                $redirectTo = $this->router->generate('ad_commandes_liste');
            } elseif (in_array('ROLE_STAFF', $roles, true) && $appUser instanceof User) {
                // PHPStan: method_exists($appUser,'getTypeStaff') always true (because $appUser is User)
                $typeStaff = $appUser->getTypeStaff();

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
        }

        // Enforce 2FA immediately after login to avoid timing/bypass issues.
        if (
            $appUser instanceof User
            && $appUser->isTotpEnabled()
            && $appUser->getTotpSecret() !== null
            && $request->hasSession()
        ) {
            $sess = $request->getSession();

            // Trusted device cookie: allow direct redirect without challenge.
            if ($this->rememberDevice->isRemembered($request, $appUser)) {
                $sess->set('2fa_passed', true);
                return new RedirectResponse((string) $redirectTo);
            }

            // PHPStan: is_string($redirectTo) always true (Router generate + targetPath are strings)
            if ($redirectTo !== '') {
                $sess->set('2fa_target_path', $redirectTo);
            }

            $sess->set('2fa_passed', false);
            return new RedirectResponse($this->router->generate('app_2fa_challenge'));
        }

        return new RedirectResponse((string) $redirectTo);
    }
}