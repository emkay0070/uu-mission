<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity participants — links people to activities for attendance tracking.
 *
 * This table tracks which people are registered/participating in which activities.
 * This allows us to:
 * - Show a list of eligible people for attendance
 * - Track a person's participation history across activities
 * - Support people joining activities mid-campaign without fake attendance records
 *
 * A person can participate in multiple activities.
 * An activity can have multiple participants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('joined_date')->nullable()->comment('When the person joined this activity');
            $table->string('status', 20)->default('active')
                  ->comment('active | inactive | completed');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Unique: one person per activity
            $table->unique(['activity_id', 'person_id'], 'ap_unique_person_activity');

            $table->index('activity_id');
            $table->index('person_id');
            $table->index(['activity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_participants');
    }
};
