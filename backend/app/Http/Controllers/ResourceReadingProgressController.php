<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\ResourceReadingProgress;
use App\Services\ResourceVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ResourceReadingProgressController extends Controller
{
    public function __construct(private ResourceVisibilityService $visibility) {}

    /** Get this user's progress for a version of an accessible resource. */
    public function show(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);
        $request->validate(['version_id' => 'nullable|integer|exists:resource_versions,id']);
        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $progress = ResourceReadingProgress::where('resource_id', $resource->id)
            ->where('user_id', Auth::id())
            ->where('resource_version_id', $versionId)
            ->first();

        return response()->json($progress ?? [
            'resource_id' => $resource->id,
            'resource_version_id' => $versionId,
            'last_page' => 1,
            'total_pages' => null,
            'completion_percentage' => 0,
            'last_read_at' => null,
            'reading_position' => null,
        ]);
    }

    /** Save this user's progress for a version of an accessible resource. */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);
        $request->validate([
            'version_id' => 'nullable|integer|exists:resource_versions,id',
            'last_page' => 'required|integer|min:1',
            'total_pages' => 'nullable|integer|min:1',
            'completion_percentage' => 'required|numeric|between:0,100',
            'reading_position' => 'nullable|array',
        ]);

        $versionId = $request->input('version_id', $resource->current_version_id);
        $this->ensureVersionBelongsToResource($resource, $versionId);

        $progress = ResourceReadingProgress::updateOrCreate(
            ['resource_id' => $resource->id, 'user_id' => Auth::id(), 'resource_version_id' => $versionId],
            [
                'last_page' => $request->last_page,
                'total_pages' => $request->total_pages,
                'completion_percentage' => $request->completion_percentage,
                'last_read_at' => now(),
                'reading_position' => $request->reading_position,
            ]
        );

        return response()->json($progress, 201);
    }

    /** List the authenticated user's reading history, including prior versions. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['per_page' => 'nullable|integer|between:5,100']);

        $query = ResourceReadingProgress::where('user_id', $user->id)
            ->with(['resource', 'resource.category', 'resourceVersion'])
            ->whereHas('resource', fn ($resources) => $this->visibility->apply($resources, $user));

        // Handle recent parameter - accept boolean or string "true"/"false"
        $recent = $request->input('recent');
        if ($recent === true || $recent === 'true' || $recent === '1') {
            $query->recentlyRead(30);
        }

        return response()->json($query->orderByDesc('last_read_at')->paginate($request->input('per_page', 20)));
    }

    private function ensureVersionBelongsToResource(Resource $resource, mixed $versionId): void
    {
        if ($versionId === null || ! $resource->versions()->whereKey($versionId)->exists()) {
            abort(404, 'Version not found for this resource');
        }
    }
}
