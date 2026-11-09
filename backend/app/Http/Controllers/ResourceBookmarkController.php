<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\ResourceBookmark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ResourceBookmarkController — user's bookmarks on resources.
 */
class ResourceBookmarkController extends Controller
{
    /**
     * List the user's bookmarks for a resource.
     *
     * GET /api/v1/resources/{resource}/bookmarks
     */
    public function index(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);
        $request->validate(['version_id' => 'nullable|integer|exists:resource_versions,id']);
        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $bookmarks = ResourceBookmark::where('resource_id', $resource->id)
            ->where('user_id', Auth::id())
            ->where('resource_version_id', $versionId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($bookmarks);
    }

    /**
     * Create a bookmark.
     *
     * POST /api/v1/resources/{resource}/bookmarks
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $request->validate([
            'version_id' => 'nullable|integer|exists:resource_versions,id',
            'page_number' => 'required|integer|min:1',
            'position_data' => 'nullable|array',
            'title' => 'required|string|max:255',
        ]);
        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $bookmark = ResourceBookmark::create([
            'resource_id' => $resource->id,
            'user_id' => Auth::id(),
            'resource_version_id' => $versionId,
            'page_number' => $request->page_number,
            'position_data' => $request->position_data,
            'title' => $request->title,
        ]);

        return response()->json($bookmark, 201);
    }

    /**
     * Delete a bookmark.
     *
     * DELETE /api/v1/resources/{resource}/bookmarks/{bookmark}
     */
    public function destroy(Request $request, Resource $resource, ResourceBookmark $bookmark): JsonResponse
    {
        $this->authorize('view', $resource);

        // Verify bookmark belongs to user and resource
        if ($bookmark->user_id !== Auth::id() || $bookmark->resource_id !== $resource->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $bookmark->delete();

        return response()->json(['message' => 'Bookmark deleted']);
    }

    private function ensureVersionBelongsToResource(Resource $resource, mixed $versionId): void
    {
        if ($versionId === null || ! $resource->versions()->whereKey($versionId)->exists()) {
            abort(404, 'Version not found for this resource');
        }
    }
}
