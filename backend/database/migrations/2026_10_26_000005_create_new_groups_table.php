<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New groups — tracks emerging groups of believers from mission outcomes.
 *
 * This supports Global Mission's emphasis on establishing new groups of believers
 * in areas where the Church has little or no presence. It tracks groups that
 * emerge from mission activities and their progression toward becoming established
 * congregations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('mission_site_id')->nullable()->constrained('mission_sites')->nullOnDelete();
            
            // Group information
            $table->string('name', 250)->comment('Name of the group or fellowship');
            $table->text('location_description')->nullable()->comment('Where the group meets');
            $table->string('contact_person_name')->nullable()->comment('Primary contact for the group');
            $table->string('contact_person_phone')->nullable()->comment('Contact phone number');
            
            // Group development
            $table->string('stage', 50)->default('interest_group')
                  ->comment('interest_group | bible_study_group | fellowship | emerging_congregation | established');
            $table->integer('regular_attendance')->nullable()->comment('Average regular attendance');
            $table->date('first_meeting_date')->nullable()->comment('When the group first met');
            
            // Leadership
            $table->foreignId('leader_person_id')->nullable()->constrained('people')->nullOnDelete()
                  ->comment('Primary leader of the group');
            $table->text('leadership_notes')->nullable()->comment('Notes on leadership development');
            
            // Status
            $table->string('status', 50)->default('active')
                  ->comment('active | merged_with_church | discontinued');
            $table->text('notes')->nullable()->comment('Additional notes about the group');
            
            $table->timestamps();
            
            $table->index(['campaign_id', 'status']);
            $table->index('mission_site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_groups');
    }
};
