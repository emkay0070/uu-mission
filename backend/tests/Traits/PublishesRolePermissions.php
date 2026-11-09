<?php

namespace Tests\Traits;

use App\Models\RolePermissionVersion;
use App\Models\SystemRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait PublishesRolePermissions
{
    protected function publishRolePermissions(SystemRole $role, array|Collection|int $permissionIds, bool $merge = false): void
    {
        $ids = is_int($permissionIds) ? [$permissionIds] : collect($permissionIds)->map(fn ($id) => (int) $id)->all();
        if ($merge) {
            $ids = array_values(array_unique(array_merge($role->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->all(), $ids)));
        }
        sort($ids);
        $version = RolePermissionVersion::create([
            'system_role_id' => $role->id,
            'version' => (int) $role->permissionVersions()->max('version') + 1,
            'change_reason' => 'Test fixture permission version',
            'published_at' => null,
        ]);
        $version->permissions()->sync($ids);
        $version->update(['published_at' => now()]);
        $role->update(['current_permission_version_id' => $version->id]);
        $role->permissions()->sync($ids);
    }

    protected function delegateRoleAt(SystemRole $role, string $scopeType, int $scopeId): void
    {
        $scopeColumn = match ($scopeType) {
            'org_unit' => 'scoped_org_unit_id',
            'campaign' => 'scoped_campaign_id',
            'site' => 'scoped_site_id',
            'team' => 'scoped_team_id',
            default => null,
        };
        foreach ($role->currentPermissionVersion->permissions as $permission) {
            DB::table('permission_delegations')->insertOrIgnore([
                'permission_id' => $permission->id, 'scope_type' => $scopeType,
                'scoped_org_unit_id' => $scopeType === 'org_unit' ? $scopeId : null,
                'scoped_campaign_id' => $scopeType === 'campaign' ? $scopeId : null,
                'scoped_site_id' => $scopeType === 'site' ? $scopeId : null,
                'scoped_team_id' => $scopeType === 'team' ? $scopeId : null,
                'delegated_by_user_id' => null, 'reason' => 'Explicit permission delegation in test fixture',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
