<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\MediaAttachment;
use App\Models\MediaCollection;
use App\Models\MissionSite;
use App\Models\Person;
use App\Models\Team;
use App\Services\MediaService;
use App\Services\MediaVisibilityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * MediaController — API endpoints for the central media library.
 */
class MediaController extends Controller
{
    /**
     * Entity types media may be attached to through the global media route.
     *
     * @var array<string, class-string<Model>>
     */
    private const ATTACHABLE_TYPES = [
        'campaign' => Campaign::class,
        'appmodelscampaign' => Campaign::class,
        'missionsite' => MissionSite::class,
        'appmodelsmissionsite' => MissionSite::class,
        'site' => MissionSite::class,
        'team' => Team::class,
        'appmodelsteam' => Team::class,
        'activity' => Activity::class,
        'appmodelsactivity' => Activity::class,
        'person' => Person::class,
        'appmodelsperson' => Person::class,
    ];

    public function __construct(
        private MediaService $mediaService,
        private MediaVisibilityService $mediaVisibility,
    ) {}

    /**
     * List media items with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Media::class);

        $query = $this->mediaVisibility->visibleTo($request->user());

        // Filter by media type
        if ($request->has('media_type')) {
            $query->ofType($request->media_type);
        }

        // Filter by visibility
        if ($request->has('visibility')) {
            $query->withVisibility($request->visibility);
        }

        // Filter by uploader (my uploads)
        if ($request->boolean('my_uploads')) {
            $query->uploadedBy(Auth::id());
        }

        // Filter by collection
        if ($request->has('collection_id')) {
            $collection = MediaCollection::findOrFail($request->collection_id);
            $query->whereHas('collections', function ($q) use ($collection) {
                $q->where('media_collections.id', $collection->id);
            });
        }

        // Filter by attachable entity
        if ($request->has('attachable_type') && $request->has('attachable_id')) {
            $query->whereHas('attachments', function ($q) use ($request) {
                $q->where('attachable_type', $request->attachable_type)
                    ->where('attachable_id', $request->attachable_id);
            });
        }

        // Search by title or filename
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('original_filename', 'ilike', "%{$search}%")
                    ->orWhere('caption', 'ilike', "%{$search}%");
            });
        }

        // Only show processed media unless specifically requested
        if (! $request->boolean('include_processing')) {
            $query->processed();
        }

        $media = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 24));

        // Append thumbnail_url to each media item
        $media->getCollection()->transform(function ($item) {
            $item->thumbnail_url = $item->getThumbnailUrl();
            $item->url = $item->getUrl();

            return $item;
        });

        return response()->json($media);
    }

    /**
     * Get a specific media item.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $media);

        // Load relationships
        $media->load(['uploader', 'collections', 'attachments.attachable']);

        // Append URLs
        $media->thumbnail_url = $media->getThumbnailUrl();
        $media->url = $media->getUrl();

        return response()->json($media);
    }

    /**
     * Upload a new media file.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Media::class);

        $request->validate([
            'file' => 'required|file|max:512000', // Max 500MB
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string|max:5000',
            'visibility' => ['nullable', Rule::in(['private', 'organisational', 'public'])],
            'capture_date' => 'nullable|date',
            'requires_consent' => 'nullable|boolean',
            'consent_obtained' => 'nullable|boolean',
            'consent_notes' => 'nullable|string|max:1000',
            'attachable_type' => ['nullable', 'string', 'required_with:attachable_id'],
            'attachable_id' => ['nullable', 'integer', 'required_with:attachable_type'],
            'attachment_type' => 'nullable|string|max:50',
        ]);

        $attachable = null;
        if ($request->filled('attachable_type') && $request->filled('attachable_id')) {
            $attachable = $this->resolveAttachable(
                (string) $request->input('attachable_type'),
                (int) $request->input('attachable_id')
            );

            $this->authorize('update', $attachable);
        }

        // Consent validation: cannot make media public if it requires consent but consent not obtained
        $visibility = $request->visibility ?? 'private';
        $requiresConsent = $request->boolean('requires_consent');
        $consentObtained = $request->boolean('consent_obtained');

        if ($visibility === 'public' && $requiresConsent && ! $consentObtained) {
            return response()->json([
                'message' => 'Media requiring consent cannot be made public until consent is obtained.',
            ], 422);
        }

        try {
            $media = $this->mediaService->upload($request->file('file'), [
                'title' => $request->title,
                'caption' => $request->caption,
                'visibility' => $visibility,
                'capture_date' => $request->capture_date,
                'requires_consent' => $requiresConsent,
                'consent_obtained' => $consentObtained,
                'consent_notes' => $request->consent_notes,
                'uploaded_by_user_id' => Auth::id(),
            ]);

            // Attach to entity if specified
            if ($attachable !== null) {
                $this->attachMedia($media, $attachable, $request->attachment_type ?? 'general');
            }

            return response()->json($media, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Upload failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Update media metadata.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $media);

        $request->validate([
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string|max:5000',
            'visibility' => ['nullable', Rule::in(['private', 'organisational', 'public'])],
            'requires_consent' => 'nullable|boolean',
            'consent_obtained' => 'nullable|boolean',
            'consent_notes' => 'nullable|string|max:1000',
        ]);

        // Consent validation: cannot make media public if it requires consent but consent not obtained
        if ($request->has('visibility') && $request->visibility === 'public') {
            $requiresConsent = $request->has('requires_consent') ? $request->boolean('requires_consent') : $media->requires_consent;
            $consentObtained = $request->has('consent_obtained') ? $request->boolean('consent_obtained') : $media->consent_obtained;

            if ($requiresConsent && ! $consentObtained) {
                return response()->json([
                    'message' => 'Media requiring consent cannot be made public until consent is obtained.',
                ], 422);
            }
        }

        $media->update($request->only([
            'title',
            'caption',
            'visibility',
            'requires_consent',
            'consent_obtained',
            'consent_notes',
        ]));

        return response()->json($media);
    }

    /**
     * Delete media (soft delete).
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        $this->authorize('delete', $media);

        // Check if media is attached to entities
        if ($media->attachments()->count() > 0) {
            return response()->json([
                'message' => 'Media is attached to entities. Detach first before deleting.',
                'attachments_count' => $media->attachments()->count(),
            ], 409);
        }

        $media->delete();

        return response()->json(['message' => 'Media deleted']);
    }

    /**
     * Attach media to an entity.
     */
    public function attach(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        $this->authorize('attach', $media);

        $request->validate([
            'attachable_type' => 'required|string',
            'attachable_id' => 'required|integer',
            'attachment_type' => 'nullable|string|max:50',
        ]);

        $attachable = $this->resolveAttachable(
            (string) $request->input('attachable_type'),
            (int) $request->input('attachable_id')
        );

        // The uploader may attach their media only to an entity they can
        // actually manage. Without this, the global media route would let a
        // user park media on a campaign or site outside their own scope.
        $this->authorize('update', $attachable);

        try {
            $attachment = $this->attachMedia(
                $media,
                $attachable,
                $request->attachment_type ?? 'general'
            );

            return response()->json($attachment, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Attachment failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Detach media from an entity.
     */
    public function detach(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        $this->authorize('detach', $media);

        $request->validate([
            'attachable_type' => 'required|string',
            'attachable_id' => 'required|integer',
            'attachment_type' => 'nullable|string|max:50',
        ]);

        $attachable = $this->resolveAttachable(
            (string) $request->input('attachable_type'),
            (int) $request->input('attachable_id')
        );

        $this->authorize('update', $attachable);

        $query = $media->attachments()
            ->where('attachable_type', $attachable->getMorphClass())
            ->where('attachable_id', $attachable->getKey());

        if ($request->filled('attachment_type')) {
            $query->where('attachment_type', $request->attachment_type);
        }

        if ($query->delete() === 0) {
            return response()->json(['message' => 'Media is not attached to this entity'], 404);
        }

        return response()->json(['message' => 'Media detached']);
    }

    /**
     * Get media URL (temporary signed URL for private media).
     */
    public function getUrl(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $media);

        $url = $this->mediaService->getUrl($media);

        if (! $url) {
            return response()->json(['message' => 'URL not available'], 404);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Get thumbnail URL.
     */
    public function getThumbnailUrl(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $media);

        $url = $this->mediaService->getThumbnailUrl($media);

        if (! $url) {
            return response()->json(['message' => 'Thumbnail not available'], 404);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Resolve and validate a polymorphic attachable target.
     */
    private function resolveAttachable(string $type, int $id): Model
    {
        $normalised = strtolower(str_replace(['\\', '_', ' '], '', $type));
        $class = self::ATTACHABLE_TYPES[$normalised] ?? null;

        if ($class === null) {
            abort(422, 'Unsupported attachable type.');
        }

        $attachable = $class::query()->find($id);

        if ($attachable === null) {
            abort(404, 'Attachable entity not found.');
        }

        return $attachable;
    }

    /**
     * Helper method to attach media to an entity.
     */
    protected function attachMedia(Media $media, Model $attachable, string $attachmentType): MediaAttachment
    {
        return MediaAttachment::firstOrCreate([
            'media_id' => $media->id,
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'attachment_type' => $attachmentType,
        ], [
            'attached_by_user_id' => Auth::id(),
        ]);
    }
}
