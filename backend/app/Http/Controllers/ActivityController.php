<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\MediaAttachment;
use App\Models\MissionSite;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ActivityController — manages scheduled field activities within campaign sites.
 *
 * Activities represent scheduled or recorded field events: evangelistic meetings,
 * Bible studies, visitation, health outreach, etc. Each activity is tied to a
 * specific site within a campaign.
 *
 * The composite FK (mission_site_id, campaign_id) at the DB level guarantees
 * the site belongs to the same campaign. This controller enforces the same
 * rule before every mutation.
 *
 * READ AUTHORISATION. Listing is admitted by ActivityPolicy::viewAny (the caller
 * holds activities.view somewhere in the campaign) and is then narrowed by
 * AuthorizationService::scopeQuery to the caller's actual (campaign, site)
 * scopes, so a site-scoped coordinator never receives a sibling site's
 * activities.
 */
class ActivityController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List activities for a campaign (optionally filtered by site).
     *
     * GET /api/v1/campaigns/{campaign}/activities
     * ?site_id=5
     * ?status=scheduled|in_progress|completed|cancelled
     */
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('viewAny', [Activity::class, $campaign]);

        $request->validate([
            'site_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::ACTIVITY_STATUSES)],
        ]);

        // The activities are read THROUGH the campaign relation so the owning
        // campaign is expressed once, by the relation's own foreign key. A
        // HasMany hands back a relation, but scopeQuery() operates on an
        // Eloquent Builder, so getQuery() unwraps it — the returned builder is
        // already constrained to this campaign by `campaign_id`.
        $query = $campaign->activities()
            ->getQuery()
            ->with(['site', 'activityType', 'ledBy', 'objectives'])
            ->orderBy('scheduled_at');

        // Narrow to the caller's real (campaign, site) scopes. Admission above
        // only proves they hold activities.view SOMEWHERE in the campaign; this
        // is what stops a site-scoped coordinator from reading a sibling site's
        // activities. An empty scope list fails closed.
        $scopes = $this->auth->getAuthorisedScopes($request->user(), ['activities.view']);
        $this->auth->scopeQuery($query, $scopes, 'campaign_id', 'mission_site_id');

        if ($request->filled('site_id')) {
            $query->where('mission_site_id', $request->site_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    /**
     * Create a new activity.
     *
     * POST /api/v1/campaigns/{campaign}/activities
     *
     * PLANNED vs UNPLANNED. An activity authored here is normally PLANNED
     * (is_planned = true): it is scheduled ahead of the campaign programme. The
     * attendance screen passes is_planned = false when it creates an activity
     * that genuinely arose on the day, so the two origins stay distinguishable.
     *
     * DUPLICATE PREVENTION. Before writing, the same occurrence slot is probed
     * — same site, same activity type, same calendar day and the same title —
     * and an existing match is returned with 409 rather than silently creating
     * a second record for what is really one activity. This is what lets the
     * attendance flow offer the existing planned activity instead of cloning it.
     */
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $validated = $request->validate([
            'mission_site_id' => ['required', 'integer'],
            'activity_type_id' => ['required', 'integer', 'exists:activity_types,id'],
            'title' => ['required', 'string', 'max:250'],
            'description' => ['sometimes', 'nullable', 'string'],
            'scheduled_at' => ['required', 'date'],
            'estimated_attendance' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'location_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'led_by_person_id' => ['sometimes', 'nullable', 'integer', 'exists:people,id'],
            'is_planned' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // Resolve the site BEFORE authorising: a site-scoped coordinator holds
        // activities.create only at their own site, so the site is part of the
        // authorization decision, not merely a data-integrity check on top of it.
        $site = $this->resolveSite($campaign, (int) $validated['mission_site_id']);

        $this->authorize('create', [Activity::class, $campaign, $site]);

        // Refuse a duplicate occurrence rather than creating a second record.
        $duplicate = $this->findDuplicateActivity(
            $campaign,
            (int) $validated['mission_site_id'],
            (int) $validated['activity_type_id'],
            $validated['title'],
            $validated['scheduled_at'],
        );

        if ($duplicate !== null) {
            return response()->json([
                'message' => 'An activity with these details already exists for this site and day.',
                'activity' => $duplicate->load(['site', 'activityType', 'objectives']),
            ], 409);
        }

        $activity = $campaign->activities()->create([
            ...$validated,
            'status' => 'scheduled',
            'is_planned' => $validated['is_planned'] ?? true,
        ]);

        return response()->json(
            $activity->load(['site', 'activityType', 'ledBy', 'objectives']),
            201
        );
    }

    /**
     * Show a single activity.
     *
     * GET /api/v1/campaigns/{campaign}/activities/{activity}
     */
    public function show(Campaign $campaign, Activity $activity): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('view', $activity);

        return response()->json(
            $activity->load(['site', 'activityType', 'ledBy', 'attendanceRegisters', 'outcomes', 'objectives'])
        );
    }

    /**
     * Update activity details.
     *
     * PATCH /api/v1/campaigns/{campaign}/activities/{activity}
     */
    public function update(Request $request, Campaign $campaign, Activity $activity): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('update', $activity);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:250'],
            'description' => ['sometimes', 'nullable', 'string'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'actual_started_at' => ['sometimes', 'nullable', 'date'],
            'actual_ended_at' => ['sometimes', 'nullable', 'date', 'after:actual_started_at'],
            'estimated_attendance' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'location_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'led_by_person_id' => ['sometimes', 'nullable', 'integer', 'exists:people,id'],
            'status' => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::ACTIVITY_STATUSES)],
            'is_planned' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $activity->update($validated);

        return response()->json(
            $activity->fresh(['site', 'activityType', 'ledBy', 'objectives'])
        );
    }

    /**
     * Delete an activity (only allowed when scheduled and no dependent records exist).
     *
     * DELETE /api/v1/campaigns/{campaign}/activities/{activity}
     */
    public function destroy(Campaign $campaign, Activity $activity): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('delete', $activity);

        if ($activity->status !== 'scheduled') {
            return response()->json([
                'message' => 'Only scheduled activities can be removed.',
            ], 422);
        }

        // Guard: refuse if child records already exist
        if ($activity->attendanceRegisters()->exists() || $activity->outcomes()->exists()) {
            return response()->json([
                'message' => 'Cannot remove an activity that already has attendance or outcomes recorded.',
            ], 422);
        }

        $activity->delete();

        return response()->json(['message' => 'Activity removed.'], 200);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function validateActivityScope(Campaign $campaign, Activity $activity): void
    {
        if ($activity->campaign_id !== $campaign->id) {
            abort(404, 'Activity not found within this campaign.');
        }
    }

    /**
     * Find an activity occupying the same occurrence slot.
     *
     * Two activities are the same occurrence when they share a site, an
     * activity type, a calendar day and a title (compared case-insensitively,
     * ignoring surrounding whitespace). That is deliberately narrow: a site may
     * legitimately run the same KIND of activity twice in one day with different
     * titles ("Bible study — youth" and "Bible study — women"), and those are
     * two real activities, not a duplicate.
     *
     * When scheduled_at is absent the "same day" rule cannot be applied, so only
     * other null-scheduled records are compared — a null never matches a dated
     * record.
     */
    private function findDuplicateActivity(
        Campaign $campaign,
        int $siteId,
        int $activityTypeId,
        string $title,
        ?string $scheduledAt,
    ): ?Activity {
        $query = Activity::where('campaign_id', $campaign->id)
            ->where('mission_site_id', $siteId)
            ->where('activity_type_id', $activityTypeId)
            ->whereRaw('lower(title) = ?', [mb_strtolower(trim($title))]);

        if ($scheduledAt !== null) {
            $query->whereDate('scheduled_at', $scheduledAt);
        } else {
            $query->whereNull('scheduled_at');
        }

        return $query->first();
    }

    /**
     * Resolve a mission site and prove it belongs to the given campaign.
     *
     * Returns the MissionSite so the caller can use it in an authorization
     * check, or aborts 422 when the site does not belong to the campaign.
     */
    private function resolveSite(Campaign $campaign, int $siteId): MissionSite
    {
        $site = MissionSite::where('id', $siteId)
            ->where('campaign_id', $campaign->id)
            ->first();

        if (! $site) {
            abort(422, "Site {$siteId} does not belong to campaign {$campaign->id}.");
        }

        return $site;
    }

    /**
     * Attach media to activity.
     *
     * POST /api/v1/campaigns/{campaign}/activities/{activity}/media
     */
    public function attachMedia(Request $request, Campaign $campaign, Activity $activity): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('update', $activity);

        $request->validate([
            'media_uuid' => ['required', 'string', 'exists:media,uuid'],
            'attachment_type' => ['sometimes', 'string', 'max:50'],
        ]);

        $media = Media::where('uuid', $request->media_uuid)->firstOrFail();

        // A manager may only attach media they are allowed to see; otherwise
        // the entity route could be used to pull an unknown media record into
        // their own scope.
        $this->authorize('view', $media);

        // Consent validation: cannot attach media requiring consent to public contexts if consent not obtained
        if ($media->requires_consent && ! $media->consent_obtained) {
            return response()->json([
                'message' => 'Media requiring consent cannot be attached until consent is obtained.',
            ], 422);
        }

        $attachment = MediaAttachment::firstOrCreate([
            'media_id' => $media->id,
            'attachable_type' => Activity::class,
            'attachable_id' => $activity->id,
            'attachment_type' => $request->attachment_type ?? 'general',
        ], [
            'attached_by_user_id' => $request->user()->id,
        ]);

        return response()->json($attachment, 201);
    }

    /**
     * Detach media from activity.
     *
     * DELETE /api/v1/campaigns/{campaign}/activities/{activity}/media/{media_uuid}
     */
    public function detachMedia(Request $request, Campaign $campaign, Activity $activity, string $media_uuid): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('update', $activity);

        $media = Media::where('uuid', $media_uuid)->firstOrFail();

        if ($media->uploaded_by_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $activity->mediaAttachments()
            ->where('media_id', $media->id)
            ->delete();

        return response()->json(['message' => 'Media detached']);
    }
}
