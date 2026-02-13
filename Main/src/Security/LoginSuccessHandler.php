<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(private RouterInterface $router) {}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        // Set patient_id or doctor_id in session based on user role
        $user = $token->getUser();
        if ($user && method_exists($user, 'getId')) {
            $roles = $token->getRoleNames();
            if (in_array('ROLE_PATIENT', $roles, true)) {
                $request->getSession()->set('patient_id', $user->getId());
            }
            // Doctor can be STAFF or ADMIN
            if (in_array('ROLE_STAFF', $roles, true) || in_array('ROLE_ADMIN', $roles, true)) {
                $request->getSession()->set('doctor_id', $user->getId());
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
            return new RedirectResponse($this->router->generate('app_ad'));
        }

        if (in_array('ROLE_STAFF', $roles, true)) {
            // Check type_staff for STAFF
            $typeStaff = method_exists($user, 'getTypeStaff') ? $user->getTypeStaff() : null;
            if ($typeStaff === 'RESP_PATIENTS') {
                // Redirect to fiche by staff page
                $staffId = $user->getId();
                return new RedirectResponse($this->router->generate('app_fiche_by_staff', ['idStaff' => $staffId]));
            }

             if ($typeStaff === 'RESP_BLOG') {
                // Redirect to blog management page
                $staffId = $user->getId();
                return new RedirectResponse($this->router->generate('admin_reclamations'));
            } 

            if ($typeStaff === 'RESP_USERS') {
                // Redirect to fiche by staff page
                $staffId = $user->getId();
                return new RedirectResponse($this->router->generate('staff_patients_index'));
            }

             if ($typeStaff === 'RESP_PRODUCTS') {
                // Redirect to fiche by staff page
                $staffId = $user->getId();
                return new RedirectResponse($this->router->generate('admin_produits_index'));
            }
            
            if ($typeStaff === 'RESP_EVEN') {
                // Redirect to fiche by staff page
                $staffId = $user->getId();
                return new RedirectResponse($this->router->generate('admin_evenement_cards'));
            }
            
            // Default STAFF redirect
            return new RedirectResponse($this->router->generate('app_admin'));
        }

        return new RedirectResponse($this->router->generate('app_home'));

    }
}
