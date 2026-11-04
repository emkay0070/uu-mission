<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * MediaService — handles file upload, validation, storage and processing.
 *
 * Provides a unified interface for media operations across the platform.
 */
class MediaService
{
    protected ImageManager $imageManager;

    // Allowed MIME types by media category
    protected array $allowedMimeTypes = [
        'image' => [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
        ],
        'video' => [
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'video/x-msvideo',
        ],
        'audio' => [
            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/ogg',
            'audio/webm',
        ],
        'document' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ];

    // Maximum file sizes in bytes (50MB default)
    protected array $maxFileSizes = [
        'image' => 50 * 1024 * 1024,
        'video' => 500 * 1024 * 1024,
        'audio' => 50 * 1024 * 1024,
        'document' => 25 * 1024 * 1024,
    ];

    public function __construct()
    {
        $this->imageManager = new ImageManager(new Driver());
    }

    /**
     * Upload and process a media file.
     */
    public function upload(UploadedFile $file, array $options = []): Media
    {
        $this->validateFile($file);

        $mediaType = $this->determineMediaType($file);
        $disk = $options['disk'] ?? 'local';
        $visibility = $options['visibility'] ?? 'private';

        // Generate unique filename
        $storedFilename = $this->generateStoredFilename($file);
        $storagePath = $this->getStoragePath($mediaType, $storedFilename);

        // Store the file
        $file->storeAs(dirname($storagePath), basename($storagePath), [
            'disk' => $disk,
        ]);

        // Extract metadata
        $metadata = $this->extractMetadata($file, $mediaType);

        // Generate thumbnail for images
        $thumbnailPath = null;
        if ($mediaType === 'image') {
            $thumbnailPath = $this->generateThumbnail($file, $disk, $storagePath);
        }

        // Create media record
        $media = Media::create([
            'uuid' => Str::uuid(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'mime_type' => $file->getMimeType(),
            'media_type' => $mediaType,
            'storage_disk' => $disk,
            'storage_path' => $storagePath,
            'thumbnail_path' => $thumbnailPath,
            'file_size' => $file->getSize(),
            'width' => $metadata['width'] ?? null,
            'height' => $metadata['height'] ?? null,
            'duration' => $metadata['duration'] ?? null,
            'title' => $options['title'] ?? null,
            'caption' => $options['caption'] ?? null,
            'uploaded_by_user_id' => $options['uploaded_by_user_id'] ?? auth()->id(),
            'capture_date' => $options['capture_date'] ?? null,
            'visibility' => $visibility,
            'processing_status' => 'completed',
            'requires_consent' => $options['requires_consent'] ?? false,
            'consent_obtained' => $options['consent_obtained'] ?? false,
            'consent_notes' => $options['consent_notes'] ?? null,
        ]);

        return $media;
    }

    /**
     * Validate uploaded file.
     */
    protected function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('File upload is invalid.');
        }

        $mediaType = $this->determineMediaType($file);
        $mimeType = $file->getMimeType();

        if (!in_array($mimeType, $this->allowedMimeTypes[$mediaType] ?? [])) {
            throw new \InvalidArgumentException("MIME type {$mimeType} is not allowed for {$mediaType} files.");
        }

        $maxSize = $this->maxFileSizes[$mediaType] ?? 50 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException("File size exceeds maximum allowed size of " . $this->formatBytes($maxSize));
        }
    }

    /**
     * Determine media type from MIME type.
     */
    protected function determineMediaType(UploadedFile $file): string
    {
        $mimeType = $file->getMimeType();

        foreach ($this->allowedMimeTypes as $type => $mimes) {
            if (in_array($mimeType, $mimes)) {
                return $type;
            }
        }

        return 'document';
    }

    /**
     * Generate unique stored filename.
     */
    protected function generateStoredFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $basename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        
        return $basename . '_' . Str::random(8) . '.' . $extension;
    }

    /**
     * Get storage path for media type.
     */
    protected function getStoragePath(string $mediaType, string $filename): string
    {
        return "media/{$mediaType}/" . date('Y/m') . '/' . $filename;
    }

    /**
     * Extract metadata from file.
     */
    protected function extractMetadata(UploadedFile $file, string $mediaType): array
    {
        $metadata = [];

        if ($mediaType === 'image') {
            try {
                $image = $this->imageManager->read($file);
                $metadata['width'] = $image->width();
                $metadata['height'] = $image->height();
            } catch (\Exception $e) {
                // If we can't read the image, dimensions will remain null
            }
        }

        // Video/audio duration extraction would require ffprobe or similar
        // For V1, we'll leave duration as null and can add later if needed

        return $metadata;
    }

    /**
     * Generate thumbnail for image.
     */
    protected function generateThumbnail(UploadedFile $file, string $disk, string $originalPath): ?string
    {
        try {
            $image = $this->imageManager->read($file);
            
            // Resize to thumbnail dimensions (max 400px)
            $image->resizeDown(400, 400);
            
            // Generate thumbnail path
            $pathInfo = pathinfo($originalPath);
            $thumbnailPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];
            
            // Store thumbnail
            Storage::disk($disk)->put($thumbnailPath, $image->toJpeg(quality: 80));
            
            return $thumbnailPath;
        } catch (\Exception $e) {
            // Thumbnail generation failed, but original file is still valid
            return null;
        }
    }

    /**
     * Delete media file and thumbnail from storage.
     * Only called when media is permanently deleted (not soft deleted).
     */
    public function deleteFiles(Media $media): void
    {
        $disk = $media->storage_disk;

        // Delete original file
        if (Storage::disk($disk)->exists($media->storage_path)) {
            Storage::disk($disk)->delete($media->storage_path);
        }

        // Delete thumbnail
        if ($media->thumbnail_path && Storage::disk($disk)->exists($media->thumbnail_path)) {
            Storage::disk($disk)->delete($media->thumbnail_path);
        }
    }

    /**
     * Check if media can be safely deleted (no other attachments exist).
     */
    public function canDeletePhysicalFile(Media $media): bool
    {
        // Check if media has any attachments
        return $media->attachments()->count() === 0;
    }

    /**
     * Format bytes to human-readable format.
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get public URL for media (enforces visibility).
     */
    public function getUrl(Media $media): ?string
    {
        return $media->getUrl();
    }

    /**
     * Get thumbnail URL for media (enforces visibility).
     */
    public function getThumbnailUrl(Media $media): ?string
    {
        return $media->getThumbnailUrl();
    }
}
