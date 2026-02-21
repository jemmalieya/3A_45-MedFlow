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

class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly IpinfoClient $ipinfoClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
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
        if ($targetPath = $this->getTargetPath($request->getSession(), 'main')) {
            return new RedirectResponse($targetPath);
        }

        $roles = $token->getRoleNames();

        // ✅ Redirection selon rôle
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return new RedirectResponse($this->router->generate('ad_commandes_liste'));
        }

        if (in_array('ROLE_STAFF', $roles, true) && $appUser instanceof User) {
            // Check type_staff for STAFF
            $typeStaff = method_exists($appUser, 'getTypeStaff') ? $appUser->getTypeStaff() : null;
            if ($typeStaff === 'RESP_PATIENTS') {
                // Redirect to fiche by staff page
                $staffId = $appUser->getId();
                return new RedirectResponse($this->router->generate('app_fiche_by_staff', ['idStaff' => $staffId]));
            }

             if ($typeStaff === 'RESP_BLOG') {
                // Redirect to blog management page
                     $staffId = $appUser->getId();
                return new RedirectResponse($this->router->generate('admin_reclamations'));
            } 

            if ($typeStaff === 'RESP_USERS') {
                // Redirect to fiche by staff page
                $staffId = $appUser->getId();
                return new RedirectResponse($this->router->generate('staff_patients_list'));
            }

             if ($typeStaff === 'RESP_PRODUCTS') {
                // Redirect to fiche by staff page
                     $staffId = $appUser->getId();
                return new RedirectResponse($this->router->generate('admin_produits_index'));
            }
            
            if ($typeStaff === 'RESP_EVEN') {
                // Redirect to fiche by staff page
                $staffId = $appUser->getId();
                return new RedirectResponse($this->router->generate('admin_evenement_cards'));
            }
            
            // Default STAFF redirect
            return new RedirectResponse($this->router->generate('app_admin'));
        }

        return new RedirectResponse($this->router->generate('app_home'));
    }
}
