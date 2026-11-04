<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance registers — a named session or meeting for which attendance is taken.
 *
 * SPLIT FROM ATTENDANCE ENTRIES (Correction 3):
 * This is one migration; attendance_entries is a separate subsequent migration.
 * The register is the "header" record: when, where, what meeting.
 * Entries are the individual rows: who attended.
 *
 * A register can be linked to a specific activity (e.g. the evangelistic meeting
 * on 2026-11-05 at Kato Site) OR be a standalone roll call (e.g. missionary
 * morning muster). activity_id is therefore nullable.
 *
 * The composite FK (mission_site_id, campaign_id) is used to enforce
 * site-campaign integrity even when the register has no linked activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns');
            $table->unsignedBigInteger('mission_site_id');
            $table->foreignId('activity_id')
                  ->nullable()
                  ->constrained('activities')
                  ->nullOnDelete();
            $table->string('register_name', 250);
            $table->date('register_date');
            $table->string('register_type', 60)->default('meeting')
                  ->comment('meeting | roll_call | service | training | community_event');
            $table->string('status', 20)->default('open')
                  ->comment('open | submitted | verified');
            $table->foreignId('submitted_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            // Composite FK: enforces site belongs to this campaign
            $table->foreign(['mission_site_id', 'campaign_id'])
                  ->references(['id', 'campaign_id'])
                  ->on('mission_sites');

            // Composite unique: one register per site per date per type
            $table->unique(['mission_site_id', 'register_date', 'register_type', 'register_name'],
                           'ar_unique_register');

            $table->index('campaign_id');
            $table->index('mission_site_id');
            $table->index(['register_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_registers');
    }
};
