<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments, committees and their members.
 *
 * Both use the same composite FK pattern as teams (Correction 2):
 * site_id + campaign_id → mission_sites(id, campaign_id).
 *
 * A department or committee can be:
 * - Campaign-wide (mission_site_id = NULL)
 * - Site-specific (mission_site_id = set, campaign enforced via composite FK)
 *
 * Note: a person can simultaneously be in a department AND a committee
 * AND a team — these are independent structures, not mutually exclusive.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Departments ────────────────────────────────────────────────────────
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns');
            $table->unsignedBigInteger('mission_site_id')->nullable();
            $table->string('name', 200);
            $table->foreignId('leader_person_id')
                  ->nullable()
                  ->constrained('people')
                  ->nullOnDelete();
            $table->timestamps();

            $table->foreign(['mission_site_id', 'campaign_id'])
                  ->references(['id', 'campaign_id'])
                  ->on('mission_sites')
                  ->nullOnDelete();

            $table->index('campaign_id');
        });

        Schema::create('dept_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people');
            $table->string('role_in_dept', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['department_id', 'person_id']);
        });

        // ── Committees ─────────────────────────────────────────────────────────
        Schema::create('committees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns');
            $table->unsignedBigInteger('mission_site_id')->nullable();
            $table->string('name', 200);
            $table->text('purpose')->nullable();
            $table->timestamps();

            $table->foreign(['mission_site_id', 'campaign_id'])
                  ->references(['id', 'campaign_id'])
                  ->on('mission_sites')
                  ->nullOnDelete();

            $table->index('campaign_id');
        });

        Schema::create('committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained('committees')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people');
            $table->string('office_label', 150)->nullable()
                  ->comment('e.g. Chairperson | Secretary | Treasurer');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['committee_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_members');
        Schema::dropIfExists('committees');
        Schema::dropIfExists('dept_members');
        Schema::dropIfExists('departments');
    }
};
