<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained()->onDelete('cascade');
            $table->foreignId('campaign_id')->constrained()->onDelete('restrict');
            $table->foreignId('mission_site_id')->nullable()->constrained()->onDelete('restrict');
            $table->foreignId('encounter_method_id')->nullable()->constrained('encounter_methods')->onDelete('set null');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->date('encounter_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['person_id', 'encounter_date']);
            $table->index(['campaign_id', 'mission_site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_encounters');
    }
};
