<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->foreignId('origin_assignment_id')->nullable()->after('created_by_user_id')
                ->constrained('user_role_assignments')->nullOnDelete();
        });

        Schema::create('resource_distribution_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience_type', 32);
            $table->string('audience_key', 120);
            $table->foreignId('target_org_unit_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->foreignId('target_campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $table->foreignId('target_site_id')->nullable()->constrained('mission_sites')->restrictOnDelete();
            $table->foreignId('target_team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->restrictOnDelete();
            $table->foreignId('supersedes_request_id')->nullable()->constrained('resource_distribution_requests')->nullOnDelete();
            $table->string('status', 24)->default('pending_review');
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'audience_key', 'status'], 'resource_distribution_request_audience_idx');
            $table->index(['status', 'audience_type'], 'resource_distribution_request_review_idx');
            $table->index(['requested_by_user_id', 'status'], 'resource_distribution_request_owner_idx');
        });

        Schema::create('resource_distribution_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_distribution_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['resource_distribution_request_id', 'created_at'], 'resource_distribution_decision_history_idx');
        });

        Schema::create('resource_distribution_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_distribution_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->restrictOnDelete();
            $table->string('audience_type', 32);
            $table->string('audience_key', 120);
            $table->foreignId('released_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'audience_key', 'released_at'], 'resource_distribution_release_audience_idx');
            $table->index(['resource_id', 'resource_version_id'], 'resource_distribution_release_version_idx');
        });

        // Add unique constraint separately to avoid Laravel's auto-naming conflict
        DB::statement('ALTER TABLE resource_distribution_releases ADD CONSTRAINT resource_distribution_releases_request_unique UNIQUE (resource_distribution_request_id)');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE resource_distribution_requests ADD CONSTRAINT resource_distribution_request_status_check CHECK (status IN ('pending_review', 'returned', 'rejected', 'approved', 'revoked', 'superseded'))");
            DB::statement("ALTER TABLE resource_distribution_decisions ADD CONSTRAINT resource_distribution_decision_action_check CHECK (action IN ('submitted', 'resubmitted', 'approved', 'rejected', 'returned', 'revoked'))");
            DB::statement("ALTER TABLE resource_distribution_requests ADD CONSTRAINT resource_distribution_request_target_check CHECK (
                (audience_type IN ('union', 'conference', 'mission_field', 'zone', 'church_district', 'local_church') AND target_org_unit_id IS NOT NULL AND target_campaign_id IS NULL AND target_site_id IS NULL AND target_team_id IS NULL AND recipient_user_id IS NULL) OR
                (audience_type = 'campaign' AND target_org_unit_id IS NULL AND target_campaign_id IS NOT NULL AND target_site_id IS NULL AND target_team_id IS NULL AND recipient_user_id IS NULL) OR
                (audience_type = 'site' AND target_org_unit_id IS NULL AND target_campaign_id IS NULL AND target_site_id IS NOT NULL AND target_team_id IS NULL AND recipient_user_id IS NULL) OR
                (audience_type = 'team' AND target_org_unit_id IS NULL AND target_campaign_id IS NULL AND target_site_id IS NULL AND target_team_id IS NOT NULL AND recipient_user_id IS NULL) OR
                (audience_type = 'private' AND target_org_unit_id IS NULL AND target_campaign_id IS NULL AND target_site_id IS NULL AND target_team_id IS NULL AND recipient_user_id IS NOT NULL)
            )");
            DB::statement('ALTER TABLE resource_distribution_releases ADD CONSTRAINT resource_distribution_release_revocation_check CHECK ((revoked_at IS NULL AND revoked_by_user_id IS NULL AND revocation_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by_user_id IS NOT NULL AND revocation_reason IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resource_distribution_releases DROP CONSTRAINT IF EXISTS resource_distribution_releases_request_unique');
        }
        Schema::dropIfExists('resource_distribution_releases');
        Schema::dropIfExists('resource_distribution_decisions');
        Schema::dropIfExists('resource_distribution_requests');
        Schema::table('resources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_assignment_id');
        });
    }
};
