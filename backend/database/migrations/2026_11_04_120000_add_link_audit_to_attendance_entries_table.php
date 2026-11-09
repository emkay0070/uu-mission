<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add audit fields for the "later linking" workflow.
 *
 * When an anonymous or name-only attendance entry is later linked to a Person
 * record, we record who performed the linking and when. This follows the same
 * audit pattern as other models (e.g., OutreachEncounter.recorded_by_user_id,
 * MissionOutcome.recorded_by_user_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_entries', function (Blueprint $table) {
            $table->foreignId('linked_by_user_id')
                ->nullable()
                ->after('notes')
                ->constrained('users')
                ->nullOnDelete()
                ->comment('User who linked this entry to a Person record');
            $table->timestamp('linked_at')
                ->nullable()
                ->after('linked_by_user_id')
                ->comment('When this entry was linked to a Person record');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_entries', function (Blueprint $table) {
            $table->dropForeign(['linked_by_user_id']);
            $table->dropColumn(['linked_by_user_id', 'linked_at']);
        });
    }
};
