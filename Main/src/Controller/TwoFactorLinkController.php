<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TwoFactorLinkController extends AbstractController
{
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
            if (is_array($params) && isset($params['secret']) && is_string($params['secret'])) {
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
