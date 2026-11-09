<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\ResourceHighlight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ResourceHighlightController — user's highlights on resources.
 */
class ResourceHighlightController extends Controller
{
    /**
     * List the user's highlights for a resource.
     *
     * GET /api/v1/resources/{resource}/highlights
     * ?page_number=5
     */
    public function index(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);
        $request->validate(['version_id' => 'nullable|integer|exists:resource_versions,id', 'page_number' => 'nullable|integer|min:1']);
        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $query = ResourceHighlight::where('resource_id', $resource->id)
            ->where('user_id', Auth::id())
            ->where('resource_version_id', $versionId);

        if ($request->filled('page_number')) {
            $query->onPage($request->page_number);
        }

        $highlights = $query->orderBy('created_at', 'desc')->get();

        return response()->json($highlights);
    }

    /**
     * Create a highlight.
     *
     * POST /api/v1/resources/{resource}/highlights
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $request->validate([
            'version_id' => 'nullable|integer|exists:resource_versions,id',
            'selected_text' => 'required|string',
            'page_number' => 'required|integer|min:1',
            'bounding_box' => 'required|array',
            'color' => 'nullable|string|max:20',
            'zoom_level' => 'nullable|numeric',
        ]);
        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $highlight = ResourceHighlight::create([
            'resource_id' => $resource->id,
            'user_id' => Auth::id(),
            'resource_version_id' => $versionId,
            'selected_text' => $request->selected_text,
            'page_number' => $request->page_number,
            'bounding_box' => $request->bounding_box,
            'color' => $request->color ?? '#ffff00',
            'zoom_level' => $request->zoom_level,
        ]);

        return response()->json($highlight, 201);
    }

    /**
     * Update a highlight.
     *
     * PATCH /api/v1/resources/{resource}/highlights/{highlight}
     */
    public function update(Request $request, Resource $resource, ResourceHighlight $highlight): JsonResponse
    {
        $this->authorize('view', $resource);

        // Verify highlight belongs to user and resource
        if ($highlight->user_id !== Auth::id() || $highlight->resource_id !== $resource->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'color' => 'nullable|string|max:20',
        ]);

        $highlight->update($request->only(['color']));

        return response()->json($highlight);
    }

    /**
     * Delete a highlight.
     *
     * DELETE /api/v1/resources/{resource}/highlights/{highlight}
     */
    public function destroy(Request $request, Resource $resource, ResourceHighlight $highlight): JsonResponse
    {
        $this->authorize('view', $resource);

        // Verify highlight belongs to user and resource
        if ($highlight->user_id !== Auth::id() || $highlight->resource_id !== $resource->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $highlight->delete();

        return response()->json(['message' => 'Highlight deleted']);
    }

    private function ensureVersionBelongsToResource(Resource $resource, mixed $versionId): void
    {
        if ($versionId === null || ! $resource->versions()->whereKey($versionId)->exists()) {
            abort(404, 'Version not found for this resource');
        }
    }
}
