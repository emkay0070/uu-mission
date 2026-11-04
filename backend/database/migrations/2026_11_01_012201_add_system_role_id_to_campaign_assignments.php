<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table) {
            $table->foreignId('system_role_id')
                  ->nullable()
                  ->constrained('system_roles')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_assignments', function (Blueprint $table) {
            $table->dropForeign(['system_role_id']);
            $table->dropColumn('system_role_id');
        });
    }
};
