<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_document_processings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->cascadeOnDelete();

            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->integer('total_pages')->nullable();
            $table->integer('extracted_pages')->default(0);
            $table->json('processing_metadata')->nullable()->comment('Extraction method, errors, etc.');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // One processing record per resource version
            $table->unique('resource_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_document_processings');
    }
};
