<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field report versions — an immutable snapshot of a report at each formal
 * submission.
 *
 * WHY THIS EXISTS
 * ---------------
 * Once a report is formally submitted it becomes part of the mission record.
 * Attendance, activities and outcomes may legitimately change afterwards (a late
 * register, a corrected figure), but the change must never silently rewrite what
 * was submitted and reviewed. Instead:
 *
 *   1. On every formal submit, the current report state is snapshotted here.
 *   2. Later changes to source records are surfaced as "unreconciled" against
 *      the preserved version, for an authorised user to reconcile deliberately.
 *   3. The preserved version and its audit trail are never mutated.
 *
 * `snapshot` holds the serialised report exactly as it stood (including the
 * preparation metadata and the human-entered sections), so a version can be read
 * back without depending on any other table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_report_id')->constrained('field_reports')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20);
            $table->string('review_state', 30)->nullable();
            $table->jsonb('snapshot');
            $table->foreignId('created_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // One row per version of a given report.
            $table->unique(['field_report_id', 'version_number'], 'frv_unique_version');
            $table->index('field_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_report_versions');
    }
};
