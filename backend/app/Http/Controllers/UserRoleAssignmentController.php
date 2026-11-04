<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UserRoleAssignmentController — manage scoped role assignments.
 */
class UserRoleAssignmentController extends Controller
{
    public function __construct(
        private AuthorizationService $auth
    ) {}

    /**
     * List role assignments for a campaign.
     * GET /api/v1/campaigns/{campaign}/role-assignments
     */
    public function indexForCampaign(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('viewAnyForCampaign', [UserRoleAssignment::class, $campaign]);

        $assignments = UserRoleAssignment::with(['user.person', 'role', 'grantedBy'])
            ->where('scope_type', 'campaign')
            ->where('scoped_campaign_id', $campaign->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($assignments);
    }

    /**
     * List role assignments for a site.
     * GET /api/v1/campaigns/{campaign}/sites/{site}/role-assignments
     */
    public function indexForSite(Request $request, Campaign $campaign, MissionSite $site): JsonResponse
    {
        $this->authorize('viewAnyForSite', [UserRoleAssignment::class, $site]);

        $assignments = UserRoleAssignment::with(['user.person', 'role', 'grantedBy'])
            ->where('scope_type', 'site')
            ->where('scoped_site_id', $site->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($assignments);
    }

    /**
     * List role assignments for a team.
     * GET /api/v1/campaigns/{campaign}/teams/{team}/role-assignments
     */
    public function indexForTeam(Request $request, Campaign $campaign, Team $team): JsonResponse
    {
        $this->authorize('viewAnyForTeam', [UserRoleAssignment::class, $team]);

        $assignments = UserRoleAssignment::with(['user.person', 'role', 'grantedBy'])
            ->where('scope_type', 'team')
            ->where('scoped_team_id', $team->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($assignments);
    }

    /**
     * Create a new role assignment.
     * POST /api/v1/role-assignments
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'               => ['required', 'integer', 'exists:users,id'],
            'system_role_id'        => ['required', 'integer', 'exists:system_roles,id'],
            'scope_type'            => ['required', 'string', 'in:global,org_unit,campaign,site,team'],
            'scoped_org_unit_id'    => ['required_if:scope_type,org_unit', 'nullable', 'integer', 'exists:org_units,id'],
            'scoped_campaign_id'    => ['required_if:scope_type,campaign', 'nullable', 'integer', 'exists:campaigns,id'],
            'scoped_site_id'        => ['required_if:scope_type,site', 'nullable', 'integer', 'exists:mission_sites,id'],
            'scoped_team_id'        => ['required_if:scope_type,team', 'nullable', 'integer', 'exists:teams,id'],
            'valid_from'            => ['nullable', 'date'],
            'valid_until'           => ['nullable', 'date', 'after:valid_from'],
        ]);

        $this->authorize('create', [UserRoleAssignment::class, $validated]);

        // Check for duplicate assignment
        $existing = UserRoleAssignment::where('user_id', $validated['user_id'])
            ->where('system_role_id', $validated['system_role_id'])
            ->where('scope_type', $validated['scope_type'])
            ->where('scoped_org_unit_id', $validated['scoped_org_unit_id'] ?? null)
            ->where('scoped_campaign_id', $validated['scoped_campaign_id'] ?? null)
            ->where('scoped_site_id', $validated['scoped_site_id'] ?? null)
            ->where('scoped_team_id', $validated['scoped_team_id'] ?? null)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This user already has this role at the specified scope.',
            ], 409);
        }

        $assignment = UserRoleAssignment::create([
            ...$validated,
            'granted_by_user_id' => $request->user()->id,
        ]);

        return response()->json($assignment->load(['user.person', 'role', 'grantedBy']), 201);
    }

    /**
     * View a specific assignment.
     * GET /api/v1/role-assignments/{assignment}
     */
    public function show(UserRoleAssignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);

        return response()->json($assignment->load(['user.person', 'role', 'grantedBy', 'scopedOrgUnit', 'scopedCampaign', 'scopedSite', 'scopedTeam']));
    }

    /**
     * Update an assignment (e.g., change dates or revoke by setting valid_until).
     * PATCH /api/v1/role-assignments/{assignment}
     */
    public function update(Request $request, UserRoleAssignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);

        $validated = $request->validate([
            'valid_from'    => ['nullable', 'date'],
            'valid_until'   => ['nullable', 'date', 'after:valid_from'],
        ]);

        $assignment->update($validated);

        return response()->json($assignment->load(['user.person', 'role', 'grantedBy']));
    }

    /**
     * Delete/revoke an assignment.
     * DELETE /api/v1/role-assignments/{assignment}
     */
    public function destroy(UserRoleAssignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);

        $assignment->delete();

        return response()->json(['message' => 'Role assignment revoked.']);
    }
}
