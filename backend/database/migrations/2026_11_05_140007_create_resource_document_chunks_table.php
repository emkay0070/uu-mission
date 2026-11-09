<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->cascadeOnDelete();
            $table->foreignId('processing_id')->constrained('resource_document_processings')->cascadeOnDelete();

            $table->integer('page_number');
            $table->string('chapter')->nullable();
            $table->string('heading')->nullable();
            $table->integer('chunk_order')->comment('Order within page');
            $table->text('extracted_text');
            $table->json('position_data')->nullable()->comment('Coordinates for highlighting');

            $table->timestamps();

            // Prevent duplicate chunk ordering within page
            $table->unique(['resource_version_id', 'page_number', 'chunk_order'], 'unique_chunk_order');

            $table->index('resource_version_id');
            $table->index('page_number');
            $table->index('chapter');

            // PostgreSQL-compatible full-text search index
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->fullText('extracted_text');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_document_chunks');
    }
};
