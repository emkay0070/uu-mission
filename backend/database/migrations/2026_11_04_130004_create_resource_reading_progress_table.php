<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_reading_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->cascadeOnDelete();

            $table->integer('last_page')->default(1)->comment('Last page read by user');
            $table->integer('total_pages')->nullable()->comment('Total pages in the document');
            $table->decimal('completion_percentage', 5, 2)->default(0)->comment('0.00 to 100.00');
            $table->timestamp('last_read_at')->nullable()->comment('When the user last opened this resource');
            $table->json('reading_position')->nullable()->comment('Normalized coordinates: {page, x, y}');

            // Note: is_current_version is removed - derive by comparing resource_version_id with resources.current_version_id

            $table->timestamps();

            // Unique constraint on (resource_id, user_id, resource_version_id) to preserve progress per version
            $table->unique(['resource_id', 'user_id', 'resource_version_id'], 'unique_progress_per_version');

            $table->index('resource_id');
            $table->index('user_id');
            $table->index('last_read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_reading_progress');
    }
};
