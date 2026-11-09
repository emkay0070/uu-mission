<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reference-data repair for the foundational RBAC rollout.
 *
 * The role-scope compatibility matrix is defined by
 * 2026_11_04_141312_add_foundational_rbac_versioning_and_lifecycle, by
 * 2026_11_04_142101_expand_role_scope_compatibilities_for_current_roles, and
 * by the SystemRole model's creation hook — but on databases that were built
 * before that matrix existed (or whose role rows were created outside the
 * hook), the role_scope_compatibilities table ends up empty and every
 * system-role assignment is unauthorised by UserRoleAssignmentPolicy.
 *
 * This migration restores the matrix for every existing role (idempotent —
 * it never widens or removes an existing row) and backfills the permission
 * version pin on legacy assignments exactly the way 141312 did, so
 * effective permissions resolve for roles whose assignments were created
 * without a version reference.
 */
return new class extends Migration
{
    /**
     * Scope coverage per role scope_type, same matrix as the SystemRole model.
     */
    private const ROLE_SCOPE_MATRIX = [
        'global' => ['global'],
        'org_unit' => ['org_unit'],
        'campaign' => ['org_unit', 'campaign'],
        'site' => ['org_unit', 'campaign', 'site'],
        'team' => ['org_unit', 'campaign', 'site', 'team'],
    ];

    public function up(): void
    {
        foreach (DB::table('system_roles')->orderBy('id')->get() as $role) {
            $compatible = self::ROLE_SCOPE_MATRIX[$role->scope_type] ?? [$role->scope_type];

            foreach ($compatible as $scopeType) {
                DB::table('role_scope_compatibilities')->updateOrInsert(
                    ['system_role_id' => $role->id, 'scope_type' => $scopeType],
                    ['scope_type' => $scopeType]
                );
            }
        }

        DB::statement('
            UPDATE user_role_assignments ura
            SET role_permission_version_id = sr.current_permission_version_id
            FROM system_roles sr
            WHERE sr.id = ura.system_role_id
              AND ura.role_permission_version_id IS NULL
              AND sr.current_permission_version_id IS NOT NULL
        ');
    }

    /**
     * The compatibility matrix is system reference data, not demo content, so
     * the repair is intentionally irreversible.
     */
    public function down(): void {}
};
