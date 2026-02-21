<?php

namespace App\Service;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class QrCodeService
{
    public function __construct(
        private string $projectDir,
        private string $appSecret
    ) {}

    public function generatePng(string $data, string $fileName): string
    {
        $dir = $this->projectDir . '/public/qrcodes';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $qrCode = new QrCode($data);
        $writer = new PngWriter();

        $result = $writer->write($qrCode);

        $path = $dir . '/' . $fileName;
        $result->saveToFile($path);

        return '/qrcodes/' . $fileName;
    }

    public function makeToken(string $type, int $id): string
    {
        return hash('sha256', $type . '|' . $id . '|' . $this->appSecret);
    }
}