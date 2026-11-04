<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Campaign;
use App\Models\MissionSite;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MissionSiteController — manages sites within a campaign.
 *
 * All site routes are nested under /campaigns/{campaign}/sites.
 * This scoping ensures every site operation is always tied to a
 * confirmed campaign context — no orphan site access.
 *
 * The composite FK in the database guarantees site-campaign consistency
 * at the DB level (Correction 2). This controller enforces the same
 * rule at the API layer by checking campaign_id before any mutation.
 */
class MissionSiteController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List the sites of a campaign that the user is authorised to access.
     *
     * GET /api/v1/campaigns/{campaign}/sites
     *
     * The result set is narrowed to the user's authorised (campaign, site)
     * scopes resolved by AuthorizationService — campaign-wide access yields
     * every site, a site-scoped user is narrowed to their own site, and a
     * team-scoped user is narrowed to the parent site the team belongs to
     * (resolved server-side). Filtering is never delegated to the client.
     */
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('viewAny', [MissionSite::class, $campaign]);

        // Use a query builder (not the HasMany relation) so scopeQuery can
        // apply the user's authorised scopes. The campaign_id constraint is
        // equivalent to $campaign->sites() but keeps a Builder type.
        $query = MissionSite::query()
            ->where('campaign_id', $campaign->id)
            ->withCount(['teams', 'assignments', 'activities', 'fieldReports', 'outcomes'])
            ->orderBy('name');

        $this->auth->scopeQuery(
            $query,
            $this->auth->getAuthorisedScopes($request->user(), null),
            'campaign_id',
            'id'
        );

        return response()->json($query->get());
    }

    /**
     * Create a new site within a campaign.
     *
     * POST /api/v1/campaigns/{campaign}/sites
     */
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('create', [MissionSite::class, $campaign]);

        $this->abortIfCampaignLocked($campaign);

        $validated = $request->validate([
            'name'                 => ['required', 'string', 'max:200'],
            'description'          => ['sometimes', 'nullable', 'string'],
            'mission_area_id'      => ['sometimes', 'nullable', 'integer', 'exists:mission_areas,id'],
            'location_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'latitude'             => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude'            => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'operational_from'     => ['sometimes', 'nullable', 'date'],
            'operational_until'    => ['sometimes', 'nullable', 'date', 'after_or_equal:operational_from'],
        ]);

        $site = $campaign->sites()->create([
            ...$validated,
            'status' => 'planned',
        ]);

        return response()->json($site, 201);
    }

    /**
     * Show a single site with its teams, assignments, and recent activity.
     *
     * GET /api/v1/campaigns/{campaign}/sites/{site}
     */
    public function show(Campaign $campaign, MissionSite $site): JsonResponse
    {
        $this->validateSiteBelongsToCampaign($campaign, $site);
        $this->authorize('view', $site);

        $site->load([
            'missionArea',
            'teams.teamType',
            'teams.members.person',
            'logistics.responsiblePerson',
        ]);

        $site->loadCount(['assignments', 'activities', 'fieldReports', 'outcomes']);

        return response()->json($site);
    }

    /**
     * Update site details.
     *
     * PATCH /api/v1/campaigns/{campaign}/sites/{site}
     */
    public function update(Request $request, Campaign $campaign, MissionSite $site): JsonResponse
    {
        $this->validateSiteBelongsToCampaign($campaign, $site);
        $this->authorize('update', $site);
        $this->abortIfCampaignLocked($campaign);

        $validated = $request->validate([
            'name'                 => ['sometimes', 'string', 'max:200'],
            'description'          => ['sometimes', 'nullable', 'string'],
            'mission_area_id'      => ['sometimes', 'nullable', 'integer', 'exists:mission_areas,id'],
            'location_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'latitude'             => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude'            => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'status'               => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::SITE_STATUSES)],
            'operational_from'     => ['sometimes', 'nullable', 'date'],
            'operational_until'    => ['sometimes', 'nullable', 'date'],
        ]);

        $site->update($validated);

        return response()->json($site->fresh(['missionArea']));
    }

    /**
     * Delete a site (only allowed when planning and no dependent records exist).
     *
     * DELETE /api/v1/campaigns/{campaign}/sites/{site}
     */
    public function destroy(Campaign $campaign, MissionSite $site): JsonResponse
    {
        $this->validateSiteBelongsToCampaign($campaign, $site);
        $this->authorize('delete', $site);

        if ($campaign->status !== 'planning') {
            return response()->json([
                'message' => 'Sites can only be removed during campaign planning phase.',
            ], 422);
        }

        // Guard: refuse if child records already exist
        if ($site->assignments()->exists() || $site->activities()->exists()) {
            return response()->json([
                'message' => 'Cannot remove a site that already has assignments or activities.',
            ], 422);
        }

        $site->delete();

        return response()->json(['message' => 'Site removed.'], 200);
    }

    /**
     * Get site readiness assessment.
     *
     * GET /api/v1/campaigns/{campaign}/sites/{site}/readiness
     *
     * Calculates readiness from operational facts rather than storing a score.
     * Returns missing requirements and overall readiness status.
     */
    public function readiness(Campaign $campaign, MissionSite $site): JsonResponse
    {
        $this->validateSiteBelongsToCampaign($campaign, $site);
        $this->authorize('view', $site);

        $site->load(['assignments.functionalRoleType', 'activities']);

        $missing = [];

        // Check personnel
        $hasCoordinator = $site->assignments->contains(fn ($a) => $a->functionalRoleType?->code === 'site_coordinator');
        if (!$hasCoordinator) {
            $missing[] = ['category' => 'personnel', 'item' => 'site_coordinator', 'description' => 'Site coordinator not assigned'];
        }

        // Check activities
        $hasActivities = $site->activities->count() > 0;
        if (!$hasActivities) {
            $missing[] = ['category' => 'programme', 'item' => 'activities', 'description' => 'No activities scheduled'];
        }

        // Determine overall status
        $status = 'ready';
        if ($campaign->status === 'planning' && count($missing) > 0) {
            $status = 'needs_planning';
        } elseif ($campaign->status === 'active') {
            $status = 'in_progress';
        } elseif ($site->status === 'closed') {
            $status = 'completed';
        }

        return response()->json([
            'site_id' => $site->id,
            'status' => $status,
            'missing_requirements' => $missing,
            'personnel_count' => $site->assignments->count(),
            'activities_count' => $site->activities->count(),
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function validateSiteBelongsToCampaign(Campaign $campaign, MissionSite $site): void
    {
        if ($site->campaign_id !== $campaign->id) {
            abort(404, 'Site not found within this campaign.');
        }
    }

    private function abortIfCampaignLocked(Campaign $campaign): void
    {
        if (in_array($campaign->status, WorkflowDefinitions::CAMPAIGN_LOCKED_STATUSES)) {
            abort(422, 'This campaign is locked. No further modifications are permitted.');
        }
    }

    /**
     * Attach media to mission site.
     *
     * POST /api/v1/campaigns/{campaign}/sites/{site}/media
     */
    public function attachMedia(Request $request, Campaign $campaign, MissionSite $site): JsonResponse
    {
        $this->validateSiteBelongsToCampaign($campaign, $site);
        $this->authorize('update', $site);

        $request->validate([
            'media_uuid' => ['required', 'string', 'exists:media,uuid'],
            'attachment_type' => ['sometimes', 'string', 'max:50'],
        ]);

        $media = \App\Models\Media::where('uuid', $request->media_uuid)->firstOrFail();

        // Consent validation: cannot attach media requiring consent to public contexts if consent not obtained
        if ($media->requires_consent && !$media->consent_obtained) {
            return response()->json([
                'message' => 'Media requiring consent cannot be attached until consent is obtained.',
            ], 422);
        }

        $attachment = \App\Models\MediaAttachment::firstOrCreate([
            'media_id' => $media->id,
            'attachable_type' => MissionSite::class,
            'attachable_id' => $site->id,
            'attachment_type' => $request->attachment_type ?? 'general',
        ], [
            'attached_by_user_id' => $request->user()->id,
        ]);

        return response()->json($attachment, 201);
    }

    /**
     * Detach media from mission site.
     *
     * DELETE /api/v1/campaigns/{campaign}/sites/{site}/media/{media_uuid}
     */
    public function detachMedia(Request $request, Campaign $campaign, MissionSite $site, string $media_uuid): JsonResponse
    {
        $this->validateSiteBelongsToCampaign($campaign, $site);
        $this->authorize('update', $site);

        $media = \App\Models\Media::where('uuid', $media_uuid)->firstOrFail();

        if ($media->uploaded_by_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $site->mediaAttachments()
             ->where('media_id', $media->id)
             ->delete();

        return response()->json(['message' => 'Media detached']);
    }
}
