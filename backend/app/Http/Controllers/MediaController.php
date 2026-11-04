<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\MediaCollection;
use App\Models\MediaAttachment;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * MediaController — API endpoints for the central media library.
 */
class MediaController extends Controller
{
    protected MediaService $mediaService;

    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * List media items with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Media::query();

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
        if (!$request->boolean('include_processing')) {
            $query->processed();
        }

        // Apply visibility check - users can only see:
        // - Their own uploads
        // - Organisational media (if authenticated)
        // - Public media
        $query->where(function ($q) {
            $q->where('uploaded_by_user_id', Auth::id())
              ->orWhere('visibility', '!=', 'private');
        });

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

        // Visibility check
        if ($media->visibility === 'private' && $media->uploaded_by_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
        $request->validate([
            'file' => 'required|file|max:512000', // Max 500MB
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string|max:5000',
            'visibility' => ['nullable', Rule::in(['private', 'organisational', 'public'])],
            'capture_date' => 'nullable|date',
            'requires_consent' => 'nullable|boolean',
            'consent_obtained' => 'nullable|boolean',
            'consent_notes' => 'nullable|string|max:1000',
            'attachable_type' => 'nullable|string',
            'attachable_id' => 'nullable|integer',
            'attachment_type' => 'nullable|string|max:50',
        ]);

        // Consent validation: cannot make media public if it requires consent but consent not obtained
        $visibility = $request->visibility ?? 'private';
        $requiresConsent = $request->boolean('requires_consent');
        $consentObtained = $request->boolean('consent_obtained');

        if ($visibility === 'public' && $requiresConsent && !$consentObtained) {
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
            if ($request->has('attachable_type') && $request->has('attachable_id')) {
                $this->attachMedia($media, $request->attachable_type, $request->attachable_id, $request->attachment_type ?? 'general');
            }

            return response()->json($media, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update media metadata.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        // Only uploader can update
        if ($media->uploaded_by_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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

            if ($requiresConsent && !$consentObtained) {
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

        // Only uploader can delete
        if ($media->uploaded_by_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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

        $request->validate([
            'attachable_type' => 'required|string',
            'attachable_id' => 'required|integer',
            'attachment_type' => 'nullable|string|max:50',
        ]);

        // Only uploader can attach their media
        if ($media->uploaded_by_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $attachment = $this->attachMedia(
                $media,
                $request->attachable_type,
                $request->attachable_id,
                $request->attachment_type ?? 'general'
            );

            return response()->json($attachment, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Attachment failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Detach media from an entity.
     */
    public function detach(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        $request->validate([
            'attachable_type' => 'required|string',
            'attachable_id' => 'required|integer',
            'attachment_type' => 'nullable|string|max:50',
        ]);

        // Only uploader can detach their media
        if ($media->uploaded_by_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = $media->attachments()
                      ->where('attachable_type', $request->attachable_type)
                      ->where('attachable_id', $request->attachable_id);

        if ($request->has('attachment_type')) {
            $query->where('attachment_type', $request->attachment_type);
        }

        $query->delete();

        return response()->json(['message' => 'Media detached']);
    }

    /**
     * Get media URL (temporary signed URL for private media).
     */
    public function getUrl(Request $request, string $uuid): JsonResponse
    {
        $media = Media::where('uuid', $uuid)->firstOrFail();

        // Visibility check
        if ($media->visibility === 'private' && $media->uploaded_by_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $url = $this->mediaService->getUrl($media);

        if (!$url) {
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

        // Visibility check
        if ($media->visibility === 'private' && $media->uploaded_by_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $url = $this->mediaService->getThumbnailUrl($media);

        if (!$url) {
            return response()->json(['message' => 'Thumbnail not available'], 404);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Helper method to attach media to an entity.
     */
    protected function attachMedia(Media $media, string $attachableType, int $attachableId, string $attachmentType): MediaAttachment
    {
        return MediaAttachment::firstOrCreate([
            'media_id' => $media->id,
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
            'attachment_type' => $attachmentType,
        ], [
            'attached_by_user_id' => Auth::id(),
        ]);
    }
}
