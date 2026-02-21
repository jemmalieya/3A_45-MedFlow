<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class TwoFactorEnforcerSubscriber implements EventSubscriberInterface
{
    /**
     * @var string[]
     */
    private array $allowedRoutes = [
        'app_login',
        'app_logout',
        'app_2fa_challenge',
        'app_2fa_verify',
        'api_2fa_status',
        'api_2fa_setup',
        'api_2fa_confirm',
        'api_2fa_qr',
        'api_2fa_session_verify',
        'api_2fa_disable',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isTotpEnabled() || $user->getTotpSecret() === null) {
            return;
        }

        $route = (string) $request->attributes->get('_route', '');
        if ($route !== '' && in_array($route, $this->allowedRoutes, true)) {
            return;
        }

        $session = $request->getSession();
        if (!$session instanceof SessionInterface) {
            return;
        }

        if ($session->get('2fa_passed') === true) {
            return;
        }

        $this->rememberTargetPath($request, $session);

        if ($this->isApiRequest($request)) {
            $event->setResponse(new JsonResponse([
                'error' => '2FA required',
            ], 403));
            return;
        }

        $event->setResponse(new RedirectResponse($this->router->generate('app_2fa_challenge')));
    }

    private function isApiRequest(Request $request): bool
    {
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/api/')) {
            return true;
        }

        $accept = $request->headers->get('Accept', '');
        return str_contains($accept, 'application/json');
    }

    private function rememberTargetPath(Request $request, SessionInterface $session): void
    {
        if ($request->getMethod() !== 'GET') {
            return;
        }

        $path = $request->getUri();
        if ($path === '') {
            return;
        }

        if (!$session->has('2fa_target_path')) {
            $session->set('2fa_target_path', $path);
        }
    }
}
