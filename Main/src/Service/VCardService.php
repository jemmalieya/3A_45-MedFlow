<?php

namespace App\Service;

final class VCardService
{
    public function buildPharmacyVCard(array $data): string
    {
        $name    = $this->esc($data['name'] ?? 'Pharmacie MedFlow');
        $org     = $this->esc($data['org'] ?? 'MedFlow');
        $phone   = $this->esc($data['phone'] ?? '');
        $email   = $this->esc($data['email'] ?? '');
        $address = $this->esc($data['address'] ?? '');
        $city    = $this->esc($data['city'] ?? '');
        $zip     = $this->esc($data['zip'] ?? '');
        $country = $this->esc($data['country'] ?? 'TN');

        $adr = ";;{$address};{$city};;{$zip};{$country}";

        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            "FN:{$name}",
            "N:{$name};;;;",
            "ORG:{$org}",
        ];

        if ($phone !== '') $lines[] = "TEL;TYPE=CELL:{$phone}";
        if ($email !== '') $lines[] = "EMAIL;TYPE=INTERNET:{$email}";
        if (trim($address.$city.$zip.$country) !== '') $lines[] = "ADR;TYPE=WORK:{$adr}";

        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function esc(string $s): string
    {
        $s = str_replace(["\r\n", "\n", "\r"], ' ', $s);
        $s = str_replace([';', ',', ':'], ['\;', '\,', '\:'], $s);
        return trim($s);
    }
}