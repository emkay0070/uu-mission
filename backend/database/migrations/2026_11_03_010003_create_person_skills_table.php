<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('skill_category', 80)->comment('evangelism | music | medical | construction | teaching | counselling | technical | administrative');
            $table->string('skill_name', 150);
            $table->string('proficiency_level', 20)->nullable()->comment('beginner | intermediate | advanced | expert');
            $table->integer('years_experience')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['person_id', 'skill_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_skills');
    }
};
