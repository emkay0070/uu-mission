<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site logistics — tracks operational arrangements for mission sites.
 *
 * This table captures essential logistics needed to run a mission site:
 * venue information, transport arrangements, accommodation details, and
 * material requirements. This is intentionally simple — not a full
 * inventory or procurement system, just enough to ensure operational readiness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_logistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_site_id')->constrained('mission_sites')->cascadeOnDelete();
            
            // Venue
            $table->string('venue_name')->nullable()->comment('Name of meeting venue');
            $table->text('venue_address')->nullable()->comment('Venue location/address');
            $table->string('venue_contact')->nullable()->comment('Venue contact person or phone');
            $table->boolean('venue_confirmed')->default(false)->comment('Is venue arrangement confirmed?');
            
            // Transport
            $table->text('transport_arrangements')->nullable()->comment('How team/equipment will reach site');
            $table->boolean('transport_confirmed')->default(false)->comment('Is transport arranged?');
            
            // Accommodation
            $table->text('accommodation_details')->nullable()->comment('Where deployed workers will stay');
            $table->boolean('accommodation_confirmed')->default(false)->comment('Is accommodation arranged?');
            
            // Materials
            $table->text('materials_needed')->nullable()->comment('Equipment, literature, supplies needed');
            $table->text('materials_status')->nullable()->comment('Status of material procurement');
            
            // Budget
            $table->decimal('budget_allocated', 10, 2)->nullable()->comment('Budget allocated for this site');
            $table->decimal('budget_spent', 10, 2)->nullable()->default(0)->comment('Budget spent to date');
            
            // Responsibility
            $table->foreignId('responsible_person_id')->nullable()->constrained('people')->nullOnDelete()
                  ->comment('Person responsible for logistics at this site');
            
            $table->text('notes')->nullable()->comment('Additional logistics notes');
            $table->timestamps();
            
            $table->index('mission_site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_logistics');
    }
};
