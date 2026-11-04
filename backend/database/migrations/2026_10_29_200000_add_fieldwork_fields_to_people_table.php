<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add fieldwork-specific fields to people table.
 *
 * These fields support the digital register workflow:
 * - village: area of residence for follow-up
 * - date_registered: when the person was first encountered
 * - notes: follow-up information and context
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('village', 200)->nullable()->after('phone');
            $table->date('date_registered')->nullable()->after('village');
            $table->text('notes')->nullable()->after('date_registered');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['village', 'date_registered', 'notes']);
        });
    }
};
