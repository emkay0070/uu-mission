<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field reports — structured narrative and statistical reports from sites.
 *
 * A field report is submitted by a site secretary or team leader for a
 * given period (usually daily or weekly). It captures narrative content
 * alongside key statistics for that reporting window.
 *
 * report_period_type controls what the period represents:
 *   - 'daily'   → period_start = period_end = the day
 *   - 'weekly'  → a 7-day window
 *   - 'summary' → a free-form end-of-campaign summary
 *
 * The composite FK (mission_site_id, campaign_id) prevents a report from
 * being filed against an inconsistent site/campaign combination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns');
            $table->unsignedBigInteger('mission_site_id');
            $table->string('report_period_type', 20)->default('daily')
                  ->comment('daily | weekly | summary');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft')
                  ->comment('draft | submitted | reviewed | approved');
            $table->text('narrative')->nullable()
                  ->comment('Main narrative body of the report');
            // Key stats — denormalized for quick dashboard reads
            $table->unsignedInteger('stat_meetings_held')->default(0);
            $table->unsignedInteger('stat_total_attendance')->default(0);
            $table->unsignedInteger('stat_decisions')->default(0);
            $table->unsignedInteger('stat_baptisms')->default(0);
            $table->unsignedInteger('stat_bible_studies_requested')->default(0);
            $table->text('challenges')->nullable();
            $table->text('prayer_requests')->nullable();
            $table->foreignId('submitted_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // Composite FK: site must belong to this campaign
            $table->foreign(['mission_site_id', 'campaign_id'])
                  ->references(['id', 'campaign_id'])
                  ->on('mission_sites');

            // One report per site per period
            $table->unique(['mission_site_id', 'period_start', 'period_end', 'report_period_type'],
                           'fr_unique_period_report');

            $table->index('campaign_id');
            $table->index('mission_site_id');
            $table->index(['campaign_id', 'status']);
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_reports');
    }
};
