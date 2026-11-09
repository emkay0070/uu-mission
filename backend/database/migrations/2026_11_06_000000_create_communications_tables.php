<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Communications — official mission announcements and instructions.
 *
 * Each communication targets exactly one scope (global, org_unit, campaign,
 * site or team). Scope integrity is enforced by a CHECK constraint mirroring
 * the user_role_assignments architecture (Correction 1).
 *
 * communications.reads tracks per-user read and acknowledgement state
 * lazily: one row per (communication, user), created on first interaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title');
            $table->longText('body');
            $table->string('communication_type')
                ->comment('announcement | organisational_update | instruction');
            $table->string('scope_type')
                ->comment('global | org_unit | campaign | site | team');

            $table->foreignId('scoped_org_unit_id')
                ->nullable()
                ->constrained('org_units')
                ->nullOnDelete();
            $table->foreignId('scoped_campaign_id')
                ->nullable()
                ->constrained('campaigns')
                ->nullOnDelete();
            $table->foreignId('scoped_site_id')
                ->nullable()
                ->constrained('mission_sites')
                ->nullOnDelete();
            $table->foreignId('scoped_team_id')
                ->nullable()
                ->constrained('teams')
                ->nullOnDelete();

            $table->boolean('requires_acknowledgement')->default(false);
            $table->string('status', 20)->default('draft')
                ->comment('draft | published | revoked');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['scope_type', 'status', 'published_at']);
            $table->index('author_user_id');
            $table->index('expires_at');
            $table->index('communication_type');
        });

        // CHECK: exactly one scope FK is set for a scoped communication;
        // none are set for a global communication.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE communications
                ADD CONSTRAINT chk_communications_scope_exclusive CHECK (
                    (scope_type = 'global'
                     AND scoped_org_unit_id IS NULL
                     AND scoped_campaign_id IS NULL
                     AND scoped_site_id IS NULL
                     AND scoped_team_id IS NULL)
                    OR
                    (scope_type = 'org_unit'
                     AND scoped_org_unit_id IS NOT NULL
                     AND scoped_campaign_id IS NULL
                     AND scoped_site_id IS NULL
                     AND scoped_team_id IS NULL)
                    OR
                    (scope_type = 'campaign'
                     AND scoped_org_unit_id IS NULL
                     AND scoped_campaign_id IS NOT NULL
                     AND scoped_site_id IS NULL
                     AND scoped_team_id IS NULL)
                    OR
                    (scope_type = 'site'
                     AND scoped_org_unit_id IS NULL
                     AND scoped_campaign_id IS NULL
                     AND scoped_site_id IS NOT NULL
                     AND scoped_team_id IS NULL)
                    OR
                    (scope_type = 'team'
                     AND scoped_org_unit_id IS NULL
                     AND scoped_campaign_id IS NULL
                     AND scoped_site_id IS NULL
                     AND scoped_team_id IS NOT NULL)
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE communications
                DROP CONSTRAINT IF EXISTS chk_communications_scope_exclusive
            ');
        }
        Schema::dropIfExists('communications');
    }
};
