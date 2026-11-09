<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_version_id')->nullable()->constrained('resource_versions')->nullOnDelete();

            $table->integer('page_number')->comment('Page number bookmarked');
            $table->json('position_data')->nullable()->comment('Normalized coordinates: {x, y} for precise positioning');
            $table->string('title')->comment('User-defined bookmark title');
            $table->timestamp('created_at')->useCurrent();

            $table->index('resource_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_bookmarks');
    }
};
