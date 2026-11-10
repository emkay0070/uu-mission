<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair role-scope compatibility records for all existing system roles.
 *
 * The role_scope_compatibilities table is populated by the SystemRole model's
 * creation hook, but roles created outside Eloquent (or before the hook was
 * added) have no compatibility records, making every role assignment fail
 * UserRoleAssignmentPolicy with 403 "This action is unauthorized".
 *
 * This migration is forward-only and idempotent: it uses updateOrInsert so
 * re-running never creates duplicates and never removes or broadens existing
 * rows.
 */
return new class extends Migration
{
    /**
     * Scope coverage per role scope_type, matching the SystemRole model hook.
     *
     * global   → global
     * org_unit → org_unit
     * campaign → org_unit, campaign
     * site     → org_unit, campaign, site
     * team     → org_unit, campaign, site, team
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_SCOPE_MATRIX = [
        'global' => ['global'],
        'org_unit' => ['org_unit'],
        'campaign' => ['org_unit', 'campaign'],
        'site' => ['org_unit', 'campaign', 'site'],
        'team' => ['org_unit', 'campaign', 'site', 'team'],
    ];

    /**
     * Run the migrations.
     */
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
    }

    /**
     * Reverse the migrations.
     *
     * The compatibility matrix is system reference data, so the repair is
     * intentionally irreversible.
     */
    public function down(): void
    {
        //
    }
};
