<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class OAuthAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private LoginSuccessHandler $loginSuccessHandler,
        private RouterInterface $router
    ) {}

    public function supports(Request $request): bool
    {
        // Not used for request-based auth; only for programmatic login
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        // Programmatic login via UserAuthenticatorInterface never calls this
        throw new \LogicException('authenticate() should not be called for programmatic OAuth login.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Delegate to existing success handler to keep role-based redirects unified
        return $this->loginSuccessHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Fallback: redirect to login on failure
        return new Response('', Response::HTTP_UNAUTHORIZED);
    }
}
