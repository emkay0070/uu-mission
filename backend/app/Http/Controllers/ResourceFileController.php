<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Services\ResourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ResourceFileController — secure file access for resources.
 */
class ResourceFileController extends Controller
{
    protected ResourceService $resourceService;

    public function __construct(ResourceService $resourceService)
    {
        $this->resourceService = $resourceService;
    }

    /**
     * Preview a resource file (inline display).
     *
     * GET /api/v1/resources/{resource}/preview
     */
    public function preview(Request $request, Resource $resource): StreamedResponse
    {
        $this->authorize('view', $resource);

        if (! $resource->hasCurrentVersion()) {
            abort(404, 'No file available for this resource');
        }

        $version = $resource->currentVersion;
        $disk = $version->storage_disk;
        $path = $version->storage_path;

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'File not found');
        }

        return Storage::disk($disk)->response($path, $version->original_filename, [
            'Content-Type' => $version->mime_type,
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * Download a resource file.
     *
     * GET /api/v1/resources/{resource}/download
     */
    public function download(Request $request, Resource $resource): StreamedResponse
    {
        $this->authorize('view', $resource);

        if (! $resource->hasCurrentVersion()) {
            abort(404, 'No file available for this resource');
        }

        $version = $resource->currentVersion;
        $disk = $version->storage_disk;
        $path = $version->storage_path;

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'File not found');
        }

        return Storage::disk($disk)->download($path, $version->original_filename);
    }

    /**
     * Preview a specific version of a resource.
     *
     * GET /api/v1/resources/{resource}/versions/{version}/preview
     */
    public function previewVersion(Request $request, Resource $resource, ResourceVersion $version): StreamedResponse
    {
        $this->authorize('view', $resource);

        // Verify version belongs to this resource
        if ($version->resource_id !== $resource->id) {
            abort(404, 'Version not found for this resource');
        }

        $disk = $version->storage_disk;
        $path = $version->storage_path;

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'File not found');
        }

        return Storage::disk($disk)->response($path, $version->original_filename, [
            'Content-Type' => $version->mime_type,
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * Download a specific version of a resource.
     *
     * GET /api/v1/resources/{resource}/versions/{version}/download
     */
    public function downloadVersion(Request $request, Resource $resource, ResourceVersion $version): StreamedResponse
    {
        $this->authorize('view', $resource);

        // Verify version belongs to this resource
        if ($version->resource_id !== $resource->id) {
            abort(404, 'Version not found for this resource');
        }

        $disk = $version->storage_disk;
        $path = $version->storage_path;

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'File not found');
        }

        return Storage::disk($disk)->download($path, $version->original_filename);
    }

    /**
     * Get resource thumbnail.
     *
     * GET /api/v1/resources/{resource}/thumbnail
     */
    public function thumbnail(Request $request, Resource $resource): StreamedResponse
    {
        $this->authorize('view', $resource);

        if (! $resource->thumbnail_storage_disk || ! $resource->thumbnail_storage_path) {
            abort(404, 'No thumbnail available');
        }

        $disk = $resource->thumbnail_storage_disk;
        $path = $resource->thumbnail_storage_path;

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'Thumbnail not found');
        }

        return Storage::disk($disk)->response($path, null, [
            'Content-Type' => Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream',
            'Content-Disposition' => 'inline',
        ]);
    }
}
