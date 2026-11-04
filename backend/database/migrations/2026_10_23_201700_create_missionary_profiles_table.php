<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Missionary profiles, skills and languages.
 *
 * A missionary profile is a persistent record attached to a person.
 * It survives across campaigns — the same person can be redeployed
 * without creating a new identity record.
 *
 * Sensitive fields:
 * - emergency_contact on people is already encrypted at app layer.
 * - training_notes is accessible only to authorized coordinators
 *   via policy; no additional encryption in schema.
 *
 * Campaign-specific assignments are in campaign_assignments (separate table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missionary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')
                  ->unique()
                  ->constrained('people')
                  ->cascadeOnDelete();
            $table->foreignId('home_org_unit_id')
                  ->nullable()
                  ->constrained('org_units')
                  ->nullOnDelete()
                  ->comment('Home church or club');
            $table->string('deployment_status', 30)->default('available')
                  ->comment('available | deployed | unavailable | inactive');
            $table->text('bio')->nullable();
            $table->text('training_notes')->nullable();
            $table->timestamps();

            $table->index('deployment_status');
        });

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
    }

    public function down(): void
    {
        Schema::dropIfExists('missionary_languages');
        Schema::dropIfExists('missionary_skills');
        Schema::dropIfExists('missionary_profiles');
    }
};
