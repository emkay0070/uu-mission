<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('External identifier for API references');

            // Core metadata
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('resource_categories')->nullOnDelete();
            $table->string('language', 10)->default('en')->comment('ISO 639-1 language code');
            $table->string('intended_audience')->nullable();

            // Publication metadata
            $table->string('publisher')->nullable();
            $table->date('publication_date')->nullable();
            $table->string('version_number')->default('1.0')->comment('Human-readable version identifier');

            // Status and workflow
            $table->enum('status', ['draft', 'pending_review', 'published', 'archived'])->default('draft');
            $table->enum('visibility_scope', ['union', 'conference', 'campaign', 'site', 'team'])->default('union');

            // Scope constraints (exactly one non-null based on visibility_scope)
            // Restrictive deletion: cannot delete org_unit/campaign/site/team if resources reference them
            $table->foreignId('scoped_org_unit_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->foreignId('scoped_campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $table->foreignId('scoped_site_id')->nullable()->constrained('mission_sites')->restrictOnDelete();
            $table->foreignId('scoped_team_id')->nullable()->constrained('teams')->restrictOnDelete();

            // Current version reference (authoritative file lives in resource_versions)
            // FK added in separate migration to avoid circular dependency
            $table->foreignId('current_version_id')->nullable();

            // Thumbnail (optional, manual upload for V1)
            $table->string('thumbnail_storage_disk')->nullable();
            $table->string('thumbnail_storage_path')->nullable();

            // Audit
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('uuid');
            $table->index('status');
            $table->index('visibility_scope');
            $table->index('category_id');
            $table->index('scoped_org_unit_id');
            $table->index('scoped_campaign_id');
            $table->index('scoped_site_id');
            $table->index('scoped_team_id');
            $table->index('current_version_id');
            $table->index('published_at');
        });

        // CHECK constraint for scope validation (PostgreSQL)
        // Ensures exactly one scope column is non-null based on visibility_scope
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE resources 
                ADD CONSTRAINT check_resource_scope 
                CHECK (
                    (visibility_scope = \'union\' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL) OR
                    (visibility_scope = \'conference\' AND scoped_org_unit_id IS NOT NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL) OR
                    (visibility_scope = \'campaign\' AND scoped_campaign_id IS NOT NULL AND scoped_org_unit_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL) OR
                    (visibility_scope = \'site\' AND scoped_site_id IS NOT NULL AND scoped_campaign_id IS NULL AND scoped_org_unit_id IS NULL AND scoped_team_id IS NULL) OR
                    (visibility_scope = \'team\' AND scoped_team_id IS NOT NULL AND scoped_site_id IS NULL AND scoped_campaign_id IS NULL AND scoped_org_unit_id IS NULL)
                )
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resources DROP CONSTRAINT check_resource_scope');
        }
        Schema::dropIfExists('resources');
    }
};
