<?php

namespace Tests\Feature;

use App\Models\Resource;
use App\Models\ResourceDocumentChunk;
use App\Models\ResourceDocumentProcessing;
use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\AIService;
use App\Services\BookRetrievalService;
use App\Services\ResourceVisibilityService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class BookRetrievalServiceTest extends TestCase
{
    use DatabaseMigrations;

    protected BookRetrievalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Set config for retrieval
        Config::set('services.retrieval.vector_limit', 20);
        Config::set('services.retrieval.fulltext_limit', 20);
        Config::set('services.retrieval.final_limit', 10);
        Config::set('services.retrieval.rrf_k', 60);
        Config::set('services.retrieval.min_similarity', 0.5);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a mock AIService that returns valid 768-dimensional embeddings.
     */
    protected function createMockAIService(?array $embedding = null): AIService
    {
        $mock = Mockery::mock(AIService::class);

        if ($embedding === null) {
            $embedding = array_fill(0, 768, 0.1);
        }

        $mock->shouldReceive('generateEmbeddings')
            ->andReturn([
                'success' => true,
                'embeddings' => [$embedding],
                'error' => null,
                'metadata' => ['model' => 'gemini-embedding-2', 'dimensions' => 768],
            ]);

        return $mock;
    }

    /**
     * Create a mock ResourceVisibilityService that grants access.
     */
    protected function createMockVisibilityService(bool $canView = true): ResourceVisibilityService
    {
        $mock = Mockery::mock(ResourceVisibilityService::class);
        $mock->shouldReceive('canView')->andReturn($canView);

        return $mock;
    }

    /**
     * Test that retrieval fails without resource access.
     */
    public function test_retrieval_fails_without_access(): void
    {
        $mockAIService = $this->createMockAIService();
        $mockVisibility = $this->createMockVisibilityService(false);

        $this->service = new BookRetrievalService($mockAIService, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
        ]);

        $result = $this->service->retrieve($user, $resource, $version, 'test query');

        $this->assertFalse($result['success']);
        $this->assertEquals('You do not have access to this resource', $result['error']);
        $this->assertEquals(403, $result['http_status']);
    }

    /**
     * Test that retrieval fails with empty query.
     */
    public function test_retrieval_fails_with_empty_query(): void
    {
        $mockAIService = $this->createMockAIService();
        $mockVisibility = $this->createMockVisibilityService(true);

        $this->service = new BookRetrievalService($mockAIService, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
        ]);

        $result = $this->service->retrieve($user, $resource, $version, '');

        $this->assertFalse($result['success']);
        $this->assertEquals('Query cannot be empty', $result['error']);
    }

    /**
     * Test that retrieval fails when document processing is incomplete.
     */
    public function test_retrieval_fails_when_processing_incomplete(): void
    {
        $mockAIService = $this->createMockAIService();
        $mockVisibility = $this->createMockVisibilityService(true);

        $this->service = new BookRetrievalService($mockAIService, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
        ]);

        // No processing record
        $result = $this->service->retrieve($user, $resource, $version, 'test query');

        $this->assertFalse($result['success']);
        $this->assertEquals('Document processing is not complete', $result['error']);
    }

    /**
     * Test that retrieval fails when version doesn't belong to resource.
     */
    public function test_retrieval_fails_when_version_mismatch(): void
    {
        $mockAIService = $this->createMockAIService();
        $mockVisibility = $this->createMockVisibilityService(true);

        $this->service = new BookRetrievalService($mockAIService, $mockVisibility);

        $user = User::factory()->create();
        $resource1 = Resource::factory()->create();
        $resource2 = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource2->id, // Belongs to different resource
        ]);

        $result = $this->service->retrieve($user, $resource1, $version, 'test query');

        $this->assertFalse($result['success']);
        $this->assertEquals('Version does not belong to this resource', $result['error']);
    }

    /**
     * Test that retrieval returns empty array when no chunks match.
     */
    public function test_retrieval_returns_empty_when_no_matches(): void
    {
        $mockAIService = $this->createMockAIService();
        $mockVisibility = $this->createMockVisibilityService(true);

        $this->service = new BookRetrievalService($mockAIService, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
        ]);

        // Create completed processing record
        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        // No chunks created
        $result = $this->service->retrieve($user, $resource, $version, 'test query');

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['passages']);
        $this->assertEmpty($result['passages']);
    }

    /**
     * Test that RRF combines vector and full-text results correctly.
     */
    public function test_rrf_combines_results(): void
    {
        $mockAIService = $this->createMockAIService();
        $mockVisibility = $this->createMockVisibilityService(true);

        $this->service = new BookRetrievalService($mockAIService, $mockVisibility);

        // Mock vector results
        $vectorResults = [
            ['chunk_id' => 1, 'score' => 0.9],
            ['chunk_id' => 2, 'score' => 0.8],
            ['chunk_id' => 3, 'score' => 0.7],
        ];

        // Mock full-text results (different order)
        $fulltextResults = [
            ['chunk_id' => 2, 'score' => 0.95],
            ['chunk_id' => 1, 'score' => 0.85],
            ['chunk_id' => 4, 'score' => 0.75],
        ];

        $combined = $this->service->reciprocalRankFusion($vectorResults, $fulltextResults);

        // Chunk 2 appears in both results (rank 2 in vector, rank 1 in full-text)
        // Chunk 1 appears in both results (rank 1 in vector, rank 2 in full-text)
        // Chunk 3 appears only in vector (rank 3)
        // Chunk 4 appears only in full-text (rank 3)

        // Chunk 1 and chunk 2 have identical RRF scores (rank 1+2 = rank 2+1),
        // so their relative order is non-deterministic. Both should be in top 2.
        $topTwo = array_column(array_slice($combined, 0, 2), 'chunk_id');
        $this->assertContains(1, $topTwo);
        $this->assertContains(2, $topTwo);

        // Chunks 3 and 4 should have lower scores
        $this->assertContains(3, array_column($combined, 'chunk_id'));
        $this->assertContains(4, array_column($combined, 'chunk_id'));
    }

    /**
     * Test that passage formatting includes all required fields.
     */
    public function test_passage_formatting_includes_required_fields(): void
    {
        $mockAIService = $this->createMockAIService();
        $mockVisibility = $this->createMockVisibilityService(true);

        $this->service = new BookRetrievalService($mockAIService, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        $chunk = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version->id,
            'processing_id' => $processing->id,
            'extracted_text' => 'Test passage text',
            'page_number' => 42,
            'chapter' => 'Chapter 1',
            'heading' => 'Introduction',
            'chunk_order' => 1,
            'embedding' => array_fill(0, 768, 0.1),
            'embedding_model' => 'gemini-embedding-2',
            'embedding_dimensions' => 768,
        ]);

        $results = [
            ['chunk_id' => $chunk->id, 'rrf_score' => 0.95],
        ];

        $passages = $this->service->formatPassages($results);

        $this->assertCount(1, $passages);
        $this->assertEquals($chunk->id, $passages[0]['chunk_id']);
        $this->assertEquals('Test passage text', $passages[0]['text']);
        $this->assertEquals(42, $passages[0]['page_number']);
        $this->assertEquals('Chapter 1', $passages[0]['chapter']);
        $this->assertEquals('Introduction', $passages[0]['heading']);
        $this->assertEquals(1, $passages[0]['chunk_order']);
        $this->assertEquals($resource->id, $passages[0]['resource_id']);
        $this->assertEquals($version->id, $passages[0]['resource_version_id']);
        $this->assertArrayHasKey('retrieval_metadata', $passages[0]);
        $this->assertEquals(0.95, $passages[0]['retrieval_metadata']['rrf_score']);
    }

    /**
     * Test that retrieval respects resource version isolation.
     */
    public function test_retrieval_respects_version_isolation(): void
    {
        $mockAIService = $this->createMockAIService();
        $mockVisibility = $this->createMockVisibilityService(true);

        $this->service = new BookRetrievalService($mockAIService, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();

        // Create two versions
        $version1 = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
        ]);

        $version2 = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
        ]);

        // Create processing for version 1
        $processing1 = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version1->id,
            'status' => 'completed',
        ]);

        // Create chunks for version 1
        $chunk1 = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version1->id,
            'processing_id' => $processing1->id,
            'extracted_text' => 'Version 1 text',
            'embedding' => array_fill(0, 768, 0.1),
        ]);

        // Create chunks for version 2 (should not be retrieved)
        $processing2 = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version2->id,
            'status' => 'completed',
        ]);

        $chunk2 = ResourceDocumentChunk::factory()->create([
            'resource_version_id' => $version2->id,
            'processing_id' => $processing2->id,
            'extracted_text' => 'Version 2 text',
            'embedding' => array_fill(0, 768, 0.2),
        ]);

        // Query version 1 - should only return version 1 chunks
        $result = $this->service->retrieve($user, $resource, $version1, 'test query');

        // Verify only version 1 chunks are returned
        foreach ($result['passages'] as $passage) {
            $this->assertEquals($version1->id, $passage['resource_version_id']);
        }
    }
}
