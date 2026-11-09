<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_version_id')->nullable()->constrained('resource_versions')->nullOnDelete();

            $table->integer('page_number')->comment('Page number where note is anchored');
            $table->json('position_data')->nullable()->comment('Normalized coordinates: {x, y, width, height} for note anchor');
            $table->text('content')->comment('Note content');

            $table->timestamps();

            $table->index('resource_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_notes');
    }
};
