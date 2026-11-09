<?php

namespace App\Services;

use App\Models\Resource;
use App\Models\ResourceDocumentChunk;
use App\Models\ResourceVersion;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BookRetrievalService — hybrid book retrieval using vector and full-text search.
 *
 * Combines pgvector semantic similarity search with PostgreSQL full-text search
 * using Reciprocal Rank Fusion (RRF) to retrieve relevant passages from processed PDFs.
 *
 * Strictly enforces resource version isolation and access control.
 */
class BookRetrievalService
{
    protected AIService $aiService;

    protected ResourceVisibilityService $visibility;

    // Retrieval configuration
    protected int $vectorLimit;

    protected int $fulltextLimit;

    protected int $finalLimit;

    protected int $rrfK;

    protected float $minSimilarity;

    protected int $maxPassages;

    protected int $maxTokens;

    public function __construct(AIService $aiService, ResourceVisibilityService $visibility)
    {
        $this->aiService = $aiService;
        $this->visibility = $visibility;

        $this->vectorLimit = config('services.retrieval.vector_limit', 20);
        $this->fulltextLimit = config('services.retrieval.fulltext_limit', 20);
        $this->finalLimit = config('services.retrieval.final_limit', 10);
        $this->rrfK = config('services.retrieval.rrf_k', 60);
        $this->minSimilarity = config('services.retrieval.min_similarity', 0.5);
        $this->maxPassages = config('services.retrieval.max_passages', 5);
        $this->maxTokens = config('services.retrieval.max_tokens', 2000);
    }

