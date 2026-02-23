<?php

namespace App\Service;

use Cloudinary\Cloudinary;

final class CalusinaryEventservice
{
    private Cloudinary $cloudinary;

    public function __construct(string $cloudinaryUrl)
    {
        $this->cloudinary = new Cloudinary($cloudinaryUrl);
    }

    public function upload(string $localPath, string $folder = 'medflow/events'): array
    {
        $result = $this->cloudinary->uploadApi()->upload($localPath, [
            'folder' => $folder,
            'resource_type' => 'auto',
        ]);

        return [
            'secure_url' => (string) ($result['secure_url'] ?? ''),
            'public_id'  => (string) ($result['public_id'] ?? ''),
        ];
    }

    public function destroy(string $publicId): void
    {
        $this->cloudinary->uploadApi()->destroy($publicId, ['resource_type' => 'image']);
        $this->cloudinary->uploadApi()->destroy($publicId, ['resource_type' => 'raw']);
        $this->cloudinary->uploadApi()->destroy($publicId, ['resource_type' => 'video']);
    }
}