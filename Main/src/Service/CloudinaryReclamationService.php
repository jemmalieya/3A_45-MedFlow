<?php

namespace App\Service;

use Cloudinary\Api\ApiResponse;
use Cloudinary\Cloudinary;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CloudinaryReclamationService
{
    private Cloudinary $cloudinary;

    public function __construct(Cloudinary $cloudinary)
    {
        $this->cloudinary = $cloudinary;
    }

    /**
     * @return array{
     *   public_id: string,
     *   resource_type?: string,
     *   format?: string,
     *   bytes?: int,
     *   secure_url?: string,
     *   url?: string
     * }
     */
    public function uploadProof(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $resourceType = str_starts_with($mime, 'image/') ? 'image' : 'raw';

        /** @var ApiResponse $result */
        $result = $this->cloudinary->uploadApi()->upload(
            $file->getPathname(),
            [
                'resource_type' => $resourceType,
                'folder' => 'medflow/reclamations',
            ]
        );

        $data = $result->getArrayCopy();

        // ✅ On met uniquement les clés obligatoires
        $out = [
            'public_id' => (string) $data['public_id'],
        ];

        // ✅ Les autres clés: on les ajoute seulement si elles existent (pas de null)
        if (isset($data['resource_type'])) {
            $out['resource_type'] = (string) $data['resource_type'];
        }
        if (isset($data['format'])) {
            $out['format'] = (string) $data['format'];
        }
        if (isset($data['bytes'])) {
            $out['bytes'] = (int) $data['bytes'];
        }
        if (isset($data['secure_url'])) {
            $out['secure_url'] = (string) $data['secure_url'];
        }
        if (isset($data['url'])) {
            $out['url'] = (string) $data['url'];
        }

        return $out;
    }

    public function delete(string $publicId, string $resourceType = 'image'): void
    {
        $this->cloudinary->uploadApi()->destroy($publicId, [
            'resource_type' => $resourceType,
        ]);
    }
}