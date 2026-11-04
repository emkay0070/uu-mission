<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Office types — the catalogue of formal positions within the organization.
 *
 * Scoping an office type to a specific org_unit_type ensures, for example,
 * that 'Union Youth President' can only be held within a 'union' unit.
 * When org_unit_type_id is null, the office can be held in any unit type.
 *
 * New office types can be added by authorized admins without code changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique()
                  ->comment('e.g. union_youth_president | field_youth_leader | campaign_secretary');
            $table->string('label', 200);
            $table->foreignId('org_unit_type_id')
                  ->nullable()
                  ->constrained('org_unit_types')
                  ->nullOnDelete()
                  ->comment('If set, office is only valid within this unit type');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_types');
    }
};
