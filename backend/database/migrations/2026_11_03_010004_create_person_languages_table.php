<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('language_code', 20)->comment('ISO 639 or custom dialect code');
            $table->boolean('is_native')->default(false);
            $table->string('proficiency_level', 20)->nullable()->comment('basic | conversational | fluent | native');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->unique(['person_id', 'language_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_languages');
    }
};
