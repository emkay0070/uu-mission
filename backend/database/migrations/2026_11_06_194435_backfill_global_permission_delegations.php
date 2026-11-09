<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill global permission delegations for every permission.
     *
     * UserRoleAssignmentPolicy requires every permission carried by a role
     * to have an active delegation covering the target scope (site, campaign,
     * org unit or global). No delegations were ever seeded, so every
     * system-role assignment — for example assigning Site Coordinator to a
     * missionary from Campaign Deployments — failed with 403 "This action
     * is unauthorized". A global delegation authorises the permission at any
     * scope; role scope compatibility still constrains where each role may
     * be assigned.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permission_delegations')) {
            return;
        }

        foreach (DB::table('permissions')->orderBy('id')->get() as $permission) {
            $exists = DB::table('permission_delegations')
                ->where('permission_id', $permission->id)
                ->where('scope_type', 'global')
                ->where('is_active', true)
                ->exists();

            if ($exists) {
                continue;
            }

            $delegationId = DB::table('permission_delegations')->insertGetId([
                'permission_id' => $permission->id,
                'scope_type' => 'global',
                'is_active' => true,
                'reason' => 'Baseline global delegation for system role defaults',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('permission_delegation_events')) {
                DB::table('permission_delegation_events')->insert([
                    'permission_delegation_id' => $delegationId,
                    'action' => 'created',
                    'actor_user_id' => null,
                    'reason' => 'Baseline global delegation for system role defaults',
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * Baseline reference data; the backfill is intentionally irreversible.
     */
    public function down(): void {}
};
