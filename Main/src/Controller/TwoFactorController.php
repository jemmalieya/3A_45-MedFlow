<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use App\Security\TwoFactorRememberDeviceService;

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

        $account = $user->getEmailUser() ?? (string) $user->getUserIdentifier();

        return $this->render('security/two_factor_standalone.html.twig', [
            'account' => $account,
            'remember_days' => (int) (is_numeric($this->getParameter('two_factor_remember_days')) ? $this->getParameter('two_factor_remember_days') : 0),
        ]);
    }

    #[Route('/2fa/verify', name: 'app_2fa_verify', methods: ['POST'])]
    public function verify(
        Request $request,
        TotpService $totp,
        SessionInterface $session,
        TwoFactorRememberDeviceService $rememberDevice,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User || !$user->isTotpEnabled() || $user->getTotpSecret() === null) {
            return $this->redirectToRoute('app_home');
        }

        $code = (string) ($request->request->get('code')
            ?? $request->request->get('otp')
            ?? $request->request->get('token')
            ?? '');

        if (!$totp->verifyCode($user->getTotpSecret(), $code, window: 2)) {
            $this->addFlash('danger', 'Code 2FA invalide.');
            return $this->redirectToRoute('app_2fa_challenge');
        }

        $session->set('2fa_passed', true);

        $secure = $request->isSecure();
        if ($request->request->getBoolean('remember_device')) {
            $daysRaw = $this->getParameter('two_factor_remember_days');
            $days = is_numeric($daysRaw) ? (int) $daysRaw : 0;
            $cookie = $rememberDevice->createCookie($user, $days, $secure);
        } else {
            $cookie = null;
        }

        $target = $session->get('2fa_target_path');
        $session->remove('2fa_target_path');

        if (is_string($target) && $target !== '') {
            $resp = new RedirectResponse($target);
            if ($cookie !== null) {
                $resp->headers->setCookie($cookie);
            }
            return $resp;
        }

        $resp = $this->redirectToRoute('app_home');
        if ($cookie !== null) {
            $resp->headers->setCookie($cookie);
        }
        return $resp;
    }

    #[Route('/api/2fa/status', name: 'api_2fa_status', methods: ['GET'])]
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

    #[Route('/api/2fa/setup', name: 'api_2fa_setup', methods: ['POST'])]
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

    #[Route('/api/2fa/confirm', name: 'api_2fa_confirm', methods: ['POST'])]
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

        if (!$totp->verifyCode($secret, $code, window: 2)) {
            return $this->json(['error' => 'Invalid code'], 400);
        }

        $user->setTotpEnabled(true);
        $em->persist($user);
        $em->flush();

        $res = $this->json(['enabled' => true]);
        $res->headers->set('Cache-Control', 'no-store');
        return $res;
    }

    #[Route('/api/2fa/session/verify', name: 'api_2fa_session_verify', methods: ['POST'])]
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

        if (!$totp->verifyCode($user->getTotpSecret(), $code, window: 2)) {
            return $this->json(['ok' => false], 400);
        }

        $session->set('2fa_passed', true);

        return $this->json(['ok' => true]);
    }

    #[Route('/api/2fa/disable', name: 'api_2fa_disable', methods: ['POST'])]
    public function disable(
        Request $request,
        TotpService $totp,
        EntityManagerInterface $em,
        SessionInterface $session,
        TwoFactorRememberDeviceService $rememberDevice,
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

        if (!$totp->verifyCode($user->getTotpSecret(), $code, window: 2)) {
            return $this->json(['error' => 'Invalid code'], 400);
        }

        $user->setTotpEnabled(false);
        $user->setTotpSecret(null);

        $em->persist($user);
        $em->flush();

        $session->remove('2fa_passed');

        $res = $this->json(['enabled' => false]);
        $res->headers->setCookie($rememberDevice->clearCookie($request->isSecure()));
        return $res;
    }

    #[Route('/api/2fa/qr', name: 'api_2fa_qr', methods: ['GET'])]
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
            'svgAddXmlHeader' => false,
            'eccLevel' => QRCode::ECC_L,
            'addQuietzone' => true,
            'scale' => 6,
        ]);

        $svg = (new QRCode($options))->render($payload);

        return new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    #[Route('/2fa/open', name: 'app_2fa_open_link', methods: ['GET'])]
    public function open(Request $request): Response
    {
        // This endpoint exists so a normal phone camera can scan an HTTPS QR code,
        // open this page, then deep-link into the authenticator app (otpauth://...).
        $encoded = (string) $request->query->get('u', '');
        $encoded = trim($encoded);

        $uri = '';
        if ($encoded !== '') {
            $b64 = strtr($encoded, '-_', '+/');
            $pad = strlen($b64) % 4;
            if ($pad !== 0) {
                $b64 .= str_repeat('=', 4 - $pad);
            }
            $decoded = base64_decode($b64, true);
            if (is_string($decoded)) {
                $uri = $decoded;
            }
        }

        if ($uri === '' || strlen($uri) > 2000 || !str_starts_with($uri, 'otpauth://totp/')) {
            return new Response('Invalid link', 400);
        }

        $secret = '';
        $parsed = parse_url($uri);
        if (is_array($parsed) && isset($parsed['query'])) {
            parse_str((string) $parsed['query'], $params);
            if (isset($params['secret']) && is_string($params['secret'])) {
    $secret = $params['secret'];
}
        }

        $safeUri = htmlspecialchars($uri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSecret = htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Open Authenticator</title>
    <style>
      body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px;}
      .box{max-width:520px;margin:0 auto;}
      .muted{color:#666;}
      a.btn{display:inline-block;margin-top:12px;padding:12px 16px;border-radius:10px;background:#0d6efd;color:#fff;text-decoration:none;}
      code{display:inline-block;background:#f6f8fa;padding:6px 8px;border-radius:8px;}
    </style>
  </head>
  <body>
    <div class="box">
      <h1>Configuration 2FA</h1>
      <p class="muted">Appuyez sur le bouton ci-dessous pour ouvrir votre application (Google Authenticator, Microsoft Authenticator, Authy...).</p>
      <a class="btn" href="{$safeUri}">Ouvrir l’application</a>
      <p class="muted" style="margin-top:16px;">Si rien ne se passe: installez une application d’authentification, puis revenez ici et réessayez.</p>
      <p class="muted" style="margin-top:16px;">Sinon, vous pouvez ajouter le compte manuellement avec ce secret:</p>
      <p><code>{$safeSecret}</code></p>
    </div>
  </body>
</html>
HTML;

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
