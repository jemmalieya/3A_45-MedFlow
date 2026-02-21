<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

#[Route('/api/2fa')]
final class TwoFactorApiController extends AbstractController
{
    #[Route('/status', name: 'api_2fa_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $res = $this->json([
            'enabled' => $user->isTotpEnabled(),
            'configured' => $user->getTotpSecret() !== null,
        ]);

        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }

    #[Route('/setup', name: 'api_2fa_setup', methods: ['POST'])]
    public function setup(
        TotpService $totp,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        if ($user->isTotpEnabled() && $user->getTotpSecret() !== null) {
            return $this->json(['error' => '2FA already enabled'], 400);
        }

        $secret = $user->getTotpSecret();
        if ($secret === null) {
            $secret = $totp->generateSecret();
            $user->setTotpSecret($secret);
            $user->setTotpEnabled(false);

            $em->persist($user);
            $em->flush();
        }

        $issuer = 'MedFlow';
        $accountName = $user->getEmailUser() ?? (string) $user->getUserIdentifier();

        $res = $this->json([
            'secret' => $secret,
            'otpauth_uri' => $totp->buildOtpAuthUri($secret, $accountName, $issuer),
            'issuer' => $issuer,
            'account' => $accountName,
        ]);

        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }

    #[Route('/confirm', name: 'api_2fa_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        TotpService $totp,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $secret = $user->getTotpSecret();
        if ($secret === null) {
            return $this->json(['error' => 'No secret configured. Call /api/2fa/setup first.'], 400);
        }

        $payload = $request->toArray();
        $code = (string) ($payload['code'] ?? '');

        if (!$totp->verifyCode($secret, $code)) {
            return $this->json(['error' => 'Invalid code'], 400);
        }

        $user->setTotpEnabled(true);
        $em->persist($user);
        $em->flush();

        $res = $this->json(['enabled' => true]);
        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }

    #[Route('/session/verify', name: 'api_2fa_session_verify', methods: ['POST'])]
    public function verifySession(
        Request $request,
        TotpService $totp,
        SessionInterface $session,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        if (!$user->isTotpEnabled() || $user->getTotpSecret() === null) {
            $session->remove('2fa_passed');
            return $this->json(['error' => '2FA not enabled'], 400);
        }

        $payload = $request->toArray();
        $code = (string) ($payload['code'] ?? '');

        if (!$totp->verifyCode($user->getTotpSecret(), $code)) {
            return $this->json(['ok' => false], 400);
        }

        $session->set('2fa_passed', true);

        return $this->json(['ok' => true]);
    }

    #[Route('/disable', name: 'api_2fa_disable', methods: ['POST'])]
    public function disable(
        Request $request,
        TotpService $totp,
        EntityManagerInterface $em,
        SessionInterface $session,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        if (!$user->isTotpEnabled() || $user->getTotpSecret() === null) {
            return $this->json(['enabled' => false]);
        }

        $payload = $request->toArray();
        $code = (string) ($payload['code'] ?? '');

        if (!$totp->verifyCode($user->getTotpSecret(), $code)) {
            return $this->json(['error' => 'Invalid code'], 400);
        }

        $user->setTotpEnabled(false);
        $user->setTotpSecret(null);

        $em->persist($user);
        $em->flush();

        $session->remove('2fa_passed');

        return $this->json(['enabled' => false]);
    }

    #[Route('/qr', name: 'api_2fa_qr', methods: ['GET'])]
    public function qr(Request $request): Response
    {
        // Same-origin QR generation to avoid browser blocking third-party images.
        $uri = (string) $request->query->get('uri', '');
        $text = (string) $request->query->get('text', '');

        $uri = trim($uri);
        $text = trim($text);

        $payload = $uri !== '' ? $uri : $text;

        if ($payload === '' || strlen($payload) > 2000) {
            return new Response('Bad Request', 400);
        }

        if ($uri !== '' && !str_starts_with($uri, 'otpauth://totp/')) {
            return new Response('Bad Request', 400);
        }

        $options = new QROptions([
            'version' => QRCode::VERSION_AUTO,
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => QRCode::ECC_L,
            'addQuietzone' => true,
            'scale' => 6,
        ]);

        $svg = (new QRCode($options))->render($payload);

        $response = new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);

        return $response;
    }
}
