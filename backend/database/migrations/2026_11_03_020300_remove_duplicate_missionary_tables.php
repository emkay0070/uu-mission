<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove duplicate missionary_skills and missionary_languages tables.
 *
 * These tables duplicate person_skills and person_languages.
 * The canonical storage is now the person_* tables which apply to all people.
 * MissionaryProfile will access skills/languages through the Person relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop missionary_languages table
        Schema::dropIfExists('missionary_languages');
        
        // Drop missionary_skills table
        Schema::dropIfExists('missionary_skills');
    }

    public function down(): void
    {
        // Recreate missionary_languages (for rollback)
        Schema::create('missionary_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('missionary_profile_id')
                  ->constrained('missionary_profiles')
                  ->cascadeOnDelete();
            $table->string('language_code', 20)
                  ->comment('ISO 639 or custom local dialect code');
            $table->boolean('is_native')->default(false);
            $table->string('proficiency_level', 20)->nullable()
                  ->comment('basic | conversational | fluent | native');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['missionary_profile_id', 'language_code']);
        });

        // Recreate missionary_skills (for rollback)
        Schema::create('missionary_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('missionary_profile_id')
                  ->constrained('missionary_profiles')
                  ->cascadeOnDelete();
            $table->string('skill_category', 80)
                  ->comment('e.g. evangelism | music | medical | construction | teaching | counselling');
            $table->string('skill_name', 150);
            $table->string('proficiency_level', 20)->nullable()
                  ->comment('basic | intermediate | advanced');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['missionary_profile_id', 'skill_category']);
        });
    }
};
