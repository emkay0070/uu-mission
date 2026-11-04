<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Update engagement_stage_history to link to outreach_contacts.
 *
 * Engagement stage transitions now belong to OutreachContact, not Person.
 * This separates outreach journey tracking from permanent identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            // Add outreach_contact_id as the primary relationship
            $table->foreignId('outreach_contact_id')
                  ->nullable()
                  ->after('person_id')
                  ->constrained('outreach_contacts')
                  ->cascadeOnDelete();

            // Make person_id nullable since it's now a secondary reference
            $table->foreignId('person_id')->nullable()->change();
        });

        // Since this is a fresh development database, we don't need to backfill
        // In production, we would migrate existing data here

        // Make outreach_contact_id NOT NULL (all new records must have it)
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->foreignId('outreach_contact_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->dropForeign(['outreach_contact_id']);
            $table->dropColumn('outreach_contact_id');
            
            // Make person_id NOT NULL again
            $table->foreignId('person_id')->nullable(false)->change();
        });
    }
};
