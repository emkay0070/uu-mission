<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field reports — connect a report to the fieldwork it describes, and give it a
 * hierarchical place in the reporting chain.
 *
 * WHAT WAS MISSING
 * ----------------
 * The original `field_reports` table knew only a campaign, a site and a period.
 * It could not say WHICH activities a report covers, WHICH attendance registers
 * it draws on, or WHICH report it consolidates. Its narrative fields stopped at
 * `challenges` and `prayer_requests`, so observations, lessons, testimonies and
 * recommendations had nowhere to live.
 *
 * WHAT IS ADDED
 * -------------
 *   report_level        team | site | campaign — the rung this report belongs to
 *   team_id             the team that owns a team-level report (NULL otherwise)
 *   activity_id         an activity the report is filed against (optional)
 *   attendance_register_id  the register the report draws on (optional)
 *   parent_report_id    the report this one consolidates (submitted lower-level
 *                       reports feed their parent — never the other way round)
 *   observations        lessons, notable experiences and what was observed
 *   testimonies         testimonies / notable experiences
 *   recommendations     next steps proposed by the author
 *   review_state        routing state of the review chain (see WorkflowDefinitions)
 *   review_due_at       when the current reviewer's window expires
 *   forwarded_at        when the report was escalated onwards (NOT approval)
 *   escalated_at        when a missed review deadline triggered forwarding
 *   preparation_meta    what the live preparation service gathered, what it could
 *                       not find, and what a human still has to write
 *
 * NOTHING IS DUPLICATED. The report references the authoritative attendance and
 * activity rows by id; it never copies their figures into itself except through
 * the pre-existing, human-entered `stat_*` columns (which are preserved as the
 * historical manual record — see PART 3 Phase 5/6 and the delivery report).
 *
 * CAMPAIGN-LEVEL REPORTS. A campaign consolidation covers the whole campaign and
 * therefore belongs to no single site, so `mission_site_id` becomes nullable.
 * The composite FK (mission_site_id, campaign_id) is unaffected: PostgreSQL's
 * default MATCH SIMPLE skips the check when mission_site_id is NULL, and still
 * enforces site-belongs-to-campaign whenever a site IS supplied.
 *
 * UNIQUENESS. The old "one report per site per period" constraint is replaced by
 * three PARTIAL unique indexes, one per rung, so that several teams may each file
 * their own report for the same site/period while a site or campaign still files
 * exactly one of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Relationship + content + review-routing columns ───────────────────
        Schema::table('field_reports', function (Blueprint $table) {
            $table->string('report_level', 20)->default('site')->after('mission_site_id')
                  ->comment('team | site | campaign');

            $table->foreignId('team_id')->nullable()->after('report_level')
                  ->constrained('teams')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->after('team_id')
                  ->constrained('activities')->nullOnDelete();
            $table->foreignId('attendance_register_id')->nullable()->after('activity_id')
                  ->constrained('attendance_registers')->nullOnDelete();
            $table->foreignId('parent_report_id')->nullable()->after('attendance_register_id')
                  ->constrained('field_reports')->nullOnDelete();

            $table->text('observations')->nullable()->after('challenges');
            $table->text('testimonies')->nullable()->after('observations');
            $table->text('recommendations')->nullable()->after('prayer_requests');

            // Review routing — deliberately SEPARATE from `status`. `status`
            // remains the 4-state lifecycle (draft/submitted/reviewed/approved);
            // `review_state` records where in the reporting chain the report is
            // waiting, so we do not multiply workflow statuses.
            $table->string('review_state', 30)->nullable()->after('status')
                  ->comment('pending_team_review | pending_site_review | pending_campaign_review | changes_requested | complete');
            $table->timestamp('review_due_at')->nullable()->after('reviewed_at');
            $table->timestamp('forwarded_at')->nullable()->after('review_due_at');
            $table->timestamp('escalated_at')->nullable()->after('forwarded_at');

            // What the live preparation service compiled, and what is missing.
            $table->jsonb('preparation_meta')->nullable()->after('recommendations');
        });

        // A campaign-level report belongs to no single site.
        DB::statement('ALTER TABLE field_reports ALTER COLUMN mission_site_id DROP NOT NULL');

        // One report per site per period is no longer correct once teams file
        // their own reports for the same period.
        Schema::table('field_reports', function (Blueprint $table) {
            $table->dropUnique('fr_unique_period_report');
        });

        // ── Partial unique indexes, one per rung ──────────────────────────────
        DB::statement("
            CREATE UNIQUE INDEX fr_unique_team_report
            ON field_reports (campaign_id, mission_site_id, team_id, period_start, period_end, report_period_type)
            WHERE report_level = 'team'
        ");

        DB::statement("
            CREATE UNIQUE INDEX fr_unique_site_report
            ON field_reports (campaign_id, mission_site_id, period_start, period_end, report_period_type)
            WHERE report_level = 'site'
        ");

        DB::statement("
            CREATE UNIQUE INDEX fr_unique_campaign_report
            ON field_reports (campaign_id, period_start, period_end, report_period_type)
            WHERE report_level = 'campaign'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fr_unique_team_report');
        DB::statement('DROP INDEX IF EXISTS fr_unique_site_report');
        DB::statement('DROP INDEX IF EXISTS fr_unique_campaign_report');

        Schema::table('field_reports', function (Blueprint $table) {
            $table->unique(['mission_site_id', 'period_start', 'period_end', 'report_period_type'],
                           'fr_unique_period_report');

            $table->dropConstrainedForeignId('parent_report_id');
            $table->dropConstrainedForeignId('attendance_register_id');
            $table->dropConstrainedForeignId('activity_id');
            $table->dropConstrainedForeignId('team_id');

            $table->dropColumn([
                'report_level', 'observations', 'testimonies', 'recommendations',
                'review_state', 'review_due_at', 'forwarded_at', 'escalated_at',
                'preparation_meta',
            ]);
        });
    }
};
