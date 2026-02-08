<?php

namespace App\Service;

use Twilio\Rest\Client;

class TwilioSmsService
{
    private Client $client;
    private string $from;

    public function __construct()
    {
        $sid   = $_ENV['TWILIO_ACCOUNT_SID'] ?? '';
        $token = $_ENV['TWILIO_AUTH_TOKEN'] ?? '';
        $this->from = $_ENV['TWILIO_FROM'] ?? '';

        if (!$sid || !$token || !$this->from) {
            throw new \RuntimeException('Twilio env manquant (SID/TOKEN/FROM).');
        }

        $this->client = new Client($sid, $token);
    }

    public function send(string $to, string $message): void
    {
        $this->client->messages->create($to, [
            'from' => $this->from,
            'body' => $message,
        ]);
    }
}
