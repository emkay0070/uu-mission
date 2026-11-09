<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('resource_version_id')->nullable()->constrained('resource_versions')->nullOnDelete();

            $table->bigInteger('file_size')->nullable()->comment('Size of downloaded file in bytes');
            $table->string('download_status', 20)->default('completed')->comment('pending, completed, failed');
            $table->timestamp('downloaded_at')->nullable()->comment('When the download completed');
            $table->timestamp('last_accessed_at')->nullable()->comment('When the user last opened this downloaded resource');

            $table->timestamps();

            // Unique constraint on (user_id, resource_id) - one download record per resource per user
            $table->unique(['user_id', 'resource_id'], 'unique_download_per_user_resource');

            $table->index('user_id');
            $table->index('resource_id');
            $table->index('download_status');
            $table->index('downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_downloads');
    }
};
