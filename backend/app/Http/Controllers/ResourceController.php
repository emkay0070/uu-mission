<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Services\AuthorizationService;
use App\Services\ResourceService;
use App\Services\ResourceVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * ResourceController — API endpoints for the missionary resource library.
 */
class ResourceController extends Controller
{
    protected ResourceService $resourceService;

    protected ResourceVisibilityService $visibility;

    protected AuthorizationService $auth;

    public function __construct(ResourceService $resourceService, ResourceVisibilityService $visibility, AuthorizationService $auth)
    {
        $this->resourceService = $resourceService;
        $this->visibility = $visibility;
        $this->auth = $auth;
    }

    /**
     * List published resources accessible to the authenticated user.
     *
     * GET /api/v1/resources
     * ?search=term
     * ?category_id=1
     * ?language=en
     * ?intended_audience=missionaries
     * ?tag=bible
     * ?per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Resource::class);

        $request->validate([
            'search' => 'nullable|string|max:100',
            'category_id' => 'nullable|integer|exists:resource_categories,id',
            'language' => 'nullable|string|max:10',
            'intended_audience' => 'nullable|string|max:100',
            'tag' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|between:5,100',
        ]);

        $user = $request->user();

        $query = Resource::with(['category', 'currentVersion', 'tags'])
            ->available();
        $this->visibility->apply($query, $user);

        // Search by title or description
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by language
        if ($request->filled('language')) {
            $query->where('language', $request->language);
        }

        // Filter by intended audience
        if ($request->filled('intended_audience')) {
            $query->where('intended_audience', $request->intended_audience);
        }

        // Filter by tag
        if ($request->filled('tag')) {
            $query->whereHas('tags', function ($q) use ($request) {
                $q->where('tag', $request->tag);
            });
        }

        $resources = $query->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json($resources);
    }

    /**
     * Show resource details.
     *
     * GET /api/v1/resources/{resource}
     */
    public function show(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $resource->load(['category', 'currentVersion', 'tags', 'createdBy', 'publishedBy']);

        return response()->json($resource);
    }

    /** List version metadata without exposing private storage paths. */
    public function versions(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);
        $versions = $resource->versions()->orderByDesc('id')->get()
            ->filter(fn ($version) => $this->visibility->canView($request->user(), $resource, $version))
            ->values();

        return response()->json($versions);
    }

    /**
     * Create a new resource with file upload.
     *
     * POST /api/v1/resources
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:512000', // Max 500MB
            'thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer|exists:resource_categories,id',
            'language' => 'nullable|string|max:10',
            'intended_audience' => 'nullable|string|max:100',
            'publisher' => 'nullable|string|max:255',
            'publication_date' => 'nullable|date',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
        ]);

        $this->authorize('create', Resource::class);

        // Capture the contributor's active authorised assignment as the
        // resource's origin context. This is derived server-side — never
        // trusted from the client — and is used later to scope private
        // distribution review decisions. It does NOT grant the contributor
        // distribution approval authority; that is checked separately at
        // decision time via canApproveDistribute().
        $originAssignment = null;
        foreach ($this->auth->getActiveAssignmentsGranting(Auth::user(), 'resources.create') as $assignment) {
            [$scopeType, $scopeId] = $this->auth->assignmentScope($assignment);
            if ($this->auth->hasPermissionAtAssignmentScope(Auth::user(), 'resources.create', $scopeType, $scopeId)) {
                $originAssignment = $assignment;
                break;
            }
        }

        try {
            $resourceData = $request->only([
                'title',
                'description',
                'category_id',
                'language',
                'intended_audience',
                'publisher',
                'publication_date',
                'tags',
            ]);
            if ($originAssignment !== null) {
                $resourceData['origin_assignment_id'] = $originAssignment->id;
            }
            $resource = $this->resourceService->createResource(
                $resourceData,
                $request->file('file'),
                Auth::id()
            );

            if ($request->hasFile('thumbnail')) {
                $this->resourceService->saveThumbnail($resource, $request->file('thumbnail'));
            }

            $resource->load(['category', 'currentVersion', 'tags']);

            return response()->json($resource, 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Resource store error: '.$e->getMessage());
            \Log::error('Trace: '.$e->getTraceAsString());

            return response()->json(['message' => 'Resource creation failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Update resource metadata.
     *
     * PATCH /api/v1/resources/{resource}
     */
    public function update(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('update', $resource);

        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer|exists:resource_categories,id',
            'language' => 'nullable|string|max:10',
            'intended_audience' => 'nullable|string|max:100',
            'publisher' => 'nullable|string|max:255',
            'publication_date' => 'nullable|date',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            'thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        try {
            if ($request->hasFile('thumbnail')) {
                $this->resourceService->saveThumbnail($resource, $request->file('thumbnail'));
            }
            $resource = $this->resourceService->updateResource(
                $resource,
                $request->only([
                    'title',
                    'description',
                    'category_id',
                    'language',
                    'intended_audience',
                    'publisher',
                    'publication_date',
                    'tags',
                ]),
                Auth::id()
            );

            $resource->load(['category', 'currentVersion', 'tags']);

            return response()->json($resource);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Resource update failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Replace resource file, creating a new version.
     *
     * POST /api/v1/resources/{resource}/replace-file
     */
    public function replaceFile(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('replaceFile', $resource);

        $request->validate([
            'file' => 'required|file|max:512000',
            'change_reason' => 'required|string|max:500',
        ]);

        try {
            $resource = $this->resourceService->replaceFile(
                $resource,
                $request->file('file'),
                $request->change_reason,
                Auth::id()
            );

            $resource->load(['category', 'currentVersion', 'tags']);

            return response()->json($resource);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'File replacement failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Archive a resource.
     *
     * POST /api/v1/resources/{resource}/archive
     */
    public function archive(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('archive', $resource);

        try {
            $resource = $this->resourceService->archiveResource($resource, Auth::id());

            return response()->json($resource);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * List the authenticated user's own draft resources.
     *
     * GET /api/v1/resources/my-drafts
     */
    public function myDrafts(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'per_page' => 'nullable|integer|between:5,100',
        ]);

        $query = Resource::with(['category', 'currentVersion.documentProcessing', 'tags'])
            ->where('created_by_user_id', $user->id)
            ->where('status', 'draft')
            ->orderByDesc('created_at');

        $resources = $query->paginate($request->input('per_page', 20));

        return response()->json($resources);
    }
}
