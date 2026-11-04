<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outreach contacts — outreach-specific journey tracking linked to Person.
 *
 * This table separates outreach engagement stages and journey information
 * from the permanent Person identity. A person may have an OutreachContact,
 * a MissionaryProfile, both, or neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')
                  ->unique()
                  ->constrained('people')
                  ->cascadeOnDelete();
            $table->foreignId('engagement_stage_id')
                  ->constrained('engagement_stages')
                  ->restrictOnDelete()
                  ->comment('Current engagement stage in outreach journey');
            $table->string('village', 200)->nullable()
                  ->comment('Area of residence for follow-up');
            $table->date('date_registered')->nullable()
                  ->comment('When the person was first encountered in outreach');
            $table->text('notes')->nullable()
                  ->comment('Outreach-specific follow-up information and context');
            $table->foreignId('campaign_id')->nullable()
                  ->constrained('campaigns')
                  ->nullOnDelete()
                  ->comment('Campaign where first encountered');
            $table->foreignId('first_encountered_campaign_id')->nullable()
                  ->constrained('campaigns')
                  ->nullOnDelete()
                  ->comment('Campaign of first encounter (historical)');
            $table->foreignId('first_encountered_site_id')->nullable()
                  ->constrained('mission_sites')
                  ->nullOnDelete()
                  ->comment('Site of first encounter (historical)');
            $table->boolean('is_active')->default(true)
                  ->comment('Whether this outreach contact is actively being followed');
            $table->timestamps();

            $table->index('engagement_stage_id');
            $table->index('campaign_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_contacts');
    }
};
