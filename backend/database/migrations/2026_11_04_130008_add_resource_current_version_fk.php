<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add FK constraint for current_version_id after both tables exist
        Schema::table('resources', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('resource_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
    }
};
