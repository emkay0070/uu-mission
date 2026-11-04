<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offices held — historical record of a person holding a formal position.
 *
 * held_until = NULL means currently in office.
 * A person can hold multiple offices simultaneously in different units.
 * Past records are never deleted — they inform historical reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices_held', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('org_unit_id')->constrained('org_units')->cascadeOnDelete();
            $table->foreignId('office_type_id')->constrained('office_types');
            $table->date('held_from');
            $table->date('held_until')->nullable()
                  ->comment('NULL = currently in office');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['person_id', 'held_until']);
            $table->index(['org_unit_id', 'office_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offices_held');
    }
};
