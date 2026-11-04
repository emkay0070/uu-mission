<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add follow-up completion fields to OutreachEncounter.
 *
 * Changes:
 * 1. Add completed_at timestamp (when follow-up was marked as completed)
 * 2. Add completed_by_user_id (who marked the follow-up as completed)
 * 3. Add completion_notes (notes about the completion)
 *
 * These fields allow follow-ups to be completed while preserving the original
 * encounter record in the person's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_encounters', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('follow_up_date')
                  ->comment('When the follow-up was marked as completed');
            $table->foreignId('completed_by_user_id')->nullable()->after('completed_at')
                  ->constrained('users')
                  ->nullOnDelete()
                  ->comment('User who marked the follow-up as completed');
            $table->text('completion_notes')->nullable()->after('completed_by_user_id')
                  ->comment('Notes about the follow-up completion');
        });
    }

    public function down(): void
    {
        Schema::table('outreach_encounters', function (Blueprint $table) {
            $table->dropForeign(['completed_by_user_id']);
            $table->dropColumn(['completed_at', 'completed_by_user_id', 'completion_notes']);
        });
    }
};
