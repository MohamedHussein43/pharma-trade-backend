<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class CloudinaryService
{
    private Cloudinary $cloudinary;

    public function __construct()
    {
        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                'api_key'    => env('CLOUDINARY_API_KEY'),
                'api_secret' => env('CLOUDINARY_API_SECRET'),
            ],
            'url' => ['secure' => true],
        ]);
    }

    /**
     * Upload a supplier logo and return the secure URL.
     * Automatically resizes to 400x400, converts to WebP.
     */
    public function uploadSupplierLogo(UploadedFile $file, string $supplierId): string
    {
        $result = $this->cloudinary->uploadApi()->upload(
            $file->getRealPath(),
            [
                'folder'         => 'suppliers/logos',
                'public_id'      => "supplier_{$supplierId}",
                'overwrite'      => true,           // replaces old logo on update
                'resource_type'  => 'image',
                'transformation' => [
                    'width'   => 400,
                    'height'  => 400,
                    'crop'    => 'fill',             // smart crop to square
                    'quality' => 'auto',             // auto-optimize quality
                    'fetch_format' => 'auto',        // serve WebP to browsers that support it
                ],
            ]
        );

        return $result['secure_url'];
    }

    /**
     * Delete a supplier logo from Cloudinary.
     * Call this when supplier is deleted or logo is replaced.
     */
    public function deleteSupplierLogo(string $supplierId): void
    {
        try {
            $this->cloudinary->uploadApi()->destroy("suppliers/logos/supplier_{$supplierId}");
        } catch (\Exception $e) {
            // Log but don't throw — deletion failure shouldn't block the main operation
            Log::warning("Cloudinary delete failed for supplier_{$supplierId}: " . $e->getMessage());
        }
    }
}