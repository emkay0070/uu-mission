<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\MissionSite;
use App\Models\Person;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\UserRoleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $request->validate([
            'site_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::ASSIGNMENT_STATUSES)],
        ]);

        // Resolve the site BEFORE authorising so a site-scoped coordinator or
        // secretary — who holds assignments.view only inside their own site —
        // can still list that site's assignments. Listing without a site stays
        // a campaign-scoped decision, so the narrower site admission never
        // widens a campaign-scoped caller.
        $site = $request->filled('site_id')
            ? $this->resolveSite($campaign, (int) $request->site_id)
            : null;

        $this->authorize('viewAny', [CampaignAssignment::class, $campaign, $site]);

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
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'mission_site_id' => ['sometimes', 'nullable', 'integer'],
            'assigned_team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'functional_role_type_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_role_types,id'],
            'system_role_id' => ['sometimes', 'nullable', 'integer', 'exists:system_roles,id'],
            'expected_arrival' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // Validate site belongs to this campaign
        if (! empty($validated['mission_site_id'])) {
            $this->validateSiteScope($campaign, $validated['mission_site_id']);
        }

        // Validate team belongs to this campaign and, when the deployment also
        // names a site, that the team actually operates there.
        if (! empty($validated['assigned_team_id'])) {
            $this->validateTeamScope($campaign, $validated['assigned_team_id']);
            $this->validateTeamBelongsToSite(
                $validated['assigned_team_id'],
                $validated['mission_site_id'] ?? null,
            );
        }

        // One assignment per person per campaign
        if ($campaign->assignments()->where('person_id', $validated['person_id'])->exists()) {
            return response()->json([
                'message' => 'This person already has an assignment in this campaign. Use PATCH to update it.',
            ], 422);
        }

        $person = Person::findOrFail($validated['person_id']);

        // A system role is granted to a user account, so the person must have
        // one. Refuse the request outright rather than silently saving the
        // campaign assignment without the role the administrator asked for.
        $this->assertPersonCanHoldSystemRole($person, $validated['system_role_id'] ?? null);

        $assignment = DB::transaction(function () use ($campaign, $validated, $request, $person): CampaignAssignment {
            $rolePayload = null;
            if (! empty($validated['system_role_id'])) {
                $role = SystemRole::whereKey($validated['system_role_id'])->lockForUpdate()->firstOrFail();
                $rolePayload = $this->roleAssignmentPayload($campaign, $role, $validated, $person->user_id);
                $rolePayload['role_permission_version_id'] = $role->current_permission_version_id;
                $this->authorize('create', [UserRoleAssignment::class, $rolePayload]);
            }
            $assignment = $campaign->assignments()->create([
                ...$validated,
                'status' => 'confirmed',
                'assigned_by_user_id' => $request->user()->id,
            ]);

            if ($rolePayload) {
                $this->createSystemRoleAssignment($request, $rolePayload);
            }

            return $assignment;
        });

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
            'mission_site_id' => ['sometimes', 'nullable', 'integer'],
            'assigned_team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'functional_role_type_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_role_types,id'],
            'system_role_id' => ['sometimes', 'nullable', 'integer', 'exists:system_roles,id'],
            'status' => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::ASSIGNMENT_STATUSES)],
            'expected_arrival' => ['sometimes', 'nullable', 'date'],
            'actual_arrival' => ['sometimes', 'nullable', 'date'],
            'departure' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // Effective deployment after this update: a field absent from the
        // payload keeps its current value, an explicit null clears it.
        $effectiveSiteId = array_key_exists('mission_site_id', $validated) ? $validated['mission_site_id'] : $assignment->mission_site_id;
        $effectiveTeamId = array_key_exists('assigned_team_id', $validated) ? $validated['assigned_team_id'] : $assignment->assigned_team_id;

        // Validate the resulting deployment still respects campaign/site bounds.
        if (! empty($effectiveSiteId)) {
            $this->validateSiteScope($campaign, $effectiveSiteId);
        }
        if (! empty($effectiveTeamId)) {
            $this->validateTeamScope($campaign, $effectiveTeamId);
            $this->validateTeamBelongsToSite($effectiveTeamId, $effectiveSiteId);
        }

        $oldRoleId = $assignment->system_role_id;
        $roleChanged = array_key_exists('system_role_id', $validated) && (int) $validated['system_role_id'] !== (int) $oldRoleId;
        $person = $assignment->person;
        $newRolePayload = null;
        if ($roleChanged && ! empty($validated['system_role_id'])) {
            // Same rule as store(): a system role cannot be granted when the
            // person has no user account to receive it. Fail before anything is
            // written so the assignment is not left partially updated.
            $this->assertPersonCanHoldSystemRole($person, $validated['system_role_id']);
            $newRole = SystemRole::findOrFail($validated['system_role_id']);
            // The role's own scope_type decides the assignment scope; the
            // deployment site/team only supply the scope identifier when the
            // role happens to be site- or team-scoped.
            $newRolePayload = $this->roleAssignmentPayload($campaign, $newRole, [
                'mission_site_id' => $effectiveSiteId,
                'assigned_team_id' => $effectiveTeamId,
            ], $person->user_id);
        }
        $oldRoleAssignment = null;
        if ($roleChanged && $oldRoleId && $person->user_id) {
            $oldRole = SystemRole::find($oldRoleId);
            if ($oldRole) {
                $oldRoleAssignment = $this->findExistingRoleAssignment(
                    (int) $person->user_id,
                    $oldRole,
                    $campaign,
                    [
                        'mission_site_id' => $assignment->mission_site_id,
                        'assigned_team_id' => $assignment->assigned_team_id,
                    ],
                );
                if ($oldRoleAssignment) {
                    $this->authorize('update', $oldRoleAssignment);
                }
            }
        }

        DB::transaction(function () use ($assignment, $validated, $request, $roleChanged, $oldRoleAssignment, $newRolePayload): void {
            if ($newRolePayload) {
                $role = SystemRole::whereKey($newRolePayload['system_role_id'])->lockForUpdate()->firstOrFail();
                $newRolePayload['role_permission_version_id'] = $role->current_permission_version_id;
                $this->authorize('create', [UserRoleAssignment::class, $newRolePayload]);
            }
            $assignment->update($validated);
            if (! $roleChanged) {
                return;
            }
            if ($oldRoleAssignment) {
                $oldRoleAssignment->update([
                    'status' => 'revoked', 'revoked_by_user_id' => $request->user()->id,
                    'revoked_at' => now(), 'revocation_reason' => 'Campaign assignment role changed or removed.',
                ]);
                DB::table('user_role_assignment_events')->insert([
                    'user_role_assignment_id' => $oldRoleAssignment->id, 'action' => 'revoked',
                    'actor_user_id' => $request->user()->id,
                    'reason' => 'Campaign assignment role changed or removed.', 'created_at' => now(),
                ]);
            }
            if ($newRolePayload) {
                $this->createSystemRoleAssignment($request, $newRolePayload);
            }
        });

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

        $roleAssignment = $this->findSystemRoleAssignment($assignment, $campaign);
        if ($roleAssignment) {
            $this->authorize('update', $roleAssignment);
        }
        DB::transaction(function () use ($assignment, $roleAssignment, $request): void {
            if ($roleAssignment) {
                $reason = 'Campaign deployment removed.';
                $roleAssignment->update([
                    'status' => 'revoked', 'revoked_by_user_id' => $request->user()->id,
                    'revoked_at' => now(), 'revocation_reason' => $reason,
                ]);
                DB::table('user_role_assignment_events')->insert([
                    'user_role_assignment_id' => $roleAssignment->id, 'action' => 'revoked',
                    'actor_user_id' => $request->user()->id, 'reason' => $reason, 'created_at' => now(),
                ]);
            }
            $assignment->delete();
        });

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
        $request->validate([
            'site_id' => ['sometimes', 'integer'],
        ]);

        // Match the listing's admission: when a site is named, authorise at
        // that site so a site-scoped coordinator can resolve the site's own
        // authorised users. Without a site, the campaign-wide decision stands.
        $site = $request->filled('site_id')
            ? $this->resolveSite($campaign, (int) $request->site_id)
            : null;

        $this->authorize('viewAny', [CampaignAssignment::class, $campaign, $site]);

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
        $this->resolveSite($campaign, $siteId);
    }

    /**
     * Resolve a mission site and prove it belongs to the given campaign.
     *
     * Returns the MissionSite so the caller can use it in an authorization
     * check (admission at the named site), or aborts 422 when the site does
     * not belong to the campaign.
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

    private function validateTeamScope(Campaign $campaign, int $teamId): void
    {
        $exists = $campaign->teams()->where('id', $teamId)->exists();

        if (! $exists) {
            abort(422, "Team {$teamId} does not belong to campaign {$campaign->id}.");
        }
    }

    /**
     * Ensure a selected team actually operates at the selected site.
     *
     * A team with no site is campaign-wide and may be paired with any site. A
     * team bound to a specific site may only be deployed there, otherwise the
     * deployment would silently place a person on a team belonging elsewhere.
     */
    private function validateTeamBelongsToSite(int $teamId, ?int $siteId): void
    {
        if ($siteId === null) {
            return;
        }

        $teamSiteId = Team::whereKey($teamId)->value('mission_site_id');

        if ($teamSiteId !== null && (int) $teamSiteId !== (int) $siteId) {
            abort(422, "Team {$teamId} does not belong to site {$siteId}.");
        }
    }

    /**
     * Ensure a person has a linked user account before a system role is granted.
     *
     * A system role is granted to a user account, so a person without one has
     * nowhere to receive it. Previously the role was skipped silently while the
     * campaign assignment still saved, leaving the administrator with a
     * misleading success response. When no system role was requested the
     * assignment remains a normal person-to-campaign deployment.
     */
    private function assertPersonCanHoldSystemRole(Person $person, mixed $systemRoleId): void
    {
        if (empty($systemRoleId)) {
            return;
        }

        if ($person->user_id === null) {
            throw ValidationException::withMessages([
                'system_role_id' => ['This person does not have a linked user account, so a system role cannot be assigned. Link a user account to this person first.'],
            ]);
        }
    }

    /**
     * Build the user_role_assignments payload for a campaign deployment.
     *
     * The assignment scope comes from the selected role's own scope_type, never
     * from whether a deployment team/site was chosen. Deploying a Site
     * Coordinator onto a team therefore still produces a site-scoped role
     * assignment: the deployment fact (campaign/site/team) and the role's
     * authorization scope are separate concepts.
     */
    private function roleAssignmentPayload(Campaign $campaign, SystemRole $role, array $data, int $userId): array
    {
        $resolved = $this->resolveRoleScope($role, $campaign, $data);

        $payload = [
            'user_id' => $userId,
            'system_role_id' => (int) $role->id,
            'scope_type' => $resolved['scope_type'],
        ];

        if ($resolved['column'] !== null) {
            $payload[$resolved['column']] = $resolved['id'];
        }

        return $payload;
    }

    /**
     * Translate a role's scope_type into a concrete assignment scope plus the
     * scope identifier drawn from the campaign deployment context.
     *
     * Missing context for a scope that needs it raises a 422 that names the
     * offending field, so invalid request data surfaces as validation rather
     * than an opaque 403 from the policy.
     *
     * @return array{scope_type: string, column: string|null, id: int|null}
     */
    private function resolveRoleScope(SystemRole $role, Campaign $campaign, array $data): array
    {
        return match ($role->scope_type) {
            'global' => ['scope_type' => 'global', 'column' => null, 'id' => null],
            'org_unit' => $this->requireScopeId($role, 'org_unit', 'scoped_org_unit_id', $campaign->lead_org_unit_id, 'organisational unit', 'system_role_id'),
            'campaign' => ['scope_type' => 'campaign', 'column' => 'scoped_campaign_id', 'id' => (int) $campaign->id],
            'site' => $this->requireScopeId($role, 'site', 'scoped_site_id', $data['mission_site_id'] ?? null, 'mission site', 'mission_site_id'),
            'team' => $this->requireScopeId($role, 'team', 'scoped_team_id', $data['assigned_team_id'] ?? null, 'team', 'assigned_team_id'),
            default => throw ValidationException::withMessages([
                'system_role_id' => ["The selected role '{$role->label}' has an unsupported scope '{$role->scope_type}' and cannot be assigned from a campaign deployment."],
            ]),
        };
    }

    /**
     * Require the identifier a role's scope depends on.
     *
     * @return array{scope_type: string, column: string, id: int}
     */
    private function requireScopeId(SystemRole $role, string $scopeType, string $column, mixed $identifier, string $label, string $errorField): array
    {
        if (empty($identifier)) {
            throw ValidationException::withMessages([
                $errorField => ["The selected role '{$role->label}' is {$scopeType}-scoped and requires a {$label} to be selected before it can be assigned."],
            ]);
        }

        return ['scope_type' => $scopeType, 'column' => $column, 'id' => (int) $identifier];
    }

    private function createSystemRoleAssignment(Request $request, array $payload): UserRoleAssignment
    {
        $payload['granted_by_user_id'] = $request->user()->id;
        $payload['role_permission_version_id'] ??= SystemRole::whereKey($payload['system_role_id'])->value('current_permission_version_id');
        $assignment = UserRoleAssignment::create($payload);
        DB::table('user_role_assignment_events')->insert([
            'user_role_assignment_id' => $assignment->id, 'action' => 'created',
            'actor_user_id' => $request->user()->id, 'reason' => 'Role assigned through campaign deployment.',
            'changes' => json_encode(['role_permission_version_id' => $assignment->role_permission_version_id, 'scope_type' => $assignment->scope_type]),
            'created_at' => now(),
        ]);

        return $assignment;
    }

    private function findSystemRoleAssignment(CampaignAssignment $assignment, Campaign $campaign): ?UserRoleAssignment
    {
        $userId = $assignment->person?->user_id;
        if (! $userId || ! $assignment->system_role_id) {
            return null;
        }

        $role = SystemRole::find($assignment->system_role_id);
        if (! $role) {
            return null;
        }

        return $this->findExistingRoleAssignment((int) $userId, $role, $campaign, [
            'mission_site_id' => $assignment->mission_site_id,
            'assigned_team_id' => $assignment->assigned_team_id,
        ]);
    }

    /**
     * Locate the live role assignment a campaign deployment previously created
     * for a given role, resolving the scope the same role-driven way.
     *
     * Returns null when the deployment context cannot satisfy the role's scope,
     * so a revoke/replace lookup never throws while the caller is cleaning up.
     */
    private function findExistingRoleAssignment(int $userId, SystemRole $role, Campaign $campaign, array $data): ?UserRoleAssignment
    {
        try {
            $resolved = $this->resolveRoleScope($role, $campaign, $data);
        } catch (ValidationException) {
            return null;
        }

        $query = UserRoleAssignment::where('user_id', $userId)
            ->where('system_role_id', $role->id)
            ->where('scope_type', $resolved['scope_type'])
            ->where('status', '!=', 'revoked');

        if ($resolved['column'] !== null) {
            $query->where($resolved['column'], $resolved['id']);
        }

        return $query->first();
    }
}
