<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\MissionSite;
use App\Models\UserRoleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AssignmentController — manages missionary deployments to campaigns and sites.
 *
 * A campaign assignment links a person to a campaign, optionally placing them
 * at a specific site and on a specific team.
 *
 * One person = one assignment per campaign. Reassigning to a different site
 * is done via PATCH, not by creating a second assignment record.
 *
 * The composite FK (mission_site_id, campaign_id) at the DB level guarantees
 * the site belongs to the same campaign. This controller enforces the same
 * rule before every mutation that touches mission_site_id.
 */
class AssignmentController extends Controller
{
    /**
     * List assignments for a campaign.
     *
     * GET /api/v1/campaigns/{campaign}/assignments
     * ?site_id=5     (filter to one site)
     * ?status=confirmed|arrived|completed
     */
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('viewAny', [CampaignAssignment::class, $campaign]);

        $request->validate([
            'site_id' => ['sometimes', 'integer'],
            'status'  => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::ASSIGNMENT_STATUSES)],
        ]);

        $query = $campaign->assignments()
            ->with(['person.user', 'site', 'team.teamType', 'functionalRoleType']);

        if ($request->filled('site_id')) {
            $query->where('mission_site_id', $request->site_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('created_at')->get());
    }

    /**
     * Assign a missionary to this campaign.
     *
     * POST /api/v1/campaigns/{campaign}/assignments
     */
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('create', [CampaignAssignment::class, $campaign]);

        $validated = $request->validate([
            'person_id'               => ['required', 'integer', 'exists:people,id'],
            'mission_site_id'         => ['sometimes', 'nullable', 'integer'],
            'assigned_team_id'        => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'functional_role_type_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_role_types,id'],
            'system_role_id'          => ['sometimes', 'nullable', 'integer', 'exists:system_roles,id'],
            'expected_arrival'        => ['sometimes', 'nullable', 'date'],
            'notes'                   => ['sometimes', 'nullable', 'string'],
        ]);

        // Validate site belongs to this campaign
        if (! empty($validated['mission_site_id'])) {
            $this->validateSiteScope($campaign, $validated['mission_site_id']);
        }

        // Validate team belongs to this campaign
        if (! empty($validated['assigned_team_id'])) {
            $this->validateTeamScope($campaign, $validated['assigned_team_id']);
        }

        // One assignment per person per campaign
        if ($campaign->assignments()->where('person_id', $validated['person_id'])->exists()) {
            return response()->json([
                'message' => 'This person already has an assignment in this campaign. Use PATCH to update it.',
            ], 422);
        }

        $assignment = $campaign->assignments()->create([
            ...$validated,
            'status'             => 'confirmed',
            'assigned_by_user_id'=> $request->user()->id,
        ]);

        // Create system role assignment if provided and person has a user account
        if (! empty($validated['system_role_id']) && $assignment->person->user_id) {
            $scopeType = ! empty($validated['assigned_team_id']) ? 'team' :
                        (! empty($validated['mission_site_id']) ? 'site' : 'campaign');
            $scopeField = match($scopeType) {
                'team' => 'scoped_team_id',
                'site' => 'scoped_site_id',
                'campaign' => 'scoped_campaign_id',
            };
            $scopeId = match($scopeType) {
                'team' => $validated['assigned_team_id'],
                'site' => $validated['mission_site_id'],
                'campaign' => $campaign->id,
            };

            UserRoleAssignment::create([
                'user_id' => $assignment->person->user_id,
                'system_role_id' => $validated['system_role_id'],
                'scope_type' => $scopeType,
                $scopeField => $scopeId,
                'granted_by_user_id' => $request->user()->id,
            ]);
        }

        return response()->json(
            $assignment->load(['person.user', 'site', 'team.teamType']),
            201
        );
    }

    /**
     * Show a single assignment.
     *
     * GET /api/v1/campaigns/{campaign}/assignments/{assignment}
     */
    public function show(Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->validateAssignmentScope($campaign, $assignment);
        $this->authorize('view', $assignment);

        return response()->json(
            $assignment->load(['person.missionaryProfile', 'site', 'team.teamType', 'functionalRoleType', 'assignedBy'])
        );
    }

    /**
     * Update an assignment (reassign to different site or update status/arrival).
     *
     * PATCH /api/v1/campaigns/{campaign}/assignments/{assignment}
     */
    public function update(Request $request, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->validateAssignmentScope($campaign, $assignment);
        $this->authorize('update', $assignment);

        $validated = $request->validate([
            'mission_site_id'         => ['sometimes', 'nullable', 'integer'],
            'assigned_team_id'        => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'functional_role_type_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_role_types,id'],
            'system_role_id'          => ['sometimes', 'nullable', 'integer', 'exists:system_roles,id'],
            'status'                  => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::ASSIGNMENT_STATUSES)],
            'expected_arrival'        => ['sometimes', 'nullable', 'date'],
            'actual_arrival'          => ['sometimes', 'nullable', 'date'],
            'departure'               => ['sometimes', 'nullable', 'date'],
            'notes'                   => ['sometimes', 'nullable', 'string'],
        ]);

        // Validate site belongs to this campaign if being changed
        if (array_key_exists('mission_site_id', $validated) && $validated['mission_site_id']) {
            $this->validateSiteScope($campaign, $validated['mission_site_id']);
        }

        $assignment->update($validated);

        // Handle system role assignment update
        if (array_key_exists('system_role_id', $validated)) {
            // Delete existing role assignment for this assignment's scope
            $scopeType = ! empty($assignment->assigned_team_id) ? 'team' :
                        (! empty($assignment->mission_site_id) ? 'site' : 'campaign');
            $scopeField = match($scopeType) {
                'team' => 'scoped_team_id',
                'site' => 'scoped_site_id',
                'campaign' => 'scoped_campaign_id',
            };
            $scopeId = match($scopeType) {
                'team' => $assignment->assigned_team_id,
                'site' => $assignment->mission_site_id,
                'campaign' => $campaign->id,
            };

            // Remove old role assignment
            UserRoleAssignment::where('user_id', $assignment->person->user_id)
                ->where($scopeField, $scopeId)
                ->where('scope_type', $scopeType)
                ->delete();

            // Create new role assignment if system_role_id is provided and person has user account
            if (! empty($validated['system_role_id']) && $assignment->person->user_id) {
                UserRoleAssignment::create([
                    'user_id' => $assignment->person->user_id,
                    'system_role_id' => $validated['system_role_id'],
                    'scope_type' => $scopeType,
                    $scopeField => $scopeId,
                    'granted_by_user_id' => $request->user()->id,
                ]);
            }
        }

        return response()->json(
            $assignment->fresh(['person', 'site', 'team.teamType'])
        );
    }

    /**
     * Unassign (remove) a person from this campaign.
     * Only allowed if status is 'confirmed' (not yet arrived).
     *
     * DELETE /api/v1/campaigns/{campaign}/assignments/{assignment}
     */
    public function destroy(Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->validateAssignmentScope($campaign, $assignment);
        $this->authorize('delete', $assignment);

        if (! in_array($assignment->status, WorkflowDefinitions::ASSIGNMENT_DELETABLE_STATUSES)) {
            return response()->json([
                'message' => "Cannot remove an assignment with status '{$assignment->status}'.",
            ], 422);
        }

        $assignment->delete();

        return response()->json(['message' => 'Assignment removed.']);
    }

    /**
     * List authorised users for a campaign/site (for follow-up assignment).
     *
     * GET /api/v1/campaigns/{campaign}/authorised-users
     * ?site_id=5 (filter to one site)
     */
    public function authorisedUsers(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('viewAny', [CampaignAssignment::class, $campaign]);

        $request->validate([
            'site_id' => ['sometimes', 'integer'],
        ]);

        $query = CampaignAssignment::where('campaign_id', $campaign->id)
            ->where('status', '!=', 'cancelled')
            ->with(['person.user']);

        if ($request->filled('site_id')) {
            $query->where('mission_site_id', $request->site_id);
        }

        $users = $query->get()
            ->map(fn ($assignment) => [
                'user_id' => $assignment->person->user_id,
                'person_id' => $assignment->person->id,
                'name' => $assignment->person->full_name,
                'email' => $assignment->person->user?->email,
            ])
            ->filter(fn ($user) => $user['user_id'] !== null)
            ->values();

        return response()->json($users);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function validateAssignmentScope(Campaign $campaign, CampaignAssignment $assignment): void
    {
        if ($assignment->campaign_id !== $campaign->id) {
            abort(404, 'Assignment not found within this campaign.');
        }
    }

    private function validateSiteScope(Campaign $campaign, int $siteId): void
    {
        $exists = MissionSite::where('id', $siteId)
            ->where('campaign_id', $campaign->id)
            ->exists();

        if (! $exists) {
            abort(422, "Site {$siteId} does not belong to campaign {$campaign->id}.");
        }
    }

    private function validateTeamScope(Campaign $campaign, int $teamId): void
    {
        $exists = $campaign->teams()->where('id', $teamId)->exists();

        if (! $exists) {
            abort(422, "Team {$teamId} does not belong to campaign {$campaign->id}.");
        }
    }
}
