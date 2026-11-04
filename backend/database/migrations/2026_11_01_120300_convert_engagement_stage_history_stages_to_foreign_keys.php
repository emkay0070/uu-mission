<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add new nullable foreign key columns
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->foreignId('from_stage_id')->nullable()->after('person_id')->constrained('engagement_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->after('from_stage_id')->constrained('engagement_stages')->nullOnDelete();
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
                UPDATE engagement_stage_histories h
                SET from_stage_id = (SELECT id FROM engagement_stages WHERE code = ?)
                WHERE from_stage = ?
            ", [$newCode, $oldCode]);

            DB::statement("
                UPDATE engagement_stage_histories h
                SET to_stage_id = (SELECT id FROM engagement_stages WHERE code = ?)
                WHERE to_stage = ?
            ", [$newCode, $oldCode]);
        }

        // Set default stage for NULL values
        DB::statement("
            UPDATE engagement_stage_histories
            SET from_stage_id = (SELECT id FROM engagement_stages WHERE code = 'new_contact')
            WHERE from_stage_id IS NULL
        ");

        DB::statement("
            UPDATE engagement_stage_histories
            SET to_stage_id = (SELECT id FROM engagement_stages WHERE code = 'new_contact')
            WHERE to_stage_id IS NULL
        ");

        // Drop old ENUM columns
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->dropColumn('from_stage');
            $table->dropColumn('to_stage');
        });

        // Make new columns non-nullable
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->foreignId('from_stage_id')->nullable(false)->change();
            $table->foreignId('to_stage_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Add back ENUM columns as nullable
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->enum('from_stage', [
                'new_contact',
                'interested',
                'studying',
                'decision_made',
                'baptism_candidate',
                'baptized',
                'member',
                'inactive',
                'lost_contact'
            ])->nullable()->after('person_id');

            $table->enum('to_stage', [
                'new_contact',
                'interested',
                'studying',
                'decision_made',
                'baptism_candidate',
                'baptized',
                'member',
                'inactive',
                'lost_contact'
            ])->nullable()->after('from_stage');
        });

        // Map back from foreign keys to ENUM
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
                UPDATE engagement_stage_histories h
                SET from_stage = ?
                WHERE from_stage_id = (SELECT id FROM engagement_stages WHERE code = ?)
            ", [$oldCode, $newCode]);

            DB::statement("
                UPDATE engagement_stage_histories h
                SET to_stage = ?
                WHERE to_stage_id = (SELECT id FROM engagement_stages WHERE code = ?)
            ", [$oldCode, $newCode]);
        }

        // Drop foreign key columns
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->dropForeign(['to_stage_id']);
            $table->dropColumn('to_stage_id');
            $table->dropForeign(['from_stage_id']);
            $table->dropColumn('from_stage_id');
        });

        // Make ENUM columns non-nullable
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->enum('from_stage', [
                'new_contact',
                'interested',
                'studying',
                'decision_made',
                'baptism_candidate',
                'baptized',
                'member',
                'inactive',
                'lost_contact'
            ])->nullable(false)->change();

            $table->enum('to_stage', [
                'new_contact',
                'interested',
                'studying',
                'decision_made',
                'baptism_candidate',
                'baptized',
                'member',
                'inactive',
                'lost_contact'
            ])->nullable(false)->change();
        });
    }
};
