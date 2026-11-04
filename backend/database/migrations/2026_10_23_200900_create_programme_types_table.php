<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programme types — the configurable catalogue of mission programme categories.
 *
 * This is the database-driven campaign type taxonomy (per spec).
 * No code change is needed to add new programme types.
 *
 * Examples: global_mission, youth_for_christ, children_for_christ,
 *           womens_thrust, mens_thrust
 *
 * default_requirements is a free-text or JSON field for documentation of
 * programme-specific expectations — not enforced by the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique()
                  ->comment('e.g. global_mission | youth_for_christ | children_for_christ | womens_thrust | mens_thrust');
            $table->string('label', 200);
            $table->string('ministry_owner', 120)->nullable()
                  ->comment('e.g. Youth | Womens Ministries | Childrens Ministries');
            $table->text('default_requirements')->nullable()
                  ->comment('Programme-specific notes — not schema-enforced');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programme_types');
    }
};
