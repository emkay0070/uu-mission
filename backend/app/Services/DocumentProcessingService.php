<?php

namespace App\Services;

use App\Models\ResourceDocumentChunk;
use App\Models\ResourceDocumentProcessing;
use App\Models\ResourceVersion;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * DocumentProcessingService — PDF text extraction and chunking.
 *
 * Handles page-by-page text extraction, chapter/heading detection,
 * and semantic chunking for document processing.
 */
class DocumentProcessingService
{
    protected PdfParser $pdfParser;

    protected AIService $aiService;

    // Chunking configuration
    protected int $minChunkTokens = 500;

    protected int $maxChunkTokens = 800;

    protected int $chunkOverlapTokens = 100;

    // Embedding configuration
    protected string $embeddingModel;

    protected int $embeddingDimensions;

    public function __construct(AIService $aiService)
    {
        $this->pdfParser = new PdfParser;
        $this->aiService = $aiService;
        $this->embeddingModel = config('services.gemini.embedding_model', 'gemini-embedding-2');
        $this->embeddingDimensions = config('services.gemini.embedding_dimensions', 768);
    }

    /**
     * Process a resource version: extract text and create chunks.
     *
     * @throws Exception
     */
    public function processResourceVersion(ResourceVersion $resourceVersion): ResourceDocumentProcessing
    {
        // Create or update processing record
        $processing = ResourceDocumentProcessing::firstOrCreate(
            ['resource_version_id' => $resourceVersion->id],
            [
                'status' => 'pending',
                'total_pages' => null,
                'extracted_pages' => 0,
                'processing_metadata' => [],
                'started_at' => null,
                'completed_at' => null,
            ]
        );

        // If already completed, return existing record
        if ($processing->isCompleted()) {
            return $processing;
        }

        // Mark as processing
        $processing->update([
            'status' => 'processing',
            'started_at' => now(),
            'processing_metadata' => array_merge($processing->processing_metadata ?? [], [
                'extraction_method' => 'smalot/pdfparser',
                'started_at' => now()->toIso8601String(),
            ]),
        ]);

        try {
            // Get the PDF file path
            $filePath = $resourceVersion->storage_path;
            if (! Storage::disk($resourceVersion->storage_disk)->exists($filePath)) {
                throw new Exception("PDF file not found: {$filePath}");
            }

            // Read PDF content
            $fileContent = Storage::disk($resourceVersion->storage_disk)->get($filePath);
            $pdf = $this->pdfParser->parseContent($fileContent);

            // Extract pages
            $pages = $pdf->getPages();
            $totalPages = count($pages);

            if ($totalPages === 0) {
                throw new Exception('PDF has no pages');
            }

            // Update total pages
            $processing->update(['total_pages' => $totalPages]);

            // Extract text from each page
            $extractedPages = [];
            $emptyPages = 0;

            foreach ($pages as $pageIndex => $page) {
                $pageNumber = $pageIndex + 1; // PDF pages are 1-indexed
                $text = $page->getText();

                // Clean text
                $text = $this->cleanText($text);

                if (empty(trim($text))) {
                    $emptyPages++;

                    continue;
                }

                $extractedPages[] = [
                    'page_number' => $pageNumber,
                    'text' => $text,
                ];
            }

            // Check if PDF has no extractable text (likely scanned/image-only)
            if ($emptyPages === $totalPages) {
                $processing->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'processing_metadata' => array_merge($processing->processing_metadata, [
                        'error' => 'no_extractable_text',
                        'error_message' => 'PDF contains no extractable text. OCR is required but not yet supported.',
                        'ocr_required' => true,
                    ]),
                ]);

                return $processing;
            }

            // Detect chapters and headings
            $chapters = $this->detectChapters($extractedPages);

            // Chunk the text
            $chunks = $this->chunkText($extractedPages, $chapters);

            // Delete existing chunks for this resource version (idempotent)
            ResourceDocumentChunk::where('resource_version_id', $resourceVersion->id)->delete();

            // Store chunks
            $chunkModels = [];
            DB::transaction(function () use ($resourceVersion, $processing, $chunks, &$chunkModels) {
                foreach ($chunks as $chunkData) {
                    $chunk = ResourceDocumentChunk::create([
                        'resource_version_id' => $resourceVersion->id,
                        'processing_id' => $processing->id,
                        'page_number' => $chunkData['page_number'],
                        'chapter' => $chunkData['chapter'] ?? null,
                        'heading' => $chunkData['heading'] ?? null,
                        'chunk_order' => $chunkData['chunk_order'],
                        'extracted_text' => $chunkData['text'],
                        'position_data' => $chunkData['position_data'] ?? null,
                        'source_text_hash' => hash('sha256', $chunkData['text']),
                    ]);
                    $chunkModels[] = $chunk;
                }
            });

            // Generate embeddings for chunks
            $this->generateChunkEmbeddings($chunkModels, $processing);

