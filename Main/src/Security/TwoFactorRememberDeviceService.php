<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class TwoFactorRememberDeviceService
{
    public const COOKIE_NAME = 'mf_2fa_remember';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    public function createCookie(User $user, int $days, bool $secure): Cookie
    {
        if ($days < 1) {
            $days = 1;
        }

        $expiresAt = time() + ($days * 86400);
        $data = $user->getId() . ':' . $expiresAt;
        $sig = $this->sign($data, $user);

        $value = $this->b64urlEncode($data) . '.' . $this->b64urlEncode($sig);

        return Cookie::create(self::COOKIE_NAME)
            ->withValue($value)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite('lax');
    }

    public function clearCookie(bool $secure): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue('')
            ->withExpires(time() - 3600)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite('lax');
    }

    public function isRemembered(Request $request, User $user): bool
    {
        $raw = (string) $request->cookies->get(self::COOKIE_NAME, '');
        if ($raw === '' || !str_contains($raw, '.')) {
            return false;
        }

        [$dataB64, $sigB64] = explode('.', $raw, 2);
        $data = $this->b64urlDecode($dataB64);
        $sig = $this->b64urlDecode($sigB64);

        if ($data === '' || $sig === '') {
            return false;
        }

        if (!str_contains($data, ':')) {
            return false;
        }

        [$uid, $exp] = explode(':', $data, 2);
        if (!ctype_digit($uid) || !ctype_digit($exp)) {
            return false;
        }

        if ((int) $uid !== (int) $user->getId()) {
            return false;
        }

        if ((int) $exp < time()) {
            return false;
        }

        $expected = $this->sign($data, $user);
        return hash_equals($expected, $sig);
    }

    private function sign(string $data, User $user): string
    {
        // Bind signature to user password hash so a password change invalidates existing remembered devices.
        $keyMaterial = $this->appSecret . '|' . (string) $user->getPassword();
        return hash_hmac('sha256', $data, $keyMaterial, true);
    }

    private function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $b64url): string
    {
        $b64 = strtr($b64url, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad !== 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        return is_string($decoded) ? $decoded : '';
    }
}
