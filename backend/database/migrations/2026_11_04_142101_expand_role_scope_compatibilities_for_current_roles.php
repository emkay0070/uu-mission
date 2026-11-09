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
        foreach (DB::table('system_roles')->get() as $role) {
            $compatible = match ($role->scope_type) {
                'global' => ['global'],
                'org_unit' => ['org_unit'],
                'campaign' => ['org_unit', 'campaign'],
                'site' => ['org_unit', 'campaign', 'site'],
                'team' => ['org_unit', 'campaign', 'site', 'team'],
                default => [$role->scope_type],
            };
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
     */
    public function down(): void
    {
        // The initial compatibility matrix remains in place; this migration only
        // adds the organizational/operational scopes that current role families use.
    }
};