            // Mark as completed
            $processing->update([
                'status' => 'completed',
                'extracted_pages' => count($extractedPages),
                'completed_at' => now(),
                'processing_metadata' => array_merge($processing->processing_metadata, [
                    'total_chunks' => count($chunks),
                    'empty_pages' => $emptyPages,
                    'completed_at' => now()->toIso8601String(),
                ]),
            ]);

            return $processing;

        } catch (Exception $e) {
            // Mark as failed
            $processing->update([
                'status' => 'failed',
                'completed_at' => now(),
                'processing_metadata' => array_merge($processing->processing_metadata ?? [], [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'failed_at' => now()->toIso8601String(),
                ]),
            ]);

            throw $e;
        }
    }

    /**
     * Clean extracted text.
     */
    protected function cleanText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Remove control characters except newlines
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Detect chapters and headings from pages.
     *
     * Uses heuristics to identify chapter boundaries and headings.
     */
    protected function detectChapters(array $pages): array
    {
        $chapters = [];
        $currentChapter = null;
        $currentHeading = null;

        foreach ($pages as $pageIndex => $page) {
            $text = $page['text'];
            $lines = explode("\n", $text);

            foreach ($lines as $line) {
                $line = trim($line);

                // Skip empty lines
                if (empty($line)) {
                    continue;
                }

                // Chapter detection: "Chapter X", "CHAPTER X", etc.
                if (preg_match('/^(chapter|chap\.?\s*\d+)/i', $line)) {
                    $currentChapter = $line;
                    $chapters[$pageIndex] = [
                        'type' => 'chapter',
                        'title' => $line,
                        'page_number' => $page['page_number'],
                    ];
                }

                // Heading detection: all caps or title case with specific patterns
                // This is a simple heuristic; can be enhanced
                if ($this->isLikelyHeading($line)) {
                    $currentHeading = $line;
                }
            }

            // Store current heading for this page
            if ($currentHeading) {
                $pages[$pageIndex]['heading'] = $currentHeading;
            }
            if ($currentChapter) {
                $pages[$pageIndex]['chapter'] = $currentChapter;
            }
        }

        return $chapters;
    }

    /**
     * Check if a line is likely a heading.
     */
    protected function isLikelyHeading(string $line): bool
    {
        // All caps and short
        if (strtoupper($line) === $line && strlen($line) < 100 && ! preg_match('/[.!?]$/', $line)) {
            return true;
        }

        // Title case with numbers (e.g., "1. Introduction")
        if (preg_match('/^\d+\.\s+[A-Z]/', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Chunk text into semantic units.
     *
     * Creates chunks of approximately 500-800 tokens with overlap.
     * Preserves page, chapter, and heading context.
     */
    protected function chunkText(array $pages, array $chapters): array
    {
        $chunks = [];
        $currentChunk = '';
        $currentChunkOrder = 0;
        $currentPageNumber = null;
        $currentChapter = null;
        $currentHeading = null;

        foreach ($pages as $pageIndex => $page) {
            $text = $page['text'];
            $pageNumber = $page['page_number'];
            $chapter = $page['chapter'] ?? null;
            $heading = $page['heading'] ?? null;

            // Update context
            if ($chapter) {
                $currentChapter = $chapter;
            }
            if ($heading) {
                $currentHeading = $heading;
            }

            // Estimate tokens (rough approximation: 1 token ≈ 4 characters)
            $textTokens = $this->estimateTokens($text);
            $currentChunkTokens = $this->estimateTokens($currentChunk);

            // If adding this text would exceed max chunk size, save current chunk
            if ($currentChunkTokens + $textTokens > $this->maxChunkTokens && ! empty($currentChunk)) {
                $chunks[] = [
                    'text' => trim($currentChunk),
                    'page_number' => $currentPageNumber,
                    'chapter' => $currentChapter,
                    'heading' => $currentHeading,
                    'chunk_order' => $currentChunkOrder++,
                    'position_data' => null,
                ];

                // Start new chunk with overlap
                $overlapText = $this->getOverlapText($currentChunk);
                $currentChunk = $overlapText.' '.$text;
            } else {
                // Add to current chunk
                if (empty($currentChunk)) {
                    $currentChunk = $text;
                    $currentPageNumber = $pageNumber;
                } else {
                    $currentChunk .= ' '.$text;
                }
            }

            // If current chunk reaches minimum size, consider saving it
            if ($this->estimateTokens($currentChunk) >= $this->maxChunkTokens) {
                $chunks[] = [
                    'text' => trim($currentChunk),
                    'page_number' => $currentPageNumber,
                    'chapter' => $currentChapter,
                    'heading' => $currentHeading,
                    'chunk_order' => $currentChunkOrder++,
                    'position_data' => null,
                ];

                // Start new chunk with overlap
                $overlapText = $this->getOverlapText($currentChunk);
                $currentChunk = $overlapText;
            }
        }

        // Save remaining chunk
        if (! empty(trim($currentChunk))) {
            $chunks[] = [
                'text' => trim($currentChunk),
                'page_number' => $currentPageNumber,
                'chapter' => $currentChapter,
                'heading' => $currentHeading,
                'chunk_order' => $currentChunkOrder,
                'position_data' => null,
            ];
        }

        return $chunks;
    }

    /**
     * Estimate token count from text (rough approximation).
     */
    protected function estimateTokens(string $text): int
    {
        // Rough approximation: 1 token ≈ 4 characters for English text
        return (int) (strlen($text) / 4);
    }

    /**
     * Get overlap text from the end of a chunk.
     */
    protected function getOverlapText(string $text): string
    {
        $words = explode(' ', $text);
        $overlapWords = array_slice($words, -$this->chunkOverlapTokens);

        return implode(' ', $overlapWords);
    }

    /**
     * Generate embeddings for document chunks.
     *
     * @param  array  $chunkModels  Array of ResourceDocumentChunk models
     * @param  ResourceDocumentProcessing  $processing  The processing record
     *
     * @throws Exception
     */
    public function generateChunkEmbeddings(array $chunkModels, ResourceDocumentProcessing $processing): void
    {
        if (empty($chunkModels)) {
            Log::warning('No chunks to generate embeddings for', [
                'resource_version_id' => $processing->resource_version_id,
            ]);

            return;
        }

        // Update processing metadata to indicate embedding generation started
        $processing->update([
            'processing_metadata' => array_merge($processing->processing_metadata ?? [], [
                'embedding_generation_started' => now()->toIso8601String(),
                'embedding_model' => $this->embeddingModel,
                'embedding_dimensions' => $this->embeddingDimensions,
            ]),
        ]);

        // Filter out chunks that already have valid embeddings (idempotency)
        $chunksNeedingEmbedding = array_filter($chunkModels, function ($chunk) {
            return empty($chunk->embedding) ||
                   $chunk->embedding_model !== $this->embeddingModel ||
                   $chunk->embedding_dimensions !== $this->embeddingDimensions;
        });

        if (empty($chunksNeedingEmbedding)) {
            Log::info('All chunks already have valid embeddings', [
                'resource_version_id' => $processing->resource_version_id,
                'total_chunks' => count($chunkModels),
            ]);

            return;
        }

        // Extract texts from chunks that need embeddings
        $texts = array_map(fn ($chunk) => $chunk->extracted_text, $chunksNeedingEmbedding);

        // Filter out empty texts
        $validChunks = [];
        $validTexts = [];
        foreach ($chunksNeedingEmbedding as $index => $chunk) {
            if (! empty(trim($chunk->extracted_text))) {
                $validChunks[] = $chunk;
                $validTexts[] = $chunk->extracted_text;
            } else {
                Log::warning('Skipping empty chunk for embedding generation', [
                    'chunk_id' => $chunk->id,
                    'resource_version_id' => $processing->resource_version_id,
                ]);
            }
        }

        if (empty($validTexts)) {
            Log::warning('No valid texts for embedding generation', [
                'resource_version_id' => $processing->resource_version_id,
            ]);

            return;
        }

        // Generate embeddings in batches
        try {
            $result = $this->aiService->generateEmbeddings($validTexts);

            if (! $result['success']) {
                $error = $result['error'] ?? 'Unknown error';
                Log::error('Embedding generation failed', [
                    'resource_version_id' => $processing->resource_version_id,
                    'error' => $error,
                ]);
                throw new Exception("Embedding generation failed: {$error}");
            }

            $embeddings = $result['embeddings'];

            if (count($embeddings) !== count($validChunks)) {
                Log::error('Embedding count mismatch', [
                    'expected' => count($validChunks),
                    'actual' => count($embeddings),
                    'resource_version_id' => $processing->resource_version_id,
                ]);
                throw new Exception('Embedding count mismatch from API');
            }

            // Store embeddings
            DB::transaction(function () use ($validChunks, $embeddings, $processing) {
                foreach ($validChunks as $index => $chunk) {
                    $embedding = $embeddings[$index];

                    // Convert to pgvector format (array of floats)
                    $chunk->update([
                        'embedding' => $embedding,
                        'embedding_model' => $this->embeddingModel,
                        'embedding_dimensions' => $this->embeddingDimensions,
                        'embedding_version' => '1.0',
                    ]);
                }

                // Update processing metadata
                $processing->update([
                    'processing_metadata' => array_merge($processing->processing_metadata ?? [], [
                        'embedding_generation_completed' => now()->toIso8601String(),
                        'embeddings_generated' => count($validChunks),
                        'embedding_metadata' => $result['metadata'] ?? null,
                    ]),
                ]);
            });

            Log::info('Embeddings generated successfully', [
                'resource_version_id' => $processing->resource_version_id,
                'count' => count($validChunks),
                'model' => $this->embeddingModel,
                'dimensions' => $this->embeddingDimensions,
            ]);

        } catch (Exception $e) {
            // Update processing metadata with error
            $processing->update([
                'processing_metadata' => array_merge($processing->processing_metadata ?? [], [
                    'embedding_generation_failed' => now()->toIso8601String(),
                    'embedding_error' => $e->getMessage(),
                ]),
            ]);

            throw $e;
        }
    }
}
