<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add team scope support to user_role_assignments.
 *
 * This migration adds scoped_team_id column and updates the CHECK constraint
 * to support team-level role assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->foreignId('scoped_team_id')
                  ->nullable()
                  ->constrained('teams')
                  ->nullOnDelete();
        });

        // Update the unique constraint to include team
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->dropUnique('ura_unique_assignment');
            $table->unique(['user_id', 'system_role_id', 'scoped_org_unit_id', 'scoped_campaign_id', 'scoped_site_id', 'scoped_team_id'],
                           'ura_unique_assignment');
        });

        // Update index for team scope
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->index(['scope_type', 'scoped_team_id']);
        });

        // Drop and recreate CHECK constraint to include team scope
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE user_role_assignments
                DROP CONSTRAINT IF EXISTS chk_ura_scope_exclusive
            ");

            DB::statement("
                ALTER TABLE user_role_assignments
                ADD CONSTRAINT chk_ura_scope_exclusive CHECK (
                    (scope_type = 'global' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL)
                    OR
                    (scope_type = 'org_unit' AND scoped_org_unit_id IS NOT NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL)
                    OR
                    (scope_type = 'campaign' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NOT NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL)
                    OR
                    (scope_type = 'site' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NOT NULL AND scoped_team_id IS NULL)
                    OR
                    (scope_type = 'team' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NOT NULL)
                )
            ");
        }
    }

    public function down(): void
    {
        // Drop CHECK constraint
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE user_role_assignments
                DROP CONSTRAINT IF EXISTS chk_ura_scope_exclusive
            ");

            // Restore original CHECK constraint
            DB::statement("
                ALTER TABLE user_role_assignments
                ADD CONSTRAINT chk_ura_scope_exclusive CHECK (
                    (scope_type = 'global' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL)
                    OR
                    (scope_type = 'org_unit' AND scoped_org_unit_id IS NOT NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL)
                    OR
                    (scope_type = 'campaign' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NOT NULL AND scoped_site_id IS NULL)
                    OR
                    (scope_type = 'site' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NOT NULL)
                )
            ");
        }

        // Drop index
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->dropIndex(['scope_type', 'scoped_team_id']);
        });

        // Drop and recreate unique constraint
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->dropUnique('ura_unique_assignment');
            $table->unique(['user_id', 'system_role_id', 'scoped_org_unit_id', 'scoped_campaign_id', 'scoped_site_id'],
                           'ura_unique_assignment');
        });

        // Drop FK and column
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->dropForeign(['scoped_team_id']);
            $table->dropColumn('scoped_team_id');
        });
    }
};
