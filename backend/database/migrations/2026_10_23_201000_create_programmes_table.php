<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programmes — the ongoing initiative or standing mandate.
 *
 * A programme is the container for multiple campaigns over time.
 * Example: "Global Mission / Unreached Areas" is a programme owned by UU.
 * "Lira-Kato Global Mission 2026" is a campaign under that programme.
 *
 * Programmes are owned by an organizational unit (usually Union or Conference).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_type_id')->constrained('programme_types');
            $table->foreignId('owning_org_unit_id')->constrained('org_units');
            $table->string('name', 250);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')
                  ->comment('active | inactive | archived');
            $table->foreignId('created_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            $table->index(['programme_type_id', 'status']);
            $table->index('owning_org_unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programmes');
    }
};
