<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RecaptchaService
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $secretKey,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return trim((string) $this->secretKey) !== '';
    }

    public function verifyRequest(Request $request): bool
    {
        $token = (string) $request->request->get('g-recaptcha-response', '');
        $remoteIp = $request->getClientIp();

        return $this->verifyToken($token, $remoteIp);
    }

    public function verifyToken(?string $token, ?string $remoteIp = null): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $token = trim((string) $token);
        if ($token === '') {
            $this->logger?->info('reCAPTCHA verify: missing token', [
                'ip' => $remoteIp,
            ]);
            return false;
        }

        try {
            $body = [
                'secret' => $this->secretKey,
                'response' => $token,
            ];
            if (is_string($remoteIp) && trim($remoteIp) !== '') {
                $body['remoteip'] = $remoteIp;
            }

            $res = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => $body,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $data = $res->toArray(false);

            $success = (bool) ($data['success'] ?? false);
            $this->logger?->info('reCAPTCHA verify: response', [
                'success' => $success,
                'ip' => $remoteIp,
                'hostname' => $data['hostname'] ?? null,
                'errorCodes' => $data['error-codes'] ?? null,
            ]);

            return $success;
        } catch (\Throwable) {
            // Fail closed when enabled.
            $this->logger?->warning('reCAPTCHA verify: exception', [
                'ip' => $remoteIp,
            ]);
            return false;
        }
    }
}
