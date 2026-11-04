<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('subject_name')->nullable()->comment('Name of person in media');
            $table->string('subject_relationship')->nullable()->comment('Relationship to organisation');
            $table->enum('consent_type', ['photo', 'video', 'audio', 'general'])->default('general');
            $table->boolean('is_minor')->default(false)->comment('Whether subject is a minor');
            $table->string('guardian_name')->nullable()->comment('Guardian name if minor');
            $table->text('consent_details')->nullable()->comment('Specific consent details');
            $table->date('consent_date')->nullable()->comment('Date consent was obtained');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            $table->index('media_id');
            $table->index('consent_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_consents');
    }
};
