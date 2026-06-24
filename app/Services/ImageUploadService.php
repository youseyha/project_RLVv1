<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager; 
use Intervention\Image\Drivers\Gd\Driver; 

class ImageUploadService
{
    protected $imageManager;

    public function __construct()
    {
        // Initialize ImageManager with GD driver
        $this->imageManager = new ImageManager(new Driver());
    }

    /**
     * Upload product image
     */
    public function uploadProductImage(UploadedFile $file, string $tenantId): string
    {
        // Validate image
        $this->validateImage($file);

        // Generate unique filename
        $filename = $this->generateFilename($file);

        // Directory path
        $directory = "products/{$tenantId}";

        // New v3 syntax
        $image = $this->imageManager->read($file);
        
        // Resize
        $image->scale(width: 800, height: 800);
        
        // Encode to JPEG
        $encoded = $image->toJpeg(quality: 85);

        // Store in public disk
        Storage::disk('public')->put(
            "{$directory}/{$filename}",
            (string) $encoded
        );

        // Return public URL
        return Storage::url("{$directory}/{$filename}");
    }

    /**
     * Upload category image
     */
    public function uploadCategoryImage(UploadedFile $file, string $tenantId): string
    {
        $this->validateImage($file);
        
        $filename = $this->generateFilename($file);
        $directory = "categories/{$tenantId}";

        // New v3 syntax
        $image = $this->imageManager->read($file);
        $image->scale(width: 400, height: 400);
        $encoded = $image->toJpeg(quality: 85);

        Storage::disk('public')->put(
            "{$directory}/{$filename}",
            (string) $encoded
        );

        return Storage::url("{$directory}/{$filename}");
    }

    /**
     * Delete image
     */
    public function deleteImage(?string $url): bool
    {
        if (!$url) return false;

        // Extract path from URL
        $path = str_replace('/storage/', '', parse_url($url, PHP_URL_PATH));

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->delete($path);
        }

        return false;
    }

    /**
     * Validate image
     */
    private function validateImage(UploadedFile $file): void
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if (!in_array($file->getMimeType(), $allowedMimes)) {
            throw new \Exception('Invalid image format. Allowed: JPEG, PNG, JPG, WEBP');
        }

        if ($file->getSize() > $maxSize) {
            throw new \Exception('Image size must be less than 5MB');
        }
    }

    /**
     * Generate unique filename
     */
    private function generateFilename(UploadedFile $file): string
    {
        return time() . '_' . Str::random(10) . '.jpg';
    }
}