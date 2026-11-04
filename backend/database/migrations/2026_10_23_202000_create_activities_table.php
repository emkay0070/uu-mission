<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activities — scheduled or recorded field events within a campaign site.
 *
 * An activity is site-specific. The composite FK (mission_site_id, campaign_id)
 * → mission_sites(id, campaign_id) enforces Correction 2: it is impossible
 * for an activity to reference Campaign A's site ID from Campaign B's context.
 *
 * scheduled_at vs recorded_at: an activity can be planned (scheduled_at set,
 * actual_at null) or recorded after the fact (both set). Status tracks the
 * lifecycle: scheduled → in_progress → completed | cancelled.
 *
 * led_by_person_id: who facilitated this particular activity.
 * estimated_attendance is a planning field; actual attendance is in attendance_entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns');
            $table->unsignedBigInteger('mission_site_id');
            $table->foreignId('activity_type_id')->constrained('activity_types');
            $table->string('title', 250);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('scheduled')
                  ->comment('scheduled | in_progress | completed | cancelled');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('actual_started_at')->nullable();
            $table->dateTime('actual_ended_at')->nullable();
            $table->string('location_note', 300)->nullable();
            $table->foreignId('led_by_person_id')
                  ->nullable()
                  ->constrained('people')
                  ->nullOnDelete();
            $table->unsignedSmallInteger('estimated_attendance')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Composite FK: enforces site belongs to this campaign
            $table->foreign(['mission_site_id', 'campaign_id'])
                  ->references(['id', 'campaign_id'])
                  ->on('mission_sites');

            $table->index('campaign_id');
            $table->index('mission_site_id');
            $table->index(['campaign_id', 'status']);
            $table->index(['mission_site_id', 'activity_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
