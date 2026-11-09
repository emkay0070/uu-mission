<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissionIds = [];
        foreach ([
            ['code' => 'resources.view', 'label' => 'View missionary resources', 'module' => 'resources'],
            ['code' => 'resources.create', 'label' => 'Create and submit resources', 'module' => 'resources'],
        ] as $permission) {
            DB::table('permissions')->insertOrIgnore([...$permission, 'created_at' => now()]);
            $permissionIds[] = (int) DB::table('permissions')->where('code', $permission['code'])->value('id');
        }

        $role = DB::table('system_roles')->where('code', 'missionary')->first();
        if (! $role || ! $role->current_permission_version_id) {
            return;
        }
        $existingIds = DB::table('role_permission_version_items')
            ->where('role_permission_version_id', $role->current_permission_version_id)
            ->pluck('permission_id')->map(fn ($id) => (int) $id)->all();
        $newIds = array_values(array_unique(array_merge($existingIds, $permissionIds)));
        sort($newIds);
        if ($newIds === $existingIds) {
            return;
        }
        $versionNumber = (int) DB::table('role_permission_versions')->where('system_role_id', $role->id)->max('version') + 1;
        $versionId = DB::table('role_permission_versions')->insertGetId([
            'system_role_id' => $role->id, 'version' => $versionNumber,
            'change_reason' => 'Active missionaries receive resources.view and resources.create by default',
            'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($newIds as $permissionId) {
            DB::table('role_permission_version_items')->insert([
                'role_permission_version_id' => $versionId, 'permission_id' => $permissionId,
            ]);
        }
        DB::table('system_roles')->where('id', $role->id)->update(['current_permission_version_id' => $versionId]);
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The published permission version is intentionally retained. A rollback
        // must not silently remove permissions already received by assignments.
    }
};
