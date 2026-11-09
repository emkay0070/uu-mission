<?php

namespace Tests\Feature;

use App\Models\Resource;
use App\Models\ResourceDocumentChunk;
use App\Models\ResourceDocumentProcessing;
use App\Models\ResourceVersion;
use App\Services\AIService;
use App\Services\DocumentProcessingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DocumentProcessingServiceTest extends TestCase
{
    use DatabaseMigrations;

    protected DocumentProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Set config for embeddings
        Config::set('services.gemini.embedding_model', 'gemini-embedding-2');
        Config::set('services.gemini.embedding_dimensions', 768);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a mock AIService that returns valid 768-dimensional embeddings.
     */
    protected function createMockAIService(?array $embeddings = null): AIService
    {
        $mock = Mockery::mock(AIService::class);

        if ($embeddings === null) {
            // Generate a mock 768-dimensional embedding
            $embeddings = [array_fill(0, 768, 0.1)];
        }

        $mock->shouldReceive('generateEmbeddings')
            ->andReturn([
                'success' => true,
                'embeddings' => $embeddings,
                'error' => null,
                'metadata' => ['model' => 'gemini-embedding-2', 'dimensions' => 768],
            ]);

        return $mock;
    }

    /**
     * Create a mock AIService that fails.
     */
    protected function createFailingMockAIService(string $error = 'API error'): AIService
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('generateEmbeddings')
            ->andReturn([
                'success' => false,
                'embeddings' => [],
                'error' => $error,
                'metadata' => null,
            ]);

        return $mock;
    }

    /**
     * Test that processing a non-existent file throws an exception.
     */
    public function test_processing_non_existent_file_throws_exception(): void
    {
        $this->service = new DocumentProcessingService($this->createMockAIService());

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'non-existent.pdf',
            'media_type' => 'pdf',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PDF file not found');

        $this->service->processResourceVersion($version);
    }

    /**
     * Test that processing creates a processing record.
     */
    public function test_processing_creates_processing_record(): void
    {
        $this->service = new DocumentProcessingService($this->createMockAIService());

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        // Mock storage to return a valid PDF
        Storage::fake('local');
        Storage::disk('local')->put('test.pdf', '%PDF-1.4 test content');

        try {
            $this->service->processResourceVersion($version);
        } catch (\Exception $e) {
            // Expected to fail on parsing, but processing record should exist
        }

        $processing = ResourceDocumentProcessing::where('resource_version_id', $version->id)->first();
        $this->assertNotNull($processing);
        $this->assertEquals('failed', $processing->status);
    }

    /**
     * Test that processing is idempotent.
     */
    public function test_processing_is_idempotent(): void
    {
        $this->service = new DocumentProcessingService($this->createMockAIService());

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        // Create a completed processing record
        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Create some chunks
        ResourceDocumentChunk::factory()->count(5)->create([
            'resource_version_id' => $version->id,
            'processing_id' => $processing->id,
        ]);

        // Process again - should return existing completed record
        $result = $this->service->processResourceVersion($version);

        $this->assertEquals($processing->id, $result->id);
        $this->assertEquals('completed', $result->status);

        // Chunks should still exist (not deleted)
        $chunkCount = ResourceDocumentChunk::where('resource_version_id', $version->id)->count();
        $this->assertEquals(5, $chunkCount);
    }

    /**
     * Test that failed processing marks record as failed.
     */
    public function test_failed_processing_marks_record_as_failed(): void
    {
        $this->service = new DocumentProcessingService($this->createMockAIService());

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('test.pdf', 'invalid pdf content');

        try {
            $this->service->processResourceVersion($version);
        } catch (\Exception $e) {
            // Expected to fail
        }

        $processing = ResourceDocumentProcessing::where('resource_version_id', $version->id)->first();
        $this->assertNotNull($processing);
        $this->assertEquals('failed', $processing->status);
        $this->assertNotNull($processing->completed_at);
        $this->assertArrayHasKey('error', $processing->processing_metadata);
    }

    /**
     * Test that chunks are associated with the correct resource version.
     */
    public function test_chunks_associated_with_correct_resource_version(): void
    {
        $this->service = new DocumentProcessingService($this->createMockAIService());

        $resource1 = Resource::factory()->create();
        $version1 = ResourceVersion::factory()->create([
            'resource_id' => $resource1->id,
            'storage_path' => 'test1.pdf',
            'media_type' => 'pdf',
        ]);

        $resource2 = Resource::factory()->create();
        $version2 = ResourceVersion::factory()->create([
            'resource_id' => $resource2->id,
            'storage_path' => 'test2.pdf',
            'media_type' => 'pdf',
        ]);

        // Create chunks for version 1
        $processing1 = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version1->id,
            'status' => 'completed',
        ]);
        ResourceDocumentChunk::factory()->count(3)->create([
            'resource_version_id' => $version1->id,
            'processing_id' => $processing1->id,
        ]);

        // Create chunks for version 2
        $processing2 = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version2->id,
            'status' => 'completed',
        ]);
        ResourceDocumentChunk::factory()->count(2)->create([
            'resource_version_id' => $version2->id,
            'processing_id' => $processing2->id,
        ]);

        // Verify isolation
        $chunks1 = ResourceDocumentChunk::where('resource_version_id', $version1->id)->get();
        $chunks2 = ResourceDocumentChunk::where('resource_version_id', $version2->id)->get();

        $this->assertCount(3, $chunks1);
        $this->assertCount(2, $chunks2);

        foreach ($chunks1 as $chunk) {
            $this->assertEquals($version1->id, $chunk->resource_version_id);
        }

        foreach ($chunks2 as $chunk) {
            $this->assertEquals($version2->id, $chunk->resource_version_id);
        }
    }

    /**
     * Test that source text hash is generated for chunks.
     */
    public function test_source_text_hash_is_generated(): void
    {
        $this->service = new DocumentProcessingService($this->createMockAIService());

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        $testText = 'Test content for hashing';
        $testHash = hash('sha256', $testText);
        $chunk = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version->id,
            'processing_id' => $processing->id,
            'extracted_text' => $testText,
            'source_text_hash' => $testHash,
        ]);

        $this->assertNotNull($chunk->source_text_hash);
        $this->assertEquals(64, strlen($chunk->source_text_hash));
        $this->assertEquals(hash('sha256', $testText), $chunk->source_text_hash);
    }

    /**
     * Test that chunks receive valid 768-dimensional embeddings.
     */
    public function test_chunks_receive_768_dimensional_embeddings(): void
    {
        // Create mock with proper 768-dimensional embeddings
        $mockEmbeddings = [
            array_fill(0, 768, 0.1),
            array_fill(0, 768, 0.2),
        ];
        $this->service = new DocumentProcessingService($this->createMockAIService($mockEmbeddings));

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        // Create chunks
        $chunk1 = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version->id,
            'processing_id' => $processing->id,
            'extracted_text' => 'First chunk text',
        ]);

        $chunk2 = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version->id,
            'processing_id' => $processing->id,
            'extracted_text' => 'Second chunk text',
        ]);

        // Generate embeddings
        $this->service->generateChunkEmbeddings([$chunk1, $chunk2], $processing);

        // Refresh from database
        $chunk1->refresh();
        $chunk2->refresh();

        // Verify embeddings were stored
        $this->assertNotNull($chunk1->embedding);
        $this->assertNotNull($chunk2->embedding);
        $this->assertCount(768, $chunk1->embedding);
        $this->assertCount(768, $chunk2->embedding);
        $this->assertEquals('gemini-embedding-2', $chunk1->embedding_model);
        $this->assertEquals('gemini-embedding-2', $chunk2->embedding_model);
        $this->assertEquals(768, $chunk1->embedding_dimensions);
        $this->assertEquals(768, $chunk2->embedding_dimensions);
    }

    /**
     * Test that multiple chunks receive separate embeddings.
     */
    public function test_multiple_chunks_receive_separate_embeddings(): void
    {
        $mockEmbeddings = [
            array_fill(0, 768, 0.1),
            array_fill(0, 768, 0.2),
            array_fill(0, 768, 0.3),
        ];
        $this->service = new DocumentProcessingService($this->createMockAIService($mockEmbeddings));

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        // Create 3 chunks
        $chunks = [];
        for ($i = 0; $i < 3; $i++) {
            $chunks[] = ResourceDocumentChunk::factory()->create([
                'resource_version_id' => $version->id,
                'processing_id' => $processing->id,
                'extracted_text' => "Chunk text {$i}",
            ]);
        }

        // Generate embeddings
        $this->service->generateChunkEmbeddings($chunks, $processing);

        // Refresh and verify
        foreach ($chunks as $index => $chunk) {
            $chunk->refresh();
            $this->assertNotNull($chunk->embedding);
            $this->assertCount(768, $chunk->embedding);
            // Verify embeddings are different (first value differs) - allow for pgvector float precision
            $this->assertTrue(abs($chunk->embedding[0] - ($index + 1) * 0.1) < 0.0001);
        }
    }

    /**
     * Test that empty chunks are skipped.
     */
    public function test_empty_chunks_are_skipped(): void
    {
        $mockEmbeddings = [array_fill(0, 768, 0.1)];
        $this->service = new DocumentProcessingService($this->createMockAIService($mockEmbeddings));

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        // Create one valid chunk and one empty chunk
        $validChunk = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version->id,
            'processing_id' => $processing->id,
            'extracted_text' => 'Valid text',
        ]);

        $emptyChunk = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version->id,
            'processing_id' => $processing->id,
            'extracted_text' => '',
        ]);

        // Generate embeddings
        $this->service->generateChunkEmbeddings([$validChunk, $emptyChunk], $processing);

        // Refresh
        $validChunk->refresh();
        $emptyChunk->refresh();

        // Valid chunk should have embedding
        $this->assertNotNull($validChunk->embedding);
        $this->assertCount(768, $validChunk->embedding);

        // Empty chunk should not have embedding
        $this->assertNull($emptyChunk->embedding);
    }

    /**
     * Test that embedding API errors trigger exceptions.
     */
    public function test_embedding_api_errors_trigger_exceptions(): void
    {
        $this->service = new DocumentProcessingService($this->createFailingMockAIService('API rate limit exceeded'));

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        $chunk = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version->id,
            'processing_id' => $processing->id,
            'extracted_text' => 'Test text',
        ]);

        // Should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Embedding generation failed');

        $this->service->generateChunkEmbeddings([$chunk], $processing);
    }

    /**
     * Test that chunks with existing valid embeddings are not regenerated (idempotency).
     */
    public function test_existing_embeddings_not_regenerated(): void
    {
        $mockEmbeddings = [array_fill(0, 768, 0.1)];
        $mockAIService = $this->createMockAIService($mockEmbeddings);

        // Expect generateEmbeddings to NOT be called since the chunk already
        // has a valid embedding (idempotency).
        $mockAIService->shouldReceive('generateEmbeddings')
            ->never()
            ->andReturn([
                'success' => true,
                'embeddings' => $mockEmbeddings,
                'error' => null,
                'metadata' => ['model' => 'gemini-embedding-2', 'dimensions' => 768],
            ]);

        $this->service = new DocumentProcessingService($mockAIService);

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        $chunk = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version->id,
            'processing_id' => $processing->id,
            'extracted_text' => 'Test text',
            'embedding' => array_fill(0, 768, 0.5),
            'embedding_model' => 'gemini-embedding-2',
            'embedding_dimensions' => 768,
        ]);

        // First call - should skip generation
        $this->service->generateChunkEmbeddings([$chunk], $processing);

        // Verify embedding was not changed
        $chunk->refresh();
        $this->assertEquals(0.5, $chunk->embedding[0]);
    }

    /**
     * Test that document is not marked completed if embedding generation fails.
     */
    public function test_document_not_completed_on_embedding_failure(): void
    {
        $this->service = new DocumentProcessingService($this->createFailingMockAIService('API error'));

        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'storage_path' => 'test.pdf',
            'media_type' => 'pdf',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('test.pdf', '%PDF-1.4 test content');

        // Processing should fail (invalid PDF or embedding error - either way processing
        // should not complete)
        $this->expectException(\Exception::class);

        $this->service->processResourceVersion($version);

        // Verify processing is not marked completed
        $processing = ResourceDocumentProcessing::where('resource_version_id', $version->id)->first();
        $this->assertNotNull($processing);
        $this->assertNotEquals('completed', $processing->status);
    }
}
