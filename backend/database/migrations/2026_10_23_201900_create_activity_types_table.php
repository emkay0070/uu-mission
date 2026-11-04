<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity types — the configurable taxonomy for field activities.
 *
 * Examples: evangelistic_meeting, bible_study, prayer_walk, health_camp,
 *           children_programme, community_service, media_outreach, follow_up_visit.
 *
 * expected_duration_minutes: a planning hint, not enforced.
 * requires_attendance_register: drives whether an attendance register is
 * automatically created when an activity of this type is scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique()
                  ->comment('e.g. evangelistic_meeting | bible_study | prayer_walk | health_camp | follow_up_visit');
            $table->string('label', 200);
            $table->string('category', 60)->nullable()
                  ->comment('outreach | training | welfare | administration | worship');
            $table->boolean('requires_attendance_register')->default(true);
            $table->smallInteger('expected_duration_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_types');
    }
};
