<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add functional_role to campaign_assignments.
 *
 * This allows a person to be assigned a specific ministry responsibility
 * (preacher, Bible worker, translator, site coordinator, etc.) at a site.
 *
 * This is distinct from team membership and team leadership roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table) {
            $table->foreignId('functional_role_type_id')->nullable()
                  ->after('assigned_team_id')
                  ->constrained('functional_role_types')
                  ->nullOnDelete()
                  ->comment('Functional ministry responsibility (preacher, Bible worker, translator, etc.)');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table) {
            $table->dropForeign(['functional_role_type_id']);
            $table->dropColumn('functional_role_type_id');
        });
    }
};
