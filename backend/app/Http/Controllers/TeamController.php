<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TeamController — manages teams within a campaign (optionally at a site).
 *
 * Teams can be campaign-wide (mission_site_id = null) or site-specific.
 * The flexible team model (Correction 4) means:
 *   - team_type_id  = WHAT FUNCTION does the team serve?
 *   - origin_org_unit_id = WHERE do the members come from?
 * Both are optional and independent.
 *
 * Site-specific teams enforce composite FK (site_id, campaign_id) at the
 * DB level. Here we validate that site_id belongs to this campaign before
 * allowing any mutation.
 */
class TeamController extends Controller
{
    /**
     * List teams for a campaign (optionally filtered by site).
     *
     * GET /api/v1/campaigns/{campaign}/teams
     * ?site_id=5        (filter to one site)
     * ?campaign_wide=1  (campaign-wide teams only)
     */
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $request->validate([
            'site_id' => ['sometimes', 'integer'],
        ]);

        // Resolve the site BEFORE authorising so a site-scoped coordinator —
        // who holds no campaign-wide authority — can still list the teams at
        // their OWN site. Listing without a site stays a campaign-scoped
        // decision, so the narrower site admission never widens the campaign.
        $site = $request->filled('site_id')
            ? $this->resolveSite($campaign, (int) $request->site_id)
            : null;

        $this->authorize('viewAny', [Team::class, $campaign, $site]);

        $query = $campaign->teams()
            ->with(['teamType', 'originOrgUnit', 'site'])
            // Count only CURRENT membership. Removing a member soft-ends the
            // row (ended_on is set) rather than deleting it, so counting every
            // row would report departed members as if they were still on the
            // team.
            ->withCount(['members' => fn ($q) => $q->whereNull('ended_on')]);

        if ($request->filled('site_id')) {
            $query->where('mission_site_id', $request->site_id);
        } elseif ($request->boolean('campaign_wide')) {
            $query->whereNull('mission_site_id');
        }

