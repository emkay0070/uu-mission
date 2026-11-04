<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review tracking and audit history for field reports.
 *
 * WHY
 * ---
 * Decision 3 requires that a delayed review must NOT block the reporting chain.
 * A team report whose Team Leader has not acted inside the review window has to
 * advance to the Site Secretary anyway — but the record must keep saying, in
 * plain terms, "Team Review Pending", and it must name the level responsible
 * for the delay. Silently marking it approved would be a lie ("Forwarding is
 * not approval"), and silently dropping the delay would lose the accountability
 * the hierarchy exists to provide.
 *
 * That needs two pieces of state that cannot be inferred from `status` alone:
 *
 *   has_pending_lower_review   the report has moved onward while a review at a
 *                              lower rung is still outstanding
 *   outstanding_review_level   WHICH rung is outstanding (team | site | campaign)
 *
 * Every transition is additionally written to `field_report_events`, an
 * append-only audit trail. It records who acted, from which state to which, at
 * which level, how late, and why — so the history of a report can be read back
 * without guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_reports', function (Blueprint $table) {
            $table->boolean('has_pending_lower_review')->default(false)
                  ->after('escalated_at')
                  ->comment('True when the report advanced while a lower-level review is still outstanding.');

            $table->string('outstanding_review_level', 20)->nullable()
                  ->after('has_pending_lower_review')
                  ->comment('The rung whose review is outstanding: team | site | campaign.');
        });

        Schema::create('field_report_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('field_report_id')
                  ->constrained('field_reports')
                  ->cascadeOnDelete();

            // submitted | approved | returned | forwarded | escalated |
            // consolidated | recovered
            $table->string('event_type', 40);

            // The routing state before and after the event, so an event is
            // readable on its own without replaying earlier rows.
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();

            // The rung the event concerns (team | site | campaign).
            $table->string('level', 20)->nullable();

            $table->foreignId('actor_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            // Free-form audit detail: delay duration, responsible level, which
            // child reports were attached, which were missing, and so on.
            $table->jsonb('meta')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['field_report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_report_events');

        Schema::table('field_reports', function (Blueprint $table) {
            $table->dropColumn(['has_pending_lower_review', 'outstanding_review_level']);
        });
    }
};
