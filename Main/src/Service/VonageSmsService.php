<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class VonageSmsService
{
    public function __construct(
        private HttpClientInterface $client
    ) {}

    /**
     * Vonage / Nexmo response (simplified + enough for PHPStan)
     *
     * @return array{
     *   message-count?: string,
     *   messages?: array<int, array{
     *     to?: string,
     *     message-id?: string,
     *     status?: string,
     *     'error-text'?: string,
     *     remaining-balance?: string,
     *     message-price?: string,
     *     network?: string
     *   }>
     * }
     */
    public function sendSms(string $to, string $message): array
    {
        // Ensure international format
        $to = trim($to);
        if ($to !== '' && $to[0] !== '+') {
            $to = '+' . $to;
        }

        $response = $this->client->request(
            'POST',
            'https://rest.nexmo.com/sms/json',
            [
                'body' => [
                    'api_key'    => (string) ($_ENV['VONAGE_API_KEY'] ?? ''),
                    'api_secret' => (string) ($_ENV['VONAGE_API_SECRET'] ?? ''),
                    'to'         => $to,
                    'from'       => (string) ($_ENV['VONAGE_FROM'] ?? 'MedFlow'),
                    'text'       => $message,
                ],
            ]
        );

        /** @var array{
         *   message-count?: string,
         *   messages?: array<int, array{
         *     to?: string,
         *     message-id?: string,
         *     status?: string,
         *     'error-text'?: string,
         *     remaining-balance?: string,
         *     message-price?: string,
         *     network?: string
         *   }>
         * } $data
         */
        $data = $response->toArray(false);

        return $data;
    }
}