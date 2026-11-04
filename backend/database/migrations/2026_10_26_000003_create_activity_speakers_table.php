<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity speakers — multiple speakers can be assigned to a single activity.
 *
 * This supports preaching schedules where multiple preachers may be assigned
 * to different meetings within a campaign series.
 *
 * The activity already has a single led_by_person_id field for the primary
 * leader. This pivot table allows additional speakers to be associated with
 * an activity for cases like:
 *   - A meeting with multiple speakers
 *   - A preaching series where different preachers speak on different nights
 *   - Co-preaching scenarios
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_speakers', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role')->default('speaker')
                  ->comment('speaker | co_speaker | translator | moderator');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['activity_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_speakers');
    }
};
