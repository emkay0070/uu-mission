<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org unit types — the configurable taxonomy for the organizational hierarchy.
 *
 * Seeded with: union, conference, mission_field, district, zone,
 * local_church, club, international_partner.
 *
 * Authorized leaders can add new types without code changes.
 * sort_order controls display hierarchy (lower = higher in tree).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_unit_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique()
                  ->comment('e.g. union | conference | mission_field | district | zone | local_church | club');
            $table->string('label', 120);
            $table->smallInteger('sort_order')->default(0)
                  ->comment('Lower = higher in hierarchy');
            $table->boolean('can_have_children')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_unit_types');
    }
};
