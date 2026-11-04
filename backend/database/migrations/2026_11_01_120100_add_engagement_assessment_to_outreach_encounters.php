<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_encounters', function (Blueprint $table) {
            $table->foreignId('stage_before_encounter_id')->nullable()->after('notes')->constrained('engagement_stages')->nullOnDelete();
            $table->foreignId('stage_after_encounter_id')->nullable()->after('stage_before_encounter_id')->constrained('engagement_stages')->nullOnDelete();
            $table->string('next_action')->nullable()->after('stage_after_encounter_id');
            $table->date('follow_up_date')->nullable()->after('next_action');
            $table->foreignId('assigned_follow_up_user_id')->nullable()->after('follow_up_date')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outreach_encounters', function (Blueprint $table) {
            $table->dropForeign(['assigned_follow_up_user_id']);
            $table->dropColumn('assigned_follow_up_user_id');
            $table->dropColumn('follow_up_date');
            $table->dropColumn('next_action');
            $table->dropForeign(['stage_after_encounter_id']);
            $table->dropColumn('stage_after_encounter_id');
            $table->dropForeign(['stage_before_encounter_id']);
            $table->dropColumn('stage_before_encounter_id');
        });
    }
};
