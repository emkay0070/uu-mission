<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add HNSW index for approximate nearest neighbor search using cosine distance
        // This index enables fast vector similarity search
        DB::statement('CREATE INDEX idx_resource_document_chunks_embedding_hnsw 
                       ON resource_document_chunks 
                       USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_resource_document_chunks_embedding_hnsw');
    }
};
