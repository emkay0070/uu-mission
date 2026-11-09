<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_version_id')->nullable()->constrained('resource_versions')->nullOnDelete();

            $table->text('selected_text')->comment('The text that was highlighted');
            $table->integer('page_number')->comment('Page number where highlight appears');
            $table->json('bounding_box')->comment('Normalized coordinates: {x, y, width, height} in 0-1 range');
            $table->string('color', 20)->default('#ffff00')->comment('Hex color code for highlight');
            $table->float('zoom_level')->nullable()->comment('Zoom level when highlight was created');

            $table->timestamps();

            $table->index('resource_id');
            $table->index('user_id');
            $table->index('page_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_highlights');
    }
};
