<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class VonageSmsService
{
    public function __construct(
        private HttpClientInterface $client
    ) {}

    public function sendSms(string $to, string $message): array
    {
        // Ensure international format
        $to = trim($to);
        if ($to !== '' && $to[0] !== '+') {
            $to = '+' . $to;
        }

        $response = $this->client->request('POST',
            'https://rest.nexmo.com/sms/json',
            [
                'body' => [
                    'api_key'    => $_ENV['VONAGE_API_KEY'],
                    'api_secret' => $_ENV['VONAGE_API_SECRET'],
                    'to'         => $to,
                    'from'       => $_ENV['VONAGE_FROM'],
                    'text'       => $message,
                ]
            ]
        );

        return $response->toArray();
    }
}
