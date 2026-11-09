<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\ResourceSaved;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ResourceSavedController — user's saved resources (personal library).
 */
class ResourceSavedController extends Controller
{
    /**
     * List the user's saved resources.
     *
     * GET /api/v1/saved-resources
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'per_page' => 'nullable|integer|between:5,100',
        ]);

        $saved = ResourceSaved::with(['resource', 'resource.category', 'resource.currentVersion', 'resourceVersion'])
            ->forUser($user->id)
            ->orderByDesc('saved_at')
            ->paginate($request->input('per_page', 20));

        return response()->json($saved);
    }

    /**
     * Save a resource to the user's library.
     *
     * POST /api/v1/resources/{resource}/save
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $request->validate([
            'version_id' => 'nullable|integer|exists:resource_versions,id',
        ]);

        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $saved = ResourceSaved::updateOrCreate(
            [
                'user_id' => Auth::id(),
                'resource_id' => $resource->id,
            ],
            [
                'resource_version_id' => $versionId,
                'saved_at' => now(),
            ]
        );

        return response()->json($saved, 201);
    }

    /**
     * Remove a resource from the user's library.
     *
     * DELETE /api/v1/resources/{resource}/save
     */
    public function destroy(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $saved = ResourceSaved::where('user_id', Auth::id())
            ->where('resource_id', $resource->id)
            ->first();

        if (! $saved) {
            return response()->json(['message' => 'Resource not saved'], 404);
        }

        $saved->delete();

        return response()->json(['message' => 'Resource removed from library']);
    }

    /**
     * Check if a resource is saved by the user.
     *
     * GET /api/v1/resources/{resource}/saved-status
     */
    public function status(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $saved = ResourceSaved::where('user_id', Auth::id())
            ->where('resource_id', $resource->id)
            ->first();

        return response()->json([
            'is_saved' => $saved !== null,
            'saved_at' => $saved?->saved_at,
            'resource_version_id' => $saved?->resource_version_id,
        ]);
    }

    private function ensureVersionBelongsToResource(Resource $resource, mixed $versionId): void
    {
        if ($versionId === null || ! $resource->versions()->whereKey($versionId)->exists()) {
            abort(404, 'Version not found for this resource');
        }
    }
}