        return response()->json($query->orderBy('name')->get());
    }

    /**
     * Create a team within a campaign.
     *
     * POST /api/v1/campaigns/{campaign}/teams
     */
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:200'],
            'description'        => ['sometimes', 'nullable', 'string'],
            'mission_site_id'    => ['sometimes', 'nullable', 'integer'],
            'team_type_id'       => ['sometimes', 'nullable', 'integer', 'exists:team_types,id'],
            'origin_org_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:org_units,id'],
        ]);

        // Resolve the site BEFORE authorising: a site-scoped coordinator holds
        // assignments.manage only at their own site, so the site is part of the
        // authorization decision, not merely a data-integrity check on top of
        // it. A campaign-wide team (no site) still requires campaign authority.
        $site = null;
        if (! empty($validated['mission_site_id'])) {
            $site = $this->resolveSite($campaign, (int) $validated['mission_site_id']);
        }

        $this->authorize('create', [Team::class, $campaign, $site]);

        $team = $campaign->teams()->create($validated);

        return response()->json(
            $team->load(['teamType', 'originOrgUnit', 'site']),
            201
        );
    }

    /**
     * Show a team with its members.
     *
     * GET /api/v1/campaigns/{campaign}/teams/{team}
     */
    public function show(Campaign $campaign, Team $team): JsonResponse
    {
        $this->validateTeamScope($campaign, $team);
        $this->authorize('view', $team);

        // The roster is the CURRENT membership. Rows are soft-ended (ended_on)
        // on removal rather than deleted, so an unfiltered `members` load would
        // present departed members — and a departed leader — as if they were
        // still on the team.
        $team->load([
            'teamType',
            'originOrgUnit',
            'site',
            'members' => fn ($q) => $q->whereNull('ended_on')->with('person'),
        ]);

        return response()->json($team);
    }

    /**
     * Update team details.
     *
     * PATCH /api/v1/campaigns/{campaign}/teams/{team}
     */
    public function update(Request $request, Campaign $campaign, Team $team): JsonResponse
    {
        $this->validateTeamScope($campaign, $team);
        $this->authorize('update', $team);

        $validated = $request->validate([
            'name'               => ['sometimes', 'string', 'max:200'],
            'description'        => ['sometimes', 'nullable', 'string'],
            'team_type_id'       => ['sometimes', 'nullable', 'integer', 'exists:team_types,id'],
            'origin_org_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:org_units,id'],
        ]);

        $team->update($validated);

        return response()->json($team->fresh(['teamType', 'originOrgUnit', 'site']));
    }

    /**
     * Add a person to a team.
     *
     * POST /api/v1/campaigns/{campaign}/teams/{team}/members
     */
    public function addMember(Request $request, Campaign $campaign, Team $team): JsonResponse
    {
        $this->validateTeamScope($campaign, $team);
        $this->authorize('manageMember', $team);

        $validated = $request->validate([
            'person_id'    => ['required', 'integer', 'exists:people,id'],
            'role_in_team' => ['required', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::TEAM_MEMBER_ROLES)],
            'joined_on'    => ['sometimes', 'nullable', 'date'],
        ]);

        // Only one leader per team. Only an ACTIVE leader blocks the
        // appointment: a member removed from the team is soft-ended, and a
        // departed leader must not keep the team permanently leaderless.
        if ($validated['role_in_team'] === 'leader' && $team->members()->where('role_in_team', 'leader')->whereNull('ended_on')->exists()) {
            return response()->json([
                'message' => 'This team already has a leader. Update the existing leader first.',
            ], 422);
        }

        // Prevent duplicate membership
        if ($team->members()->where('person_id', $validated['person_id'])->whereNull('ended_on')->exists()) {
            return response()->json([
                'message' => 'This person is already an active member of this team.',
            ], 422);
        }

        $member = $team->members()->create($validated);

        return response()->json(
            $member->load('person'),
            201
        );
    }

    /**
     * Remove a person from a team (soft-end by setting ended_on).
     *
     * DELETE /api/v1/campaigns/{campaign}/teams/{team}/members/{member}
     */
    public function removeMember(Request $request, Campaign $campaign, Team $team, TeamMember $member): JsonResponse
    {
        $this->validateTeamScope($campaign, $team);
        $this->authorize('manageMember', $team);

        if ($member->team_id !== $team->id) {
            abort(404, 'Member not found in this team.');
        }

        $member->update(['ended_on' => $request->input('ended_on', now()->toDateString())]);

        return response()->json(['message' => 'Member removed from team.']);
    }

    /**
     * Change an existing member's role within the team.
     *
     * PATCH /api/v1/campaigns/{campaign}/teams/{team}/members/{member}
     *
     * This is what makes "appoint or change the team leader" possible without
     * introducing a second leadership model: leadership IS the `leader` value
     * of an existing membership. Promoting a member to leader demotes whoever
     * currently leads in the same operation, so the one-leader-per-team
     * invariant holds and a change of leader never leaves two leaders.
     */
    public function updateMember(Request $request, Campaign $campaign, Team $team, TeamMember $member): JsonResponse
    {
        $this->validateTeamScope($campaign, $team);
        $this->authorize('manageMember', $team);

        if ($member->team_id !== $team->id) {
            abort(404, 'Member not found in this team.');
        }

        // A soft-ended row is history, not a current membership: it cannot be
        // promoted back into the roster by a role change. Re-adding the person
        // is the honest way to bring them back.
        if ($member->ended_on !== null) {
            return response()->json([
                'message' => 'This person is no longer an active member of this team.',
            ], 422);
        }

        $validated = $request->validate([
            'role_in_team' => ['required', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::TEAM_MEMBER_ROLES)],
        ]);

        if ($validated['role_in_team'] === 'leader') {
            $team->members()
                ->where('role_in_team', 'leader')
                ->whereNull('ended_on')
                ->whereKeyNot($member->id)
                ->update(['role_in_team' => 'member']);
        }

        $member->update($validated);

        return response()->json($member->fresh('person'));
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function validateTeamScope(Campaign $campaign, Team $team): void
    {
        if ($team->campaign_id !== $campaign->id) {
            abort(404, 'Team not found within this campaign.');
        }
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
     * Attach media to team.
     *
     * POST /api/v1/campaigns/{campaign}/teams/{team}/media
     */
    public function attachMedia(Request $request, Campaign $campaign, Team $team): JsonResponse
    {
        $this->validateTeamScope($campaign, $team);
        $this->authorize('update', $team);

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
            'attachable_type' => Team::class,
            'attachable_id' => $team->id,
            'attachment_type' => $request->attachment_type ?? 'general',
        ], [
            'attached_by_user_id' => $request->user()->id,
        ]);

        return response()->json($attachment, 201);
    }

    /**
     * Detach media from team.
     *
     * DELETE /api/v1/campaigns/{campaign}/teams/{team}/media/{media_uuid}
     */
    public function detachMedia(Request $request, Campaign $campaign, Team $team, string $media_uuid): JsonResponse
    {
        $this->validateTeamScope($campaign, $team);
        $this->authorize('update', $team);

        $media = \App\Models\Media::where('uuid', $media_uuid)->firstOrFail();

        if ($media->uploaded_by_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $team->mediaAttachments()
             ->where('media_id', $media->id)
             ->delete();

        return response()->json(['message' => 'Media detached']);
    }
}
