<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams and team members — flexible operational groups within a campaign or site.
 *
 * FLEXIBLE TEAM MODEL (Correction 4):
 * - mission_site_id = NULL → campaign-wide team (e.g. UU Leadership Team)
 * - mission_site_id = SET  → site-specific team (e.g. Kato Outreach Team)
 * - origin_org_unit_id     → optional: which org does this team come from?
 * - team_type_id           → optional: what functional role does this team serve?
 *
 * SITE-CAMPAIGN INTEGRITY (Correction 2):
 * When mission_site_id is set, the composite FK
 * (mission_site_id, campaign_id) → mission_sites(id, campaign_id)
 * ensures the site and campaign are always consistent.
 * It is impossible to create a team for Campaign A at a site in Campaign B.
 *
 * team_members supports multi-role: a person can be leader in one team
 * and a member in another, within the same campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns');
            $table->unsignedBigInteger('mission_site_id')->nullable()
                  ->comment('NULL = campaign-wide team');
            $table->foreignId('team_type_id')
                  ->nullable()
                  ->constrained('team_types')
                  ->nullOnDelete();
            $table->foreignId('origin_org_unit_id')
                  ->nullable()
                  ->constrained('org_units')
                  ->nullOnDelete()
                  ->comment('Originating organization (e.g. CUC, UU, local church)');
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->timestamps();

            // Composite FK: enforces site belongs to the same campaign
            $table->foreign(['mission_site_id', 'campaign_id'])
                  ->references(['id', 'campaign_id'])
                  ->on('mission_sites')
                  ->nullOnDelete();

            $table->index('campaign_id');
            $table->index('mission_site_id');
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people');
            $table->string('role_in_team', 50)->default('member')
                  ->comment('leader | deputy | member');
            $table->date('joined_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'person_id']);
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
