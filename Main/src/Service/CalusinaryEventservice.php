<?php

namespace App\Service;

use Cloudinary\Cloudinary;
use Cloudinary\Api\ApiResponse;

final class CalusinaryEventservice
{
    private Cloudinary $cloudinary;

    public function __construct(string $cloudinaryUrl)
    {
        $this->cloudinary = new Cloudinary($cloudinaryUrl);
    }

    /**
     * @return array{secure_url:string, public_id:string}
     */
    public function upload(string $localPath, string $folder = 'medflow/events'): array
    {
        /** @var ApiResponse $result */
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