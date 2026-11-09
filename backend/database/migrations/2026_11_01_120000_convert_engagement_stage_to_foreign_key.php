<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add new nullable foreign key column
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('engagement_stage_id')->nullable()->after('engagement_stage')->constrained('engagement_stages')->nullOnDelete();
        });

        // Map existing ENUM values to new stage codes
        $stageMap = [
            'new_contact' => 'new_contact',
            'interested' => 'interested',
            'studying' => 'bible_studies_ongoing',
            'decision_made' => 'decision_for_christ',
            'baptism_candidate' => 'preparing_for_baptism',
            'baptized' => 'baptized',
            'member' => 'church_member',
            'inactive' => 'temporarily_inactive',
            'lost_contact' => 'unable_to_contact',
        ];

        foreach ($stageMap as $oldCode => $newCode) {
            DB::statement("
                UPDATE people p
                SET engagement_stage_id = (SELECT id FROM engagement_stages WHERE code = ?)
                WHERE engagement_stage = ?
            ", [$newCode, $oldCode]);
        }

        // Set default stage for NULL values
        DB::statement("
            UPDATE people
            SET engagement_stage_id = (SELECT id FROM engagement_stages WHERE code = 'new_contact')
            WHERE engagement_stage_id IS NULL
        ");

        // Drop old ENUM column
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('engagement_stage');
        });

        // Make new column non-nullable
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('engagement_stage_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Add back ENUM column as nullable
        Schema::table('people', function (Blueprint $table) {
            $table->enum('engagement_stage', [
                'new_contact',
                'interested',
                'studying',
                'decision_made',
                'baptism_candidate',
                'baptized',
                'member',
                'inactive',
                'lost_contact'
            ])->nullable()->after('notes');
        });

        // Map back from foreign key to ENUM
        $stageMap = [
            'new_contact' => 'new_contact',
            'interested' => 'interested',
            'bible_studies_ongoing' => 'studying',
            'decision_for_christ' => 'decision_made',
            'preparing_for_baptism' => 'baptism_candidate',
            'baptized' => 'baptized',
            'church_member' => 'member',
            'temporarily_inactive' => 'inactive',
            'unable_to_contact' => 'lost_contact',
        ];

        foreach ($stageMap as $newCode => $oldCode) {
            DB::statement("
                UPDATE people p
                SET engagement_stage = ?
                WHERE engagement_stage_id = (SELECT id FROM engagement_stages WHERE code = ?)
            ", [$oldCode, $newCode]);
        }

        // Drop foreign key column
        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['engagement_stage_id']);
            $table->dropColumn('engagement_stage_id');
        });

        // Make ENUM column non-nullable (use raw SQL to avoid PostgreSQL
        // incompatibility with inline CHECK constraints in ALTER COLUMN TYPE)
        DB::statement('ALTER TABLE people ALTER COLUMN engagement_stage SET NOT NULL');
    }
};
