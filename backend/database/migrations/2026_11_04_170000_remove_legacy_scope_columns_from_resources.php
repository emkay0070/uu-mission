<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop CHECK constraint for PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resources DROP CONSTRAINT IF EXISTS check_resource_scope');
        }

        // Drop indexes
        Schema::table('resources', function (Blueprint $table) {
            $table->dropIndex(['visibility_scope']);
            $table->dropIndex(['scoped_org_unit_id']);
            $table->dropIndex(['scoped_campaign_id']);
            $table->dropIndex(['scoped_site_id']);
            $table->dropIndex(['scoped_team_id']);
        });

        // Drop foreign keys and columns
        Schema::table('resources', function (Blueprint $table) {
            $table->dropForeign(['scoped_org_unit_id']);
            $table->dropForeign(['scoped_campaign_id']);
            $table->dropForeign(['scoped_site_id']);
            $table->dropForeign(['scoped_team_id']);
        });

        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('visibility_scope');
            $table->dropColumn('scoped_org_unit_id');
            $table->dropColumn('scoped_campaign_id');
            $table->dropColumn('scoped_site_id');
            $table->dropColumn('scoped_team_id');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->enum('visibility_scope', ['union', 'conference', 'campaign', 'site', 'team'])->default('union');
            $table->foreignId('scoped_org_unit_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->foreignId('scoped_campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $table->foreignId('scoped_site_id')->nullable()->constrained('mission_sites')->restrictOnDelete();
            $table->foreignId('scoped_team_id')->nullable()->constrained('teams')->restrictOnDelete();
        });

        Schema::table('resources', function (Blueprint $table) {
            $table->index('visibility_scope');
            $table->index('scoped_org_unit_id');
            $table->index('scoped_campaign_id');
            $table->index('scoped_site_id');
            $table->index('scoped_team_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE resources 
                ADD CONSTRAINT check_resource_scope 
                CHECK (
                    (visibility_scope = \'union\' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL) OR
                    (visibility_scope = \'conference\' AND scoped_org_unit_id IS NOT NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL) OR
                    (visibility_scope = \'campaign\' AND scoped_campaign_id IS NOT NULL AND scoped_org_unit_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL) OR
                    (visibility_scope = \'site\' AND scoped_site_id IS NOT NULL AND scoped_campaign_id IS NULL AND scoped_org_unit_id IS NULL AND scoped_team_id IS NULL) OR
                    (visibility_scope = \'team\' AND scoped_team_id IS NOT NULL AND scoped_site_id IS NULL AND scoped_campaign_id IS NULL AND scoped_org_unit_id IS NULL)
                )
            ');
        }
    }
};
