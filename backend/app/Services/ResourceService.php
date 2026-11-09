<?php

namespace App\Services;

use App\Domain\WorkflowDefinitions;
use App\Jobs\ProcessDocumentVersion;
use App\Models\OrgUnit;
use App\Models\Resource;
use App\Models\ResourceTag;
use App\Models\ResourceVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ResourceService — handles resource creation, version management, and file operations.
 *
 * Provides transactional resource creation with initial version, file replacement
 * with version history, and scope validation.
 *
 * Storage is independent of the Media Library - resources use their own
 * storage paths and lifecycle management.
 */
class ResourceService
{
    // Allowed MIME types for resources
    protected array $allowedMimeTypes = [
        'pdf' => [
            'application/pdf',
        ],
        'document' => [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
        'image' => [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
        ],
        'audio' => [
            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/ogg',
        ],
        'video' => [
            'video/mp4',
            'video/webm',
        ],
    ];

    // Maximum file sizes in bytes (50MB default for most, 100MB for video)
    protected array $maxFileSizes = [
        'pdf' => 50 * 1024 * 1024,
        'document' => 25 * 1024 * 1024,
        'image' => 20 * 1024 * 1024,
        'audio' => 50 * 1024 * 1024,
        'video' => 100 * 1024 * 1024,
    ];

    /** Store an optional thumbnail on the private Resources disk. */
    public function saveThumbnail(Resource $resource, UploadedFile $thumbnail): Resource
    {
        if (! $thumbnail->isValid() || ! str_starts_with((string) $thumbnail->getMimeType(), 'image/')) {
            throw ValidationException::withMessages(['thumbnail' => 'A valid image thumbnail is required.']);
        }
        if ($thumbnail->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['thumbnail' => 'Thumbnail must be 5 MB or smaller.']);
        }

        $disk = 'local';
        $path = $thumbnail->storeAs('resources/thumbnails', Str::uuid().'.'.$thumbnail->guessExtension(), $disk);
        if ($path === false) {
            throw ValidationException::withMessages(['thumbnail' => 'Unable to store the uploaded thumbnail.']);
        }
        $oldDisk = $resource->thumbnail_storage_disk;
        $oldPath = $resource->thumbnail_storage_path;
        $resource->update(['thumbnail_storage_disk' => $disk, 'thumbnail_storage_path' => $path]);

        if ($oldDisk && $oldPath && Storage::disk($oldDisk)->exists($oldPath)) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return $resource->fresh();
    }

