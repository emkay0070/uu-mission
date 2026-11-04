<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('External identifier for public references');
            
            // File information
            $table->string('original_filename')->comment('Original filename from upload');
            $table->string('stored_filename')->unique()->comment('Unique filename in storage');
            $table->string('mime_type')->comment('MIME type of the file');
            $table->enum('media_type', ['image', 'video', 'audio', 'document'])->default('document');
            
            // Storage information
            $table->string('storage_disk')->default('local')->comment('Filesystem disk name');
            $table->string('storage_path')->comment('Path within the storage disk');
            $table->string('thumbnail_path')->nullable()->comment('Path to thumbnail/preview file');
            
            // File metadata
            $table->unsignedBigInteger('file_size')->comment('File size in bytes');
            $table->unsignedInteger('width')->nullable()->comment('Image width in pixels');
            $table->unsignedInteger('height')->nullable()->comment('Image height in pixels');
            $table->unsignedInteger('duration')->nullable()->comment('Video/audio duration in seconds');
            
            // Descriptive metadata
            $table->string('title')->nullable();
            $table->text('caption')->nullable()->comment('Description or caption');
            
            // Ownership and context
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('capture_date')->nullable()->comment('Date the media was captured (if known)');
            
            // Visibility and access
            $table->enum('visibility', ['private', 'organisational', 'public'])->default('private');
            $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            
            // Safeguarding
            $table->boolean('requires_consent')->default(false)->comment('Whether consent is required for publication');
            $table->boolean('consent_obtained')->default(false)->comment('Whether consent has been obtained');
            $table->text('consent_notes')->nullable()->comment('Notes about consent status');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('media_type');
            $table->index('visibility');
            $table->index('uploaded_by_user_id');
            $table->index('processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
