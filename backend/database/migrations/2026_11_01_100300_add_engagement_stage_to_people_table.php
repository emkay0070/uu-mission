<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('engagement_stage');
        });
    }
};
