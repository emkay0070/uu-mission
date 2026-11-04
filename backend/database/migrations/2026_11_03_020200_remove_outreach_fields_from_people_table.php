<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove outreach-specific fields from people table.
 *
 * These fields now belong to the outreach_contacts table.
 * People table now contains only permanent identity information.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // Drop engagement stage foreign key and column
            $table->dropForeign(['engagement_stage_id']);
            $table->dropColumn('engagement_stage_id');
            
            // Drop outreach-specific fields
            $table->dropColumn(['village', 'date_registered', 'notes', 'campaign_id', 'mission_site_id']);
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // Re-add outreach-specific fields (for rollback)
            $table->foreignId('engagement_stage_id')->nullable()->after('notes')->constrained('engagement_stages')->nullOnDelete();
            $table->string('village', 200)->nullable()->after('phone');
            $table->date('date_registered')->nullable()->after('village');
            $table->text('notes')->nullable()->after('date_registered');
            $table->foreignId('campaign_id')->nullable()->after('notes')->constrained('campaigns')->nullOnDelete();
            $table->foreignId('mission_site_id')->nullable()->after('campaign_id')->constrained('mission_sites')->nullOnDelete();
        });
    }
};
