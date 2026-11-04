<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Functional role types — configurable taxonomy for mission responsibilities.
 *
 * These are functional ministry responsibilities (preacher, Bible worker, translator,
 * site coordinator, etc.) that a person can hold at a site or within a campaign.
 *
 * This is distinct from team leadership roles (leader/deputy/member) which are
 * structural organizational positions within a team.
 *
 * A person can have multiple functional responsibilities across different sites
 * or even at the same site (e.g., both preach and conduct Bible studies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('functional_role_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique()
                  ->comment('e.g. site_coordinator | preacher | bible_worker | translator | prayer_team | music_team | health_team');
            $table->string('label', 150);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('functional_role_types');
    }
};
