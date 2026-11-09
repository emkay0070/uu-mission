<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\UserRoleAssignment;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function indexForOrgUnit(Request $request, OrgUnit $orgUnit): JsonResponse
    {
        $this->authorize('viewAnyForOrgUnit', [UserRoleAssignment::class, $orgUnit]);
        $assignments = UserRoleAssignment::with(['user.person', 'role', 'permissionVersion', 'grantedBy'])
            ->where('scope_type', 'org_unit')
            ->where('scoped_org_unit_id', $orgUnit->id)
            ->orderByDesc('created_at')
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'system_role_id' => ['required', 'integer', 'exists:system_roles,id'],
            'scope_type' => ['required', 'string', 'in:global,org_unit,campaign,site,team'],
            'scoped_org_unit_id' => ['required_if:scope_type,org_unit', 'nullable', 'integer', 'exists:org_units,id', 'prohibited_unless:scope_type,org_unit'],
            'scoped_campaign_id' => ['required_if:scope_type,campaign', 'nullable', 'integer', 'exists:campaigns,id', 'prohibited_unless:scope_type,campaign'],
            'scoped_site_id' => ['required_if:scope_type,site', 'nullable', 'integer', 'exists:mission_sites,id', 'prohibited_unless:scope_type,site'],
            'scoped_team_id' => ['required_if:scope_type,team', 'nullable', 'integer', 'exists:teams,id', 'prohibited_unless:scope_type,team'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
        ]);

        $result = DB::transaction(function () use ($request, $validated) {
            $role = SystemRole::whereKey($validated['system_role_id'])->lockForUpdate()->firstOrFail();
            $validated['role_permission_version_id'] = $role->current_permission_version_id;
            $this->authorize('create', [UserRoleAssignment::class, $validated]);

            $existing = UserRoleAssignment::where('user_id', $validated['user_id'])
                ->where('system_role_id', $validated['system_role_id'])
                ->where('status', '!=', 'revoked')
                ->where('scope_type', $validated['scope_type'])
                ->where('scoped_org_unit_id', $validated['scoped_org_unit_id'] ?? null)
                ->where('scoped_campaign_id', $validated['scoped_campaign_id'] ?? null)
                ->where('scoped_site_id', $validated['scoped_site_id'] ?? null)
                ->where('scoped_team_id', $validated['scoped_team_id'] ?? null)
                ->first();
            if ($existing) {
                return response()->json(['message' => 'This user already has this role at the specified scope.'], 409);
            }

            $assignment = UserRoleAssignment::create([
                ...$validated,
                'granted_by_user_id' => $request->user()->id,
            ]);
            DB::table('user_role_assignment_events')->insert([
                'user_role_assignment_id' => $assignment->id, 'action' => 'created',
                'actor_user_id' => $request->user()->id, 'reason' => 'Role assignment created.',
                'changes' => json_encode(['role_permission_version_id' => $assignment->role_permission_version_id, 'scope_type' => $assignment->scope_type]),
                'created_at' => now(),
            ]);

            return $assignment;
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $assignment = $result;
        $this->auth->clearUserCache($assignment->user);

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
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'status' => ['sometimes', 'in:active,suspended,revoked'],
            'reason' => ['required_with:status', 'string', 'min:3', 'max:2000'],
            'role_permission_version_id' => ['sometimes', 'integer', 'exists:role_permission_versions,id'],
        ]);
        if ($assignment->status === 'revoked' && ($validated['status'] ?? null) !== 'revoked') {
            return response()->json(['message' => 'A revoked assignment cannot be reinstated or edited.'], 422);
        }
        if ($assignment->status === 'revoked' && ($validated['status'] ?? null) === 'revoked') {
            return response()->json(['message' => 'Role assignment is already permanently revoked.'], 409);
        }
        $before = $assignment->only(['valid_from', 'valid_until', 'status', 'role_permission_version_id']);
        $changes = collect($validated)->except('reason')->all();
        $actorId = $request->user()->id;
        if (isset($changes['role_permission_version_id'])) {
            abort_unless($request->user()->can('updateVersion', [$assignment, (int) $changes['role_permission_version_id']]), 403);
            if (empty($validated['reason'])) {
                return response()->json(['message' => 'A reason is required when changing an assignment permission version.'], 422);
            }
        }
        if (($changes['status'] ?? null) === 'revoked') {
            $changes += ['revoked_by_user_id' => $actorId, 'revoked_at' => now(), 'revocation_reason' => $validated['reason']];
        } elseif (($changes['status'] ?? null) === 'suspended') {
            $changes += ['suspended_by_user_id' => $actorId, 'suspended_at' => now(), 'suspension_reason' => $validated['reason']];
        } elseif (($changes['status'] ?? null) === 'active' && $assignment->status === 'suspended') {
            $changes += ['suspended_by_user_id' => null, 'suspended_at' => null, 'suspension_reason' => null];
        }
        $assignment->update($changes);
        DB::table('user_role_assignment_events')->insert([
            'user_role_assignment_id' => $assignment->id,
            'action' => ($changes['status'] ?? null) === 'revoked' ? 'revoked' : (($changes['status'] ?? null) === 'suspended' ? 'suspended' : (($changes['status'] ?? null) === 'active' && $before['status'] === 'suspended' ? 'reinstated' : (isset($changes['role_permission_version_id']) ? 'version_updated' : 'updated'))),
            'actor_user_id' => $actorId, 'reason' => $validated['reason'] ?? null,
            'changes' => json_encode(['before' => $before, 'after' => $assignment->only(['valid_from', 'valid_until', 'status', 'role_permission_version_id'])]), 'created_at' => now(),
        ]);
        $this->auth->clearUserCache($assignment->user);

        return response()->json($assignment->load(['user.person', 'role', 'grantedBy']));
    }

    /**
     * Delete/revoke an assignment.
     * DELETE /api/v1/role-assignments/{assignment}
     */
    public function destroy(UserRoleAssignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);

        if ($assignment->status === 'revoked') {
            return response()->json(['message' => 'Role assignment is already revoked.'], 409);
        }
        $reason = request()->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']])['reason'];
        $assignment->update(['status' => 'revoked', 'revoked_by_user_id' => request()->user()->id, 'revoked_at' => now(), 'revocation_reason' => $reason]);
        DB::table('user_role_assignment_events')->insert(['user_role_assignment_id' => $assignment->id, 'action' => 'revoked', 'actor_user_id' => request()->user()->id, 'reason' => $reason, 'created_at' => now()]);
        $this->auth->clearUserCache($assignment->user);

        return response()->json(['message' => 'Role assignment revoked.']);
    }

    public function setPermissionOverride(Request $request, UserRoleAssignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);
        $data = $request->validate([
            'permission_code' => ['required', 'string', 'exists:permissions,code'],
            'effect' => ['required', 'in:grant,deny'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        [$scopeType, $scopeId] = $this->auth->assignmentScope($assignment);
        if ($data['effect'] === 'grant' && ! $this->auth->hasPermissionDelegation($data['permission_code'], $scopeType, $scopeId)) {
            return response()->json(['message' => 'No permission delegation is configured for this permission and scope.'], 403);
        }
        $permission = Permission::where('code', $data['permission_code'])->firstOrFail();
        $action = $assignment->permissionOverrides()->where('permission_id', $permission->id)->exists() ? 'changed' : 'created';
        $override = $assignment->permissionOverrides()->updateOrCreate(
            ['permission_id' => $permission->id],
            ['effect' => $data['effect'], 'created_by_user_id' => $request->user()->id, 'reason' => $data['reason']]
        );
        DB::table('user_role_assignment_override_events')->insert([
            'user_role_assignment_id' => $assignment->id, 'permission_id' => $permission->id,
            'effect' => $data['effect'], 'action' => $action, 'actor_user_id' => $request->user()->id,
            'reason' => $data['reason'], 'created_at' => now(),
        ]);
        $this->auth->clearUserCache($assignment->user);

        return response()->json($override->load('permission'), 201);
    }

    public function removePermissionOverride(Request $request, UserRoleAssignment $assignment, Permission $permission): JsonResponse
    {
        $this->authorize('update', $assignment);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $override = $assignment->permissionOverrides()->where('permission_id', $permission->id)->firstOrFail();
        DB::table('user_role_assignment_override_events')->insert([
            'user_role_assignment_id' => $assignment->id, 'permission_id' => $permission->id,
            'effect' => null, 'action' => 'removed', 'actor_user_id' => $request->user()->id,
            'reason' => $data['reason'], 'created_at' => now(),
        ]);
        $override->delete();
        $this->auth->clearUserCache($assignment->user);

        return response()->json(['message' => 'Permission override removed.']);
    }
}
