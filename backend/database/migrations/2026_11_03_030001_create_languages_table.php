<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique()->comment('Custom code or ISO 639-1/639-3');
            $table->string('name', 100);
            $table->string('native_name', 100)->nullable();
            $table->string('iso_639_1', 2)->nullable()->comment('ISO 639-1 code if applicable');
            $table->string('iso_639_3', 3)->nullable()->comment('ISO 639-3 code if applicable');
            $table->boolean('supports_translation')->default(true);
            $table->boolean('supports_stt')->default(false)->comment('Speech-to-Text');
            $table->boolean('supports_tts')->default(false)->comment('Text-to-Speech');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index('code');
            $table->index('iso_639_1');
            $table->index('iso_639_3');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
