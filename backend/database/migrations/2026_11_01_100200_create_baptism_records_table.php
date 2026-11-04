<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baptism_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained()->onDelete('cascade');
            $table->foreignId('campaign_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('mission_site_id')->nullable()->constrained()->onDelete('set null');
            $table->date('baptism_date');
            $table->string('location')->nullable();
            $table->foreignId('officiated_by_person_id')->nullable()->constrained('people')->onDelete('set null');
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['person_id', 'baptism_date']);
            $table->index(['campaign_id', 'mission_site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baptism_records');
    }
};
