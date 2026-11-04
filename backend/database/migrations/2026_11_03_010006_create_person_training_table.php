<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_training', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('training_type', 50)->comment('mission | safeguarding | first_aid | leadership | pastoral | technical | other');
            $table->string('training_name', 200);
            $table->string('training_provider', 200)->nullable();
            $table->date('completed_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('certificate_number', 100)->nullable();
            $table->string('verification_status', 20)->default('unverified')->comment('unverified | verified | expired');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['person_id', 'training_type']);
            $table->index(['person_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_training');
    }
};
