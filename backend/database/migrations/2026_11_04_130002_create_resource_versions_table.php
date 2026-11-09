<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('version_number')->comment('Human-readable version identifier (e.g. 1.0, 1.1, 2.0)');

            // File storage (authoritative - resources table only references this)
            $table->string('storage_disk')->default('local')->comment('Filesystem disk name');
            $table->string('storage_path')->comment('Path within the storage disk');
            $table->string('original_filename')->comment('Original filename from upload');
            $table->string('mime_type')->comment('MIME type of the file');
            $table->enum('media_type', ['pdf', 'document', 'image', 'audio', 'video'])->default('pdf');
            $table->unsignedBigInteger('file_size')->comment('File size in bytes');

            $table->text('change_reason')->nullable()->comment('Reason for this version (e.g. typo fix, new content)');
            $table->foreignId('replaced_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('resource_id');
            $table->index('version_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_versions');
    }
};
