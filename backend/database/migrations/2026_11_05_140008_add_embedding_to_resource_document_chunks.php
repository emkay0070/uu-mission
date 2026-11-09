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
        Schema::table('resource_document_chunks', function (Blueprint $table) {
            // Embedding lifecycle metadata (works in all databases)
            $table->string('embedding_model')->nullable()->comment('Model used for embedding (e.g., gemini-embedding-2)');
            $table->integer('embedding_dimensions')->nullable()->comment('Dimensions of the embedding vector');
            $table->string('embedding_version')->nullable()->comment('Version of the embedding generation');
            $table->string('source_text_hash', 64)->nullable()->comment('SHA-256 hash of source text for change detection');
        });

        // Add pgvector embedding column only for PostgreSQL
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::table('resource_document_chunks', function (Blueprint $table) {
                $table->vector('embedding', 768)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_document_chunks', function (Blueprint $table) {
            $table->dropColumn([
                'embedding',
                'embedding_model',
                'embedding_dimensions',
                'embedding_version',
                'source_text_hash',
            ]);
        });
    }
};
