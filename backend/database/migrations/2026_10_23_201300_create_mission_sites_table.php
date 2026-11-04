<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission sites — the operational locations within a campaign (Correction 2).
 *
 * PROBLEM SOLVED: Teams, activities, reports and assignments that reference
 * both a campaign and a site could reference mismatched combinations if only
 * simple individual FKs were used (e.g. team.campaign_id = 1, team.site_id = 5
 * where site 5 actually belongs to campaign 2).
 *
 * SOLUTION: mission_sites has a COMPOSITE UNIQUE KEY on (id, campaign_id).
 * Child tables that reference both (teams, departments, committees, activities,
 * assignments) use COMPOSITE FKs: FOREIGN KEY (site_id, campaign_id)
 * REFERENCES mission_sites(id, campaign_id).
 *
 * This means the DB itself guarantees a team's site and campaign are consistent.
 * It is physically impossible to insert a team for Campaign A at a site
 * belonging to Campaign B.
 *
 * campaign_target_areas: links campaigns to their mission area intelligence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns');
            $table->foreignId('mission_area_id')
                  ->nullable()
                  ->constrained('mission_areas')
                  ->nullOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->text('location_description')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('status', 20)->default('planned')
                  ->comment('planned | active | closed');
            $table->date('operational_from')->nullable();
            $table->date('operational_until')->nullable();
            $table->timestamps();

            // CRITICAL: composite unique enables composite FKs from child tables
            $table->unique(['id', 'campaign_id'], 'mission_sites_id_campaign_unique');
            $table->index('campaign_id');
            $table->index('status');
        });

        // Now add the site FK to user_role_assignments (completes Correction 1)
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->foreign('scoped_site_id')
                  ->references('id')->on('mission_sites')
                  ->nullOnDelete();
        });

        // Campaign → mission area pivot
        Schema::create('campaign_target_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('mission_area_id')->constrained('mission_areas')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['campaign_id', 'mission_area_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->dropForeign(['scoped_site_id']);
        });
        Schema::dropIfExists('campaign_target_areas');
        Schema::dropIfExists('mission_sites');
    }
};
