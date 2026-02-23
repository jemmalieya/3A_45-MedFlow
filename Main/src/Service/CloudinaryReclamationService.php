<?php

namespace App\Service;

use Cloudinary\Cloudinary;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CloudinaryReclamationService
{
    private Cloudinary $cloudinary;

    public function __construct(Cloudinary $cloudinary)
    {
        $this->cloudinary = $cloudinary;
    }

public function uploadProof(UploadedFile $file): array
{
    $mime = (string) $file->getMimeType();
    $resourceType = (str_starts_with($mime, 'image/')) ? 'image' : 'raw';

    $result = $this->cloudinary->uploadApi()->upload(
        $file->getPathname(),
        [
            'resource_type' => $resourceType,
            'folder' => 'medflow/reclamations',
        ]
    );

    // ✅ Cloudinary v2 renvoie souvent un ApiResponse (ArrayObject)
    return $result instanceof \ArrayObject ? $result->getArrayCopy() : (array) $result;
}

    public function delete(string $publicId, string $resourceType = 'image'): void
    {
        $this->cloudinary->uploadApi()->destroy($publicId, [
            'resource_type' => $resourceType,
        ]);
    }
}