    /**
     * Create a new resource with its initial version in a single transaction.
     *
     * @param  array  $data  Resource metadata (title, description, category_id, etc.)
     * @param  UploadedFile  $file  The uploaded file
     * @param  int  $userId  ID of the user creating the resource
     *
     * @throws ValidationException
     */
    public function createResource(array $data, UploadedFile $file, int $userId): Resource
    {
        // Validate file first (outside transaction)
        $this->validateFile($file);

        // Validate scope requirements before storing anything
        $this->validateScope($data);

        // Determine media type
        $mediaType = $this->determineMediaType($file);

        // Generate storage path
        $storedFilename = $this->generateStoredFilename($file);
        $storagePath = $this->getStoragePath($mediaType, $storedFilename);
        $disk = 'local';

        // Store the file outside transaction
        $storedPath = $file->storeAs(dirname($storagePath), basename($storagePath), [
            'disk' => $disk,
        ]);
        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'file' => 'Unable to store the uploaded file.',
            ]);
        }

        try {
            $version = null;

            $resource = DB::transaction(function () use ($data, $file, $userId, $storagePath, $disk, $mediaType, &$version) {
                // Create resource record
                $resource = Resource::create([
                    'uuid' => Str::uuid(),
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'category_id' => $data['category_id'] ?? null,
                    'language' => $data['language'] ?? 'en',
                    'intended_audience' => $data['intended_audience'] ?? null,
                    'publisher' => $data['publisher'] ?? null,
                    'publication_date' => $data['publication_date'] ?? null,
                    'version_number' => '1.0',
                    'status' => 'draft',
                    'origin_assignment_id' => $data['origin_assignment_id'] ?? null,
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                ]);

                // Create initial version
                $version = ResourceVersion::create([
                    'resource_id' => $resource->id,
                    'version_number' => '1.0',
                    'storage_disk' => $disk,
                    'storage_path' => $storagePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'media_type' => $mediaType,
                    'file_size' => $file->getSize(),
                ]);

                // Set current version
                $resource->current_version_id = $version->id;
                $resource->save();

                // Add tags if provided
                if (! empty($data['tags'])) {
                    foreach ($data['tags'] as $tag) {
                        ResourceTag::create([
                            'resource_id' => $resource->id,
                            'tag' => $tag,
                        ]);
                    }
                }

                return $resource;
            });

            // Dispatch document processing only once the transaction has committed.
            if ($version !== null && $version->media_type === 'pdf') {
                ProcessDocumentVersion::dispatch($version->id);
            }

            return $resource;
        } catch (\Exception $e) {
            // Clean up stored file if transaction fails
            if (Storage::disk($disk)->exists($storagePath)) {
                Storage::disk($disk)->delete($storagePath);
            }
            throw $e;
        }
    }

    /**
     * Update resource metadata (does not replace file).
     *
     * @throws ValidationException
     */
    public function updateResource(Resource $resource, array $data, int $userId): Resource
    {
        $resource->update(array_merge($data, [
            'updated_by_user_id' => $userId,
        ]));

        // Update tags if provided
        if (array_key_exists('tags', $data)) {
            $resource->tags()->delete();
            foreach ($data['tags'] ?? [] as $tag) {
                ResourceTag::create([
                    'resource_id' => $resource->id,
                    'tag' => $tag,
                ]);
            }
        }

        return $resource->fresh();
    }

    /**
     * Replace the file for a resource, creating a new version.
     *
     * @throws ValidationException
     */
    public function replaceFile(Resource $resource, UploadedFile $file, string $changeReason, int $userId): Resource
    {
        // Validate file first (outside transaction)
        $this->validateFile($file);

        // Determine media type
        $mediaType = $this->determineMediaType($file);

        // Generate storage path
        $storedFilename = $this->generateStoredFilename($file);
        $storagePath = $this->getStoragePath($mediaType, $storedFilename);
        $disk = 'local';

        // Store the new file outside transaction
        $storedPath = $file->storeAs(dirname($storagePath), basename($storagePath), [
            'disk' => $disk,
        ]);
        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'file' => 'Unable to store the uploaded file.',
            ]);
        }

        try {
            $newVersion = null;

            $resource = DB::transaction(function () use ($resource, $file, $changeReason, $userId, $storagePath, $disk, $mediaType, &$newVersion) {
                // Increment version number
                $currentVersionNumber = $resource->version_number;
                $newVersionNumber = $this->incrementVersionNumber($currentVersionNumber);

                // Create new version
                $newVersion = ResourceVersion::create([
                    'resource_id' => $resource->id,
                    'version_number' => $newVersionNumber,
                    'storage_disk' => $disk,
                    'storage_path' => $storagePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'media_type' => $mediaType,
                    'file_size' => $file->getSize(),
                    'change_reason' => $changeReason,
                    'replaced_by_user_id' => $userId,
                ]);

                // Update resource to point to new version
                $resource->current_version_id = $newVersion->id;
                $resource->version_number = $newVersionNumber;
                $resource->updated_by_user_id = $userId;
                $resource->save();

                return $resource->fresh();
            });

            // Dispatch document processing only once the transaction has committed.
            if ($newVersion !== null && $newVersion->media_type === 'pdf') {
                ProcessDocumentVersion::dispatch($newVersion->id);
            }

            return $resource;
        } catch (\Exception $e) {
            // Clean up stored file if transaction fails
            if (Storage::disk($disk)->exists($storagePath)) {
                Storage::disk($disk)->delete($storagePath);
            }
            throw $e;
        }
    }

    /**
     * Publish a resource (transition from draft/pending_review to published).
     *
     * @throws ValidationException
     */
    public function publishResource(Resource $resource, int $userId): Resource
    {
        $this->validateStatusTransition($resource->status, 'published');

        $resource->update([
            'status' => 'published',
            'published_at' => now(),
            'published_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        return $resource->fresh();
    }

    /**
     * Archive a resource (transition from published to archived).
     *
     * @throws ValidationException
     */
    public function archiveResource(Resource $resource, int $userId): Resource
    {
        $this->validateStatusTransition($resource->status, 'archived');

        $resource->update([
            'status' => 'archived',
            'archived_at' => now(),
            'updated_by_user_id' => $userId,
        ]);

        return $resource->fresh();
    }

    /**
     * Submit a resource for review (transition from draft to pending_review).
     *
     * @throws ValidationException
     */
    public function submitForReview(Resource $resource, int $userId): Resource
    {
        $this->validateStatusTransition($resource->status, 'pending_review');

        $resource->update([
            'status' => 'pending_review',
            'updated_by_user_id' => $userId,
        ]);

        return $resource->fresh();
    }

    /**
     * Return a resource to draft (transition from pending_review to draft).
     *
     * @throws ValidationException
     */
    public function returnToDraft(Resource $resource, int $userId): Resource
    {
        $this->validateStatusTransition($resource->status, 'draft');

        $resource->update([
            'status' => 'draft',
            'updated_by_user_id' => $userId,
        ]);

        return $resource->fresh();
    }

    /**
     * Validate that a status transition is allowed.
     *
     * @throws ValidationException
     */
    protected function validateStatusTransition(string $currentStatus, string $newStatus): void
    {
        $transitions = WorkflowDefinitions::RESOURCE_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($newStatus, $transitions)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition from {$currentStatus} to {$newStatus}",
            ]);
        }
    }

    /**
     * Validate uploaded file.
     *
     * @throws ValidationException
     */
    protected function validateFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => 'File upload is invalid.',
            ]);
        }

        $mediaType = $this->determineMediaType($file);
        $mimeType = $file->getMimeType();

        if (! in_array($mimeType, $this->allowedMimeTypes[$mediaType] ?? [])) {
            throw ValidationException::withMessages([
                'file' => "MIME type {$mimeType} is not allowed for {$mediaType} files.",
            ]);
        }

        $maxSize = $this->maxFileSizes[$mediaType] ?? 50 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            $maxSizeMB = round($maxSize / 1024 / 1024, 2);
            throw ValidationException::withMessages([
                'file' => "File size exceeds maximum allowed size of {$maxSizeMB}MB",
            ]);
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

        return $basename.'_'.Str::random(8).'.'.$extension;
    }

    /**
     * Get storage path for resource files.
     */
    protected function getStoragePath(string $mediaType, string $filename): string
    {
        return "resources/files/{$mediaType}/".date('Y/m').'/'.$filename;
    }

    /**
     * Increment version number (1.0 → 1.1 → 1.2 → 2.0).
     */
    protected function incrementVersionNumber(string $currentVersion): string
    {
        $parts = explode('.', $currentVersion);
        $major = (int) ($parts[0] ?? 1);
        $minor = (int) ($parts[1] ?? 0);

        $minor++;

        if ($minor > 99) {
            $major++;
            $minor = 0;
        }

        return "{$major}.{$minor}";
    }

    /**
     * Validate scope requirements for resource creation.
     *
     * @throws ValidationException
     */
    protected function validateScope(array $data): void
    {
        $scope = $data['visibility_scope'] ?? null;

        if ($scope === 'conference') {
            if (empty($data['scoped_org_unit_id'])) {
                throw ValidationException::withMessages([
                    'scoped_org_unit_id' => 'Conference scope requires scoped_org_unit_id',
                ]);
            }

            $orgUnit = OrgUnit::find($data['scoped_org_unit_id']);
            if (! $orgUnit || $orgUnit->type?->code !== 'conference') {
                throw ValidationException::withMessages([
                    'scoped_org_unit_id' => 'The selected organisation unit is not a conference.',
                ]);
            }
        }

        if ($scope === 'campaign') {
            if (empty($data['scoped_campaign_id'])) {
                throw ValidationException::withMessages([
                    'scoped_campaign_id' => 'Campaign scope requires scoped_campaign_id',
                ]);
            }
        }
    }

    /**
     * Delete resource files from storage (called when resource is permanently deleted).
     */
    public function deleteResourceFiles(Resource $resource): void
    {
        // Delete all version files
        foreach ($resource->versions as $version) {
            $disk = $version->storage_disk;
            if (Storage::disk($disk)->exists($version->storage_path)) {
                Storage::disk($disk)->delete($version->storage_path);
            }
        }

        // Delete thumbnail
        if ($resource->thumbnail_storage_disk && $resource->thumbnail_storage_path) {
            $disk = $resource->thumbnail_storage_disk;
            if (Storage::disk($disk)->exists($resource->thumbnail_storage_path)) {
                Storage::disk($disk)->delete($resource->thumbnail_storage_path);
            }
        }
    }
}
