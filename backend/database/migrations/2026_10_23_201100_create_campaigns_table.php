<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns — a specific, bounded operational occurrence under a programme.
 *
 * A campaign has a lifecycle: planning → active → completed → archived.
 * Completed campaigns are never deleted — they are permanent historical records.
 *
 * COMPOSITE UNIQUE KEY on (id, programme_id):
 * This is required so that mission_sites can use a composite FK
 * referencing (campaign_id, programme_id) to enforce that a site
 * belongs to the same programme as its parent campaign (Correction 2).
 *
 * Soft deletes are intentionally NOT used here — a campaign record must
 * always exist to preserve the integrity of all linked records.
 * Status transitions to 'archived' instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained('programmes');
            $table->foreignId('lead_org_unit_id')
                  ->constrained('org_units')
                  ->comment('The organizational unit administering this campaign');
            $table->string('name', 250);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('planning')
                  ->comment('planning | active | completed | archived');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('created_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            // Composite unique: enables composite FK from mission_sites
            // so site → campaign → programme integrity is enforced at DB level
            $table->unique(['id', 'programme_id'], 'campaigns_id_programme_unique');

            $table->index(['programme_id', 'status']);
            $table->index(['status', 'start_date', 'end_date']);
            $table->index('lead_org_unit_id');
        });

        Schema::create('campaign_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('title', 250);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending')
                  ->comment('pending | in_progress | achieved | dropped');
            $table->smallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_objectives');
        Schema::dropIfExists('campaigns');
    }
};
