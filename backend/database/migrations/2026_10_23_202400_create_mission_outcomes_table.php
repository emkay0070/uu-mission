<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission outcomes — the spiritual results of field activities.
 *
 * Each outcome record is a distinct, individually traceable spiritual event.
 * This design:
 *   - Allows deduplication (same person baptised in same campaign = one record)
 *   - Enables follow-up tracking per individual
 *   - Aggregates cleanly to reports without data loss
 *
 * outcome_type is the core discriminator:
 *   'decision'          → accepted Christ at a meeting
 *   'baptism'           → baptised during the campaign
 *   'bible_study_req'   → requested Bible study follow-up
 *   'church_joined'     → formally joined a church
 *   'follow_up_needed'  → flagged for follow-up (no other outcome yet)
 *
 * person_id is nullable to capture walk-in community outcomes where no
 * full people record has been created. visitor_name captures their identity.
 *
 * The activity_id and attendance_register_id are optional source traceability
 * links — they tell us which event produced this outcome.
 *
 * follow_up_status drives the follow-up workflow:
 *   pending → assigned → completed | lost_contact
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns');
            $table->unsignedBigInteger('mission_site_id');
            $table->string('outcome_type', 40)
                  ->comment('decision | baptism | bible_study_req | church_joined | follow_up_needed');
            $table->date('outcome_date');
            // Known person (missionary or profiled community member)
            $table->foreignId('person_id')
                  ->nullable()
                  ->constrained('people')
                  ->nullOnDelete();
            // Walk-in community member (no full people record yet)
            $table->string('visitor_name', 200)->nullable();
            $table->string('visitor_phone', 30)->nullable();
            $table->string('visitor_gender', 20)->nullable();
            $table->unsignedSmallInteger('visitor_age')->nullable();
            // Source traceability
            $table->foreignId('activity_id')
                  ->nullable()
                  ->constrained('activities')
                  ->nullOnDelete();
            $table->foreignId('attendance_register_id')
                  ->nullable()
                  ->constrained('attendance_registers')
                  ->nullOnDelete();
            // Follow-up workflow
            $table->string('follow_up_status', 30)->default('pending')
                  ->comment('pending | assigned | completed | lost_contact | not_required');
            $table->foreignId('follow_up_assigned_to_person_id')
                  ->nullable()
                  ->constrained('people')
                  ->nullOnDelete();
            $table->date('follow_up_due_date')->nullable();
            $table->date('follow_up_completed_date')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            // Composite FK: site must belong to this campaign
            $table->foreign(['mission_site_id', 'campaign_id'])
                  ->references(['id', 'campaign_id'])
                  ->on('mission_sites');

            $table->index('campaign_id');
            $table->index('mission_site_id');
            $table->index(['campaign_id', 'outcome_type']);
            $table->index(['follow_up_status', 'follow_up_due_date']);
            $table->index('person_id');
        });

        // At least one person identifier must be present
        DB::statement("
            ALTER TABLE mission_outcomes
            ADD CONSTRAINT chk_mo_identifier CHECK (
                person_id IS NOT NULL OR visitor_name IS NOT NULL
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_outcomes');
    }
};
