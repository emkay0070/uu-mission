<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\ResourceDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ResourceDownloadController — tracks offline downloads with version awareness.
 */
class ResourceDownloadController extends Controller
{
    /**
     * List the user's downloaded resources.
     *
     * GET /api/v1/downloaded-resources
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'per_page' => 'nullable|integer|between:5,100',
        ]);

        $downloads = ResourceDownload::with(['resource', 'resource.category', 'resource.currentVersion', 'resourceVersion'])
            ->forUser($user->id)
            ->completed()
            ->orderByDesc('downloaded_at')
            ->paginate($request->input('per_page', 20));

        // Add outdated flag to each download
        $downloads->getCollection()->transform(function ($download) {
            $download->is_outdated = $download->isOutdated();

            return $download;
        });

        return response()->json($downloads);
    }

    /**
     * Record a download for offline access.
     *
     * POST /api/v1/resources/{resource}/download-record
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        if (! $resource->hasCurrentVersion()) {
            return response()->json(['message' => 'No file available for this resource'], 404);
        }

        $request->validate([
            'version_id' => 'nullable|integer|exists:resource_versions,id',
            'file_size' => 'required|integer|min:0',
        ]);

        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $download = ResourceDownload::updateOrCreate(
            [
                'user_id' => Auth::id(),
                'resource_id' => $resource->id,
            ],
            [
                'resource_version_id' => $versionId,
                'file_size' => $request->file_size,
                'download_status' => 'completed',
                'downloaded_at' => now(),
                'last_accessed_at' => now(),
            ]
        );

        return response()->json($download, 201);
    }

    /**
     * Update last accessed time for a downloaded resource.
     *
     * PATCH /api/v1/resources/{resource}/download-record
     */
    public function update(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $download = ResourceDownload::where('user_id', Auth::id())
            ->where('resource_id', $resource->id)
            ->first();

        if (! $download) {
            return response()->json(['message' => 'Download not found'], 404);
        }

        $download->update([
            'last_accessed_at' => now(),
        ]);

        $download->is_outdated = $download->isOutdated();

        return response()->json($download);
    }

    /**
     * Remove a download record (e.g., to free up space).
     *
     * DELETE /api/v1/resources/{resource}/download-record
     */
    public function destroy(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $download = ResourceDownload::where('user_id', Auth::id())
            ->where('resource_id', $resource->id)
            ->first();

        if (! $download) {
            return response()->json(['message' => 'Download not found'], 404);
        }

        $download->delete();

        return response()->json(['message' => 'Download record removed']);
    }

    /**
     * Check download status for a resource.
     *
     * GET /api/v1/resources/{resource}/download-status
     */
    public function status(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $download = ResourceDownload::where('user_id', Auth::id())
            ->where('resource_id', $resource->id)
            ->where('download_status', 'completed')
            ->first();

        if (! $download) {
            return response()->json([
                'is_downloaded' => false,
                'downloaded_at' => null,
                'resource_version_id' => null,
                'is_outdated' => false,
            ]);
        }

        return response()->json([
            'is_downloaded' => true,
            'downloaded_at' => $download->downloaded_at,
            'last_accessed_at' => $download->last_accessed_at,
            'resource_version_id' => $download->resource_version_id,
            'is_outdated' => $download->isOutdated(),
            'file_size' => $download->file_size,
        ]);
    }

    private function ensureVersionBelongsToResource(Resource $resource, mixed $versionId): void
    {
        if ($versionId === null || ! $resource->versions()->whereKey($versionId)->exists()) {
            abort(404, 'Version not found for this resource');
        }
    }
}