    /**
     * Retrieve relevant passages for a query from a specific resource version.
     *
     * @param  User  $user  The authenticated user
     * @param  resource  $resource  The resource being queried
     * @param  ResourceVersion  $version  The specific resource version
     * @param  string  $query  The user's question
     * @return array {success: bool, passages: array, error: string, metadata: array}
     */
    public function retrieve(User $user, Resource $resource, ResourceVersion $version, string $query): array
    {
        // Verify user has access to this resource and version
        if (! $this->visibility->canView($user, $resource, $version)) {
            return $this->errorResponse('You do not have access to this resource', 403);
        }

        // Verify the version belongs to the resource
        if ((int) $version->resource_id !== (int) $resource->id) {
            return $this->errorResponse('Version does not belong to this resource', 400);
        }

        // Validate query
        if (empty(trim($query))) {
            return $this->errorResponse('Query cannot be empty', 400);
        }

        // Check if document processing is complete
        $processing = $version->documentProcessing;
        if (! $processing || ! $processing->isCompleted()) {
            return $this->errorResponse('Document processing is not complete', 400);
        }

        try {
            // Generate query embedding
            $embeddingResult = $this->aiService->generateEmbeddings($query);

            if (! $embeddingResult['success']) {
                Log::error('Query embedding generation failed', [
                    'resource_version_id' => $version->id,
                    'error' => $embeddingResult['error'],
                ]);

                return $this->errorResponse('Failed to generate query embedding', 500);
            }

            $queryEmbedding = $embeddingResult['embeddings'][0] ?? null;

            if (! $queryEmbedding || count($queryEmbedding) !== 768) {
                Log::error('Invalid query embedding dimensions', [
                    'resource_version_id' => $version->id,
                    'dimensions' => $queryEmbedding ? count($queryEmbedding) : 0,
                ]);

                return $this->errorResponse('Invalid query embedding', 500);
            }

            // Perform vector search
            $vectorResults = $this->vectorSearch($version->id, $queryEmbedding);

            // Perform full-text search
            $fulltextResults = $this->fulltextSearch($version->id, $query);

            // Combine results using RRF
            $combinedResults = $this->reciprocalRankFusion($vectorResults, $fulltextResults);

            // Apply token budget and passage limits
            $passages = $this->applyTokenBudget($combinedResults);

            $metadata = [
                'resource_id' => $resource->id,
                'resource_version_id' => $version->id,
                'vector_count' => count($vectorResults),
                'fulltext_count' => count($fulltextResults),
                'combined_count' => count($combinedResults),
                'returned_count' => count($passages),
                'query_length' => strlen($query),
            ];

            return [
                'success' => true,
                'passages' => $passages,
                'error' => null,
                'metadata' => $metadata,
            ];

        } catch (Exception $e) {
            Log::error('Book retrieval failed', [
                'resource_version_id' => $version->id,
                'query' => substr($query, 0, 100),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Retrieval failed: '.$e->getMessage(), 500);
        }
    }

    /**
     * Perform vector similarity search using pgvector.
     *
     * @return array Array of {chunk_id, score}
     */
    protected function vectorSearch(int $resourceVersionId, array $queryEmbedding): array
    {
        // Only PostgreSQL supports pgvector
        if (DB::connection()->getDriverName() !== 'pgsql') {
            Log::warning('Vector search not supported on non-PostgreSQL database');

            return [];
        }

        try {
            // Convert embedding to PostgreSQL array format
            $embeddingArray = '['.implode(',', $queryEmbedding).']';

            $results = DB::select(
                'SELECT 
                    id as chunk_id,
                    1 - (embedding <=> :embedding::vector) as score
                FROM resource_document_chunks
                WHERE resource_version_id = :resource_version_id
                    AND embedding IS NOT NULL
                    AND embedding_model = :embedding_model
                    AND embedding_dimensions = :embedding_dimensions
                ORDER BY embedding <=> :embedding::vector
                LIMIT :limit',
                [
                    'embedding' => $embeddingArray,
                    'resource_version_id' => $resourceVersionId,
                    'embedding_model' => config('services.gemini.embedding_model', 'gemini-embedding-2'),
                    'embedding_dimensions' => config('services.gemini.embedding_dimensions', 768),
                    'limit' => $this->vectorLimit,
                ]
            );

            // Filter by minimum similarity
            $results = array_filter($results, fn ($r) => $r->score >= $this->minSimilarity);

            return array_map(fn ($r) => [
                'chunk_id' => $r->chunk_id,
                'score' => $r->score,
            ], $results);

        } catch (Exception $e) {
            Log::error('Vector search failed', [
                'resource_version_id' => $resourceVersionId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Perform full-text search using PostgreSQL.
     *
     * @return array Array of {chunk_id, score}
     */
    protected function fulltextSearch(int $resourceVersionId, string $query): array
    {
        // Only PostgreSQL supports full-text search
        if (DB::connection()->getDriverName() !== 'pgsql') {
            Log::warning('Full-text search not supported on non-PostgreSQL database');

            return [];
        }

        try {
            $results = DB::select(
                "SELECT 
                    id as chunk_id,
                    ts_rank(to_tsvector('english', extracted_text), plainto_tsquery('english', :query)) as score
                FROM resource_document_chunks
                WHERE resource_version_id = :resource_version_id
                    AND to_tsvector('english', extracted_text) @@ plainto_tsquery('english', :query)
                ORDER BY score DESC
                LIMIT :limit",
                [
                    'query' => $query,
                    'resource_version_id' => $resourceVersionId,
                    'limit' => $this->fulltextLimit,
                ]
            );

            return array_map(fn ($r) => [
                'chunk_id' => $r->chunk_id,
                'score' => $r->score,
            ], $results);

        } catch (Exception $e) {
            Log::error('Full-text search failed', [
                'resource_version_id' => $resourceVersionId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Combine results using Reciprocal Rank Fusion (RRF).
     *
     * RRF formula: score = sum(1 / (k + rank)) for each result list
     *
     * @param  array  $vectorResults  Array of {chunk_id, score}
     * @param  array  $fulltextResults  Array of {chunk_id, score}
     * @return array Array of {chunk_id, rrf_score} sorted by rrf_score DESC
     */
    public function reciprocalRankFusion(array $vectorResults, array $fulltextResults): array
    {
        $scores = [];

        // Add vector search scores (ranked by score DESC)
        foreach ($vectorResults as $rank => $result) {
            $chunkId = $result['chunk_id'];
            $rank1 = $rank + 1; // 1-indexed
            $scores[$chunkId] = ($scores[$chunkId] ?? 0) + (1 / ($this->rrfK + $rank1));
        }

        // Add full-text search scores (ranked by score DESC)
        foreach ($fulltextResults as $rank => $result) {
            $chunkId = $result['chunk_id'];
            $rank1 = $rank + 1; // 1-indexed
            $scores[$chunkId] = ($scores[$chunkId] ?? 0) + (1 / ($this->rrfK + $rank1));
        }

        // Sort by RRF score DESC
        arsort($scores);

        // Convert to array format
        $combined = [];
        foreach ($scores as $chunkId => $rrfScore) {
            $combined[] = [
                'chunk_id' => $chunkId,
                'rrf_score' => $rrfScore,
            ];
        }

        return $combined;
    }

    /**
     * Format passages for API response.
     *
     * @param  array  $results  Array of {chunk_id, rrf_score}
     * @return array Formatted passages
     */
    public function formatPassages(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $chunkIds = array_column($results, 'chunk_id');
        $chunks = ResourceDocumentChunk::whereIn('id', $chunkIds)
            ->with(['resourceVersion.resource'])
            ->get()
            ->keyBy('id');

        $passages = [];
        foreach ($results as $result) {
            $chunk = $chunks->get($result['chunk_id']);

            if (! $chunk) {
                continue;
            }

            $passages[] = [
                'chunk_id' => $chunk->id,
                'text' => $chunk->extracted_text,
                'page_number' => $chunk->page_number,
                'chapter' => $chunk->chapter,
                'heading' => $chunk->heading,
                'chunk_order' => $chunk->chunk_order,
                'resource_id' => $chunk->resourceVersion->resource_id,
                'resource_version_id' => $chunk->resource_version_id,
                'retrieval_metadata' => [
                    'rrf_score' => $result['rrf_score'],
                    'embedding_model' => $chunk->embedding_model,
                    'embedding_dimensions' => $chunk->embedding_dimensions,
                ],
            ];
        }

        return $passages;
    }

    /**
     * Apply token budget to retrieved passages.
     *
     * Prioritizes passages by retrieval rank (already sorted by RRF score).
     * Includes only complete passages that fit within the token budget.
     * Preserves page numbers and citation metadata.
     *
     * Token counting method: Approximate ~4 characters per token for English text.
     * Safety margin: 20% to account for overhead and approximation errors.
     *
     * @param  array  $results  Array of {chunk_id, rrf_score} sorted by RRF score DESC
     * @return array Formatted passages within token budget
     */
    protected function applyTokenBudget(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        // Apply safety margin (20%)
        $effectiveBudget = $this->maxTokens * 0.8;

        // Limit to max passages first
        $results = array_slice($results, 0, $this->maxPassages, true);

        $chunkIds = array_column($results, 'chunk_id');
        $chunks = ResourceDocumentChunk::whereIn('id', $chunkIds)
            ->with(['resourceVersion.resource'])
            ->get()
            ->keyBy('id');

        $passages = [];
        $usedTokens = 0;

        foreach ($results as $result) {
            $chunk = $chunks->get($result['chunk_id']);

            if (! $chunk) {
                continue;
            }

            // Estimate tokens for this passage (approx 4 chars per token)
            $passageText = $chunk->extracted_text;
            $estimatedTokens = ceil(strlen($passageText) / 4);

            // Check if adding this passage would exceed budget
            if ($usedTokens + $estimatedTokens > $effectiveBudget) {
                // Skip this passage to stay within budget
                continue;
            }

            $usedTokens += $estimatedTokens;

            $passages[] = [
                'chunk_id' => $chunk->id,
                'text' => $chunk->extracted_text,
                'page_number' => $chunk->page_number,
                'chapter' => $chunk->chapter,
                'heading' => $chunk->heading,
                'chunk_order' => $chunk->chunk_order,
                'resource_id' => $chunk->resourceVersion->resource_id,
                'resource_version_id' => $chunk->resource_version_id,
                'retrieval_metadata' => [
                    'rrf_score' => $result['rrf_score'],
                    'embedding_model' => $chunk->embedding_model,
                    'embedding_dimensions' => $chunk->embedding_dimensions,
                ],
            ];
        }

        return $passages;
    }

    /**
     * Return a standardized error response.
     */
    protected function errorResponse(string $message, int $httpStatus = 500): array
    {
        return [
            'success' => false,
            'passages' => [],
            'error' => $message,
            'metadata' => null,
            'http_status' => $httpStatus,
        ];
    }
}
