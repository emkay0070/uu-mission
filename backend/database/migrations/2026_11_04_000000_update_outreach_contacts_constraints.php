<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Update OutreachContact constraints for campaign-specific engagement records.
 *
 * Changes:
 * 1. Remove unique constraint on person_id (allows one Person to have multiple OutreachContacts)
 * 2. Add composite unique constraint on (person_id, campaign_id) (one OutreachContact per Person per campaign)
 * 3. Handle legacy records with NULL campaign_id by setting a flag column
 * 4. Make campaign_id and mission_site_id required for new records (via model validation, not DB constraint)
 *
 * Legacy handling:
 * - Existing records with NULL campaign_id are preserved
 * - They are marked with is_legacy = true for identification
 * - New records must have campaign_id and mission_site_id
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add is_legacy flag to identify pre-migration records without campaign association
        Schema::table('outreach_contacts', function (Blueprint $table) {
            $table->boolean('is_legacy')->default(false)->after('is_active')
                  ->comment('Legacy record without campaign association (pre-migration)');
        });

        // Mark existing records with NULL campaign_id as legacy
        DB::statement("
            UPDATE outreach_contacts
            SET is_legacy = true
            WHERE campaign_id IS NULL
        ");

        // Drop the existing unique constraint on person_id
        Schema::table('outreach_contacts', function (Blueprint $table) {
            $table->dropUnique(['person_id']);
        });

        // Add composite unique constraint on (person_id, campaign_id)
        // Note: This allows multiple NULL campaign_id records (legacy), but enforces
        // uniqueness for records WITH a campaign_id
        Schema::table('outreach_contacts', function (Blueprint $table) {
            $table->unique(['person_id', 'campaign_id'], 'outreach_contacts_person_campaign_unique');
        });
    }

    public function down(): void
    {
        // Rollback: remove composite unique constraint
        Schema::table('outreach_contacts', function (Blueprint $table) {
            $table->dropUnique('outreach_contacts_person_campaign_unique');
        });

        // Restore original unique constraint on person_id
        // This will fail if there are multiple OutreachContacts for the same Person
        Schema::table('outreach_contacts', function (Blueprint $table) {
            $table->unique('person_id');
        });

        // Remove is_legacy flag
        Schema::table('outreach_contacts', function (Blueprint $table) {
            $table->dropColumn('is_legacy');
        });
    }
};
