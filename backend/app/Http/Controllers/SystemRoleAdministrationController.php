<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\Permission;
use App\Models\RolePermissionVersion;
use App\Models\SystemRole;
use App\Models\Team;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemRoleAdministrationController extends Controller
{
    public function __construct(private AuthorizationService $authorization) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->authorization->hasGlobalPermission($request->user(), 'admin.roles.manage'), 403);
        $roles = SystemRole::with('currentPermissionVersion.permissions')->orderBy('label')->get()->map(function (SystemRole $role) {
            $role->setAttribute('compatible_scope_types', DB::table('role_scope_compatibilities')->where('system_role_id', $role->id)->orderBy('scope_type')->pluck('scope_type'));

            return $role;
        });

        return response()->json($roles);
    }

    public function versions(Request $request, SystemRole $role): JsonResponse
    {
        abort_unless($this->canManageRole($request, $role), 403);

        return response()->json($role->permissionVersions()->with('permissions')->orderByDesc('version')->get());
    }

    public function delegations(Request $request): JsonResponse
    {
        abort_unless($this->authorization->hasGlobalPermission($request->user(), 'admin.roles.manage'), 403);

        return response()->json(DB::table('permission_delegations as d')
            ->join('permissions as p', 'p.id', '=', 'd.permission_id')
            ->select('d.*', 'p.code as permission_code')
            ->orderBy('p.code')->orderBy('d.scope_type')->orderBy('d.id')->get());
    }

    public function publishPermissions(Request $request, SystemRole $role): JsonResponse
    {
        $data = $request->validate([
            'permission_codes' => ['required', 'array'],
            'permission_codes.*' => ['required', 'string', 'distinct', 'exists:permissions,code'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        abort_unless($this->canManageRole($request, $role), 403);

        $permissions = Permission::whereIn('code', $data['permission_codes'])->orderBy('id')->get();
        $current = $role->currentPermissionVersion;
        if ($current && $current->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->sort()->values()->all() === $permissions->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all()) {
            return response()->json(['message' => 'The requested permission set is already current.'], 200);
        }

        $version = DB::transaction(function () use ($role, $permissions, $request, $data) {
            $lockedRole = SystemRole::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $version = RolePermissionVersion::create([
                'system_role_id' => $lockedRole->id,
                'version' => (int) $lockedRole->permissionVersions()->max('version') + 1,
                'created_by_user_id' => $request->user()->id,
                'change_reason' => $data['reason'],
                'published_at' => null,
            ]);
            $version->permissions()->sync($permissions->modelKeys());
            $version->update(['published_at' => now()]);
            $lockedRole->update(['current_permission_version_id' => $version->id]);
            $lockedRole->permissions()->sync($permissions->modelKeys());

            return $version;
        });

        return response()->json($version->load('permissions'), 201);
    }

    public function setCompatibleScopes(Request $request, SystemRole $role): JsonResponse
    {
        $data = $request->validate([
            'scope_types' => ['required', 'array', 'min:1'],
            'scope_types.*' => ['required', 'distinct', 'in:global,org_unit,campaign,site,team'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        abort_unless($this->authorization->hasGlobalPermission($request->user(), 'admin.roles.manage'), 403);
        if (in_array('global', $data['scope_types'], true) && $role->scope_type !== 'global') {
            return response()->json(['message' => 'This role is not configured for global assignment.'], 422);
        }
        $incompatible = DB::table('user_role_assignments')->where('system_role_id', $role->id)->whereNotIn('scope_type', $data['scope_types'])->exists();
        if ($incompatible) {
            return response()->json(['message' => 'Compatibility cannot remove a scope currently used by an assignment.'], 422);
        }
        DB::transaction(function () use ($role, $data, $request): void {
            DB::table('role_scope_compatibilities')->where('system_role_id', $role->id)->delete();
            foreach ($data['scope_types'] as $scopeType) {
                DB::table('role_scope_compatibilities')->insert(['system_role_id' => $role->id, 'scope_type' => $scopeType]);
            }
            DB::table('role_scope_compatibility_events')->insert([
                'system_role_id' => $role->id, 'actor_user_id' => $request->user()->id,
                'scope_types' => json_encode($data['scope_types']), 'reason' => $data['reason'], 'created_at' => now(),
            ]);
        });

        return response()->json(['system_role_id' => $role->id, 'scope_types' => $data['scope_types']]);
    }

    public function createDelegation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'permission_code' => ['required', 'string', 'exists:permissions,code'],
            'scope_type' => ['required', 'in:global,org_unit,campaign,site,team'],
            'scoped_org_unit_id' => ['required_if:scope_type,org_unit', 'nullable', 'integer', 'exists:org_units,id', 'prohibited_unless:scope_type,org_unit'],
            'scoped_campaign_id' => ['required_if:scope_type,campaign', 'nullable', 'integer', 'exists:campaigns,id', 'prohibited_unless:scope_type,campaign'],
            'scoped_site_id' => ['required_if:scope_type,site', 'nullable', 'integer', 'exists:mission_sites,id', 'prohibited_unless:scope_type,site'],
            'scoped_team_id' => ['required_if:scope_type,team', 'nullable', 'integer', 'exists:teams,id', 'prohibited_unless:scope_type,team'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        if (! $this->canManageTarget($request, $data)) {
            return response()->json(['message' => 'No delegated administrative authority exists for this scope.'], 403);
        }

        $delegationId = DB::table('permission_delegations')->insertGetId([
            'permission_id' => Permission::where('code', $data['permission_code'])->value('id'),
            'scope_type' => $data['scope_type'],
            'scoped_org_unit_id' => $data['scoped_org_unit_id'] ?? null,
            'scoped_campaign_id' => $data['scoped_campaign_id'] ?? null,
            'scoped_site_id' => $data['scoped_site_id'] ?? null,
            'scoped_team_id' => $data['scoped_team_id'] ?? null,
            'delegated_by_user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('permission_delegation_events')->insert([
            'permission_delegation_id' => $delegationId, 'action' => 'created',
            'actor_user_id' => $request->user()->id, 'reason' => $data['reason'], 'created_at' => now(),
        ]);

        return response()->json(DB::table('permission_delegations')->find($delegationId), 201);
    }

    public function revokeDelegation(Request $request, int $delegation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $record = DB::table('permission_delegations')->where('id', $delegation)->first();
        abort_unless($record, 404);
        $target = [
            'scope_type' => $record->scope_type,
            'scoped_org_unit_id' => $record->scoped_org_unit_id,
            'scoped_campaign_id' => $record->scoped_campaign_id,
            'scoped_site_id' => $record->scoped_site_id,
            'scoped_team_id' => $record->scoped_team_id,
        ];
        abort_unless($this->canManageTarget($request, $target), 403);
        if (! $record->is_active) {
            return response()->json(['message' => 'Delegation is already revoked.'], 409);
        }
        DB::table('permission_delegations')->where('id', $delegation)->update(['is_active' => false, 'updated_at' => now()]);
        DB::table('permission_delegation_events')->insert([
            'permission_delegation_id' => $delegation, 'action' => 'revoked',
            'actor_user_id' => $request->user()->id, 'reason' => $data['reason'], 'created_at' => now(),
        ]);

        return response()->json(['message' => 'Permission delegation revoked.']);
    }

    private function canManageRole(Request $request, SystemRole $role): bool
    {
        // Role defaults govern every assignment of that role. Updating the role
        // definition therefore requires global role-administration authority.
        return $this->authorization->hasGlobalPermission($request->user(), 'admin.roles.manage');
    }

    private function canManageTarget(Request $request, array $target): bool
    {
        $user = $request->user();

        return match ($target['scope_type']) {
            'global' => $this->authorization->hasGlobalPermission($user, 'admin.roles.manage'),
            'org_unit' => $this->authorization->hasPermissionForOrgUnit($user, 'admin.roles.manage', (int) $target['scoped_org_unit_id']),
            'campaign' => $this->authorization->hasPermissionForCampaign($user, 'admin.roles.manage', Campaign::find($target['scoped_campaign_id'])),
            'site' => $this->authorization->hasPermissionForSite($user, 'admin.roles.manage', MissionSite::find($target['scoped_site_id']), false),
            'team' => $this->authorization->hasPermissionForTeam($user, 'admin.roles.manage', Team::find($target['scoped_team_id'])),
            default => false,
        };
    }
}
