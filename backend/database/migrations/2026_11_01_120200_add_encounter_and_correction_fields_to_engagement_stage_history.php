<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->foreignId('outreach_encounter_id')->nullable()->after('notes')->constrained('outreach_encounters')->nullOnDelete();
            $table->text('correction_reason')->nullable()->after('outreach_encounter_id');
            $table->boolean('is_correction')->default(false)->after('correction_reason');
        });
    }

    public function down(): void
    {
        Schema::table('engagement_stage_histories', function (Blueprint $table) {
            $table->dropColumn('is_correction');
            $table->dropColumn('correction_reason');
            $table->dropForeign(['outreach_encounter_id']);
            $table->dropColumn('outreach_encounter_id');
        });
    }
};
