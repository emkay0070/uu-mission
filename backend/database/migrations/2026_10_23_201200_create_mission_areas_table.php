<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission areas — geographic intelligence targets, not campaign sites.
 *
 * A mission area is a persistent geographic/community entity that can be
 * targeted by multiple campaigns over time. It accumulates intelligence
 * between campaigns. A campaign_target_areas pivot links campaigns to areas.
 *
 * mission_area_intel stores individual intelligence observations with:
 * - Verification status (verified fact vs. unverified observation)
 * - Source traceability (who observed it, when)
 * - superseded_by_id allows traceable updates without destroying history
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->char('country_code', 2)->default('UG');
            $table->string('region_name', 200)->nullable();
            $table->text('description')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->text('boundary_geojson')->nullable()
                  ->comment('GeoJSON polygon for map display');
            $table->timestamps();

            $table->index('country_code');
        });

        Schema::create('mission_area_intel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_area_id')
                  ->constrained('mission_areas')
                  ->cascadeOnDelete();
            $table->string('intel_category', 60)
                  ->comment('church_presence | language | access | community_need | accommodation | security | history');
            $table->text('content');
            $table->boolean('is_verified')->default(false);
            $table->string('source_description', 300)->nullable();
            $table->foreignId('contributed_by_person_id')
                  ->nullable()
                  ->constrained('people')
                  ->nullOnDelete();
            $table->date('observed_on')->nullable();
            $table->foreignId('superseded_by_id')
                  ->nullable()
                  ->constrained('mission_area_intel')
                  ->nullOnDelete()
                  ->comment('Points to the newer record that replaces this one');
            $table->timestamps();

            $table->index(['mission_area_id', 'intel_category']);
            $table->index(['mission_area_id', 'is_verified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_area_intel');
        Schema::dropIfExists('mission_areas');
    }
};
