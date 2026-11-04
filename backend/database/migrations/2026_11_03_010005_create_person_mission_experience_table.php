<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_mission_experience', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('mission_name', 200);
            $table->string('location', 200)->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('role', 100)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->index(['person_id', 'ended_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_mission_experience');
    }
};
