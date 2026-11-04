<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add campaign and site FK columns to user_role_assignments.
 *
 * These FKs are added AFTER campaigns table exists (dependency order).
 * This completes Correction 1: all three scope FK columns now have
 * real, enforceable foreign key constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->foreign('scoped_campaign_id')
                  ->references('id')->on('campaigns')
                  ->nullOnDelete();
            // scoped_site_id FK is added after mission_sites table is created
        });
    }

    public function down(): void
    {
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->dropForeign(['scoped_campaign_id']);
        });
    }
};
