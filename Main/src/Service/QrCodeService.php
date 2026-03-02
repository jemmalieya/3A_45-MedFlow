<?php

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

final class QrCodeService
{
    private string $publicDir;

    public function __construct(string $projectDir)
    {
        $this->publicDir = rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public';
    }

    /**
     * @return array{publicPath: string, absolutePath: string, dataUri: string}
     */
    public function generateSvg(string $data, string $filenameWithoutExt, int $size = 320, int $margin = 10): array
    {
        $this->ensureQrDirExists();

        $absolutePath = $this->publicDir . DIRECTORY_SEPARATOR . 'qrcodes' . DIRECTORY_SEPARATOR . $filenameWithoutExt . '.svg';
        $publicPath   = '/qrcodes/' . $filenameWithoutExt . '.svg';

        $builder = new Builder(
            writer: new SvgWriter(),
            data: $data,
            encoding: new Encoding('UTF-8'),
            size: $size,
            margin: $margin
        );

        $result = $builder->build();
        $result->saveToFile($absolutePath);

        return [
            'publicPath'   => $publicPath,
            'absolutePath' => $absolutePath,
            'dataUri'      => $result->getDataUri(),
        ];
    }

    /**
     * @return array{publicPath: string, absolutePath: string, dataUri: string}
     */
    public function generatePng(string $data, string $filenameWithoutExt, int $size = 320, int $margin = 10): array
    {
        $this->ensureQrDirExists();

        $absolutePath = $this->publicDir . DIRECTORY_SEPARATOR . 'qrcodes' . DIRECTORY_SEPARATOR . $filenameWithoutExt . '.png';
        $publicPath   = '/qrcodes/' . $filenameWithoutExt . '.png';

        $builder = new Builder(
            writer: new PngWriter(),
            data: $data,
            encoding: new Encoding('UTF-8'),
            size: $size,
            margin: $margin
        );

        $result = $builder->build();
        $result->saveToFile($absolutePath);

        return [
            'publicPath'   => $publicPath,
            'absolutePath' => $absolutePath,
            'dataUri'      => $result->getDataUri(),
        ];
    }

    private function ensureQrDirExists(): void
    {
        $dir = $this->publicDir . DIRECTORY_SEPARATOR . 'qrcodes';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}