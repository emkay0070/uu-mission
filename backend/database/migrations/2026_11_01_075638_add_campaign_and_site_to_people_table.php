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
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('engagement_stage')->constrained('campaigns')->onDelete('set null');
            $table->foreignId('mission_site_id')->nullable()->after('campaign_id')->constrained('mission_sites')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropForeign(['mission_site_id']);
            $table->dropColumn(['campaign_id', 'mission_site_id']);
        });
    }
};
