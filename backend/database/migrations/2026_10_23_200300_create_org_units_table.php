<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org units — the actual organizational nodes in the hierarchy.
 *
 * Self-referencing parent_id supports arbitrary depth.
 * country_code distinguishes Ugandan units from international partners.
 * Jurisdiction (org unit) is kept separate from physical location
 * (mission_sites) — an org unit is an administrative authority, not a place.
 *
 * The CHECK constraint prevents self-loops at the DB level.
 * Circular ancestor detection is enforced at the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_unit_type_id')->constrained('org_unit_types');
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('org_units')
                  ->nullOnDelete();
            $table->string('name', 200);
            $table->string('short_code', 30)->nullable();
            $table->char('country_code', 2)->default('UG')
                  ->comment('ISO 3166-1 alpha-2');
            $table->boolean('is_international')->default(false);
            $table->foreignId('primary_contact_person_id')
                  ->nullable()
                  ->constrained('people')
                  ->nullOnDelete();
            $table->date('active_from')->nullable();
            $table->date('active_until')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('org_unit_type_id');
            $table->index('country_code');
        });

        // Prevent self-loops at DB level
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE org_units ADD CONSTRAINT chk_org_units_no_self_loop CHECK (parent_id IS NULL OR parent_id <> id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};
