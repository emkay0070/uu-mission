<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\ResourceNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ResourceNoteController — user's notes on resources.
 */
class ResourceNoteController extends Controller
{
    /**
     * List the user's notes for a resource.
     *
     * GET /api/v1/resources/{resource}/notes
     * ?page_number=5
     */
    public function index(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);
        $request->validate(['version_id' => 'nullable|integer|exists:resource_versions,id', 'page_number' => 'nullable|integer|min:1']);
        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $query = ResourceNote::where('resource_id', $resource->id)
            ->where('user_id', Auth::id())
            ->where('resource_version_id', $versionId);

        if ($request->filled('page_number')) {
            $query->onPage($request->page_number);
        }

        $notes = $query->orderBy('created_at', 'desc')->get();

        return response()->json($notes);
    }

    /**
     * Create a note.
     *
     * POST /api/v1/resources/{resource}/notes
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $request->validate([
            'version_id' => 'nullable|integer|exists:resource_versions,id',
            'content' => 'required|string',
            'page_number' => 'required|integer|min:1',
            'position_data' => 'nullable|array',
        ]);
        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $note = ResourceNote::create([
            'resource_id' => $resource->id,
            'user_id' => Auth::id(),
            'resource_version_id' => $versionId,
            'content' => $request->content,
            'page_number' => $request->page_number,
            'position_data' => $request->position_data,
        ]);

        return response()->json($note, 201);
    }

    /**
     * Update a note.
     *
     * PATCH /api/v1/resources/{resource}/notes/{note}
     */
    public function update(Request $request, Resource $resource, ResourceNote $note): JsonResponse
    {
        $this->authorize('view', $resource);

        // Verify note belongs to user and resource
        if ($note->user_id !== Auth::id() || $note->resource_id !== $resource->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'content' => 'required|string',
        ]);

        $note->update($request->only(['content']));

        return response()->json($note);
    }

    /**
     * Delete a note.
     *
     * DELETE /api/v1/resources/{resource}/notes/{note}
     */
    public function destroy(Request $request, Resource $resource, ResourceNote $note): JsonResponse
    {
        $this->authorize('view', $resource);

        // Verify note belongs to user and resource
        if ($note->user_id !== Auth::id() || $note->resource_id !== $resource->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $note->delete();

        return response()->json(['message' => 'Note deleted']);
    }

    private function ensureVersionBelongsToResource(Resource $resource, mixed $versionId): void
    {
        if ($versionId === null || ! $resource->versions()->whereKey($versionId)->exists()) {
            abort(404, 'Version not found for this resource');
        }
    }
}
