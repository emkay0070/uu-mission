<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outcome types — the configurable classification of spiritual results.
 *
 * Previously these codes were embedded as hardcoded strings in
 * MissionOutcomeController and the stats aggregation in CampaignController.
 * Storing them here means:
 *   - Controllers validate against the DB (no unknown codes accepted).
 *   - The stats endpoint queries active codes dynamically.
 *   - Administrators can add new outcome categories without a code change.
 *
 * sort_order determines how they appear in reports and the frontend.
 * include_in_stats = true means the outcome is counted in campaign dashboards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outcome_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('label', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('triggers_follow_up')->default(false)
                  ->comment('If true, a new outcome of this type should create a follow-up task');
            $table->boolean('include_in_stats')->default(true)
                  ->comment('Whether this type is counted in campaign dashboard totals');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Add FK from mission_outcomes.outcome_type to outcome_types.code
        // (on PostgreSQL only — avoids breaking SQLite tests)
        // We store codes as strings, so no int FK; instead we add a DB-level
        // CHECK that the value exists, implemented as an FK to the code column.
        // SQLite does not support adding constraints after table creation.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE mission_outcomes
                ADD CONSTRAINT fk_mo_outcome_type
                FOREIGN KEY (outcome_type) REFERENCES outcome_types(code)
                ON UPDATE CASCADE');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE mission_outcomes DROP CONSTRAINT IF EXISTS fk_mo_outcome_type');
        }
        Schema::dropIfExists('outcome_types');
    }
};
