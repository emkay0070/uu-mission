<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * engagement_stage_histories.from_stage_id — restore nullable.
 *
 * WHY THIS MIGRATION EXISTS
 * -------------------------
 * A stage-history row records a TRANSITION: "the contact moved FROM stage X
 * TO stage Y". The very first row for a contact is different in kind — it is
 * the INITIAL REGISTRATION, the point at which the contact entered the
 * outreach journey. There is no previous stage, so its `from_stage_id` is
 * legitimately NULL.
 *
 * The original schema captured this correctly: the table was created with a
 * NULLABLE `from_stage` enum
 * (2026_11_01_100400_create_engagement_stage_history_table.php).
 *
 * The later conversion to foreign keys
 * (2026_11_01_120300_convert_engagement_stage_history_stages_to_foreign_keys.php)
 * silently regressed this. After back-filling any NULLs with 'new_contact', it
 * forced BOTH columns to NOT NULL — which contradicts two things:
 *   1. The domain fact above: the first entry has no "from" stage.
 *   2. Its OWN foreign-key rule. The FK was declared `->nullOnDelete()`, which
 *      exists precisely so the column may become NULL when a referenced stage
 *      is deleted. A NOT NULL column can never satisfy that rule.
 *
 * The result was that registering a brand-new outreach contact failed at the
 * database layer with a not-null violation, even though the controller was
 * correctly writing `from_stage_id => null` for the initial history row.
 *
 * `to_stage_id` is deliberately LEFT as NOT NULL: every history row — initial
 * or transition — always lands the contact in a stage, so it must never be
 * null.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Restore the original (and semantically correct) nullability.
        // Raw SQL is used so the existing foreign-key constraint on the column
        // is preserved untouched.
        DB::statement('ALTER TABLE engagement_stage_histories ALTER COLUMN from_stage_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Re-tighten the column. Any rows still holding a NULL "from" stage —
        // which are exactly the initial-registration rows — must be given a
        // concrete value first, otherwise the SET NOT NULL would fail. We
        // back-fill with the row's own target stage, mirroring the behaviour of
        // the migration being reversed.
        DB::statement('
            UPDATE engagement_stage_histories
            SET from_stage_id = to_stage_id
            WHERE from_stage_id IS NULL
        ');

        DB::statement('ALTER TABLE engagement_stage_histories ALTER COLUMN from_stage_id SET NOT NULL');
    }
};
