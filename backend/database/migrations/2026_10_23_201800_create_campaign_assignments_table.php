<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign assignments — a missionary's formal deployment to a campaign and site.
 *
 * This is the bridge between a missionary profile and a specific campaign.
 * One person can have one assignment per campaign; a reassignment to a different
 * site is recorded by updating this record (not by creating a duplicate).
 *
 * Composite FK (mission_site_id, campaign_id) → mission_sites(id, campaign_id)
 * guarantees the assigned site belongs to the stated campaign (Correction 2).
 *
 * assigned_team_id is optional — a missionary can be assigned to a campaign site
 * without yet being placed on a specific team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns');
            $table->foreignId('person_id')->constrained('people');

            // Nullable: assignment can be campaign-level before site placement
            $table->unsignedBigInteger('mission_site_id')->nullable();

            $table->foreignId('assigned_team_id')
                  ->nullable()
                  ->constrained('teams')
                  ->nullOnDelete();

            $table->string('status', 30)->default('confirmed')
                  ->comment('confirmed | arrived | completed | withdrew | no_show');
            $table->date('expected_arrival')->nullable();
            $table->date('actual_arrival')->nullable();
            $table->date('departure')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('assigned_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            // Composite FK: site must belong to this campaign
            $table->foreign(['mission_site_id', 'campaign_id'])
                  ->references(['id', 'campaign_id'])
                  ->on('mission_sites')
                  ->nullOnDelete();

            // A person can only have one assignment per campaign
            $table->unique(['campaign_id', 'person_id'], 'ca_unique_person_campaign');

            $table->index('campaign_id');
            $table->index('mission_site_id');
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_assignments');
    }
};
