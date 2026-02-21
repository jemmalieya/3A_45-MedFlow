<?php

namespace App\Service;

final class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10) {
            $bytes = 10;
        }

        $random = random_bytes($bytes);
        return $this->base32Encode($random);
    }

    public function buildOtpAuthUri(string $secret, string $accountName, string $issuer, int $digits = 6, int $period = 30): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => $digits,
            'period' => $period,
        ], '', '&', PHP_QUERY_RFC3986);

        return sprintf('otpauth://totp/%s?%s', $label, $query);
    }

    public function verifyCode(string $secret, string $code, int $window = 1, int $digits = 6, int $period = 30, ?int $time = null): bool
    {
        $normalized = preg_replace('/\s+/', '', $code ?? '');
        if ($normalized === null) {
            $normalized = '';
        }

        if (!preg_match('/^\d{' . $digits . '}$/', $normalized)) {
            return false;
        }

        $now = $time ?? time();
        $counter = (int) floor($now / $period);

        for ($i = -$window; $i <= $window; $i++) {
            $expected = $this->generateTotp($secret, $counter + $i, $digits);
            if (hash_equals($expected, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function generateTotp(string $secret, int $counter, int $digits): string
    {
        $key = $this->base32Decode($secret);
        if ($key === '') {
            return str_repeat('0', $digits);
        }

        $counterBytes = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $counterBytes, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % (10 ** $digits);
        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $binary = '';

        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binary, 5);
        $out = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $index = bindec($chunk);
            $out .= $alphabet[$index];
        }

        return $out;
    }

    private function base32Decode(string $base32): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $base32) ?? '');
        if ($clean === '') {
            return '';
        }

        $binary = '';
        $length = strlen($clean);
        for ($i = 0; $i < $length; $i++) {
            $pos = strpos($alphabet, $clean[$i]);
            if ($pos === false) {
                return '';
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = str_split($binary, 8);
        $out = '';
        foreach ($bytes as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }
            $out .= chr(bindec($byte));
        }

        return $out;
    }
}
