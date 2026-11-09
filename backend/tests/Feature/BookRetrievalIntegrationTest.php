<?php

namespace Tests\Feature;

use App\Models\Resource;
use App\Models\ResourceConversation;
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

class BookRetrievalIntegrationTest extends TestCase
{
    use DatabaseMigrations;

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
     * Test that book retrieval is called during chat for PDF resources.
     */
    public function test_book_retrieval_called_during_chat_for_pdf(): void
    {
        $mockRetrieval = Mockery::mock(BookRetrievalService::class);
        $mockRetrieval->shouldReceive('retrieve')
            ->once()
            ->andReturn([
                'success' => true,
                'passages' => [
                    [
                        'chunk_id' => 1,
                        'text' => 'Test passage',
                        'page_number' => 42,
                        'chapter' => 'Chapter 1',
                        'heading' => 'Introduction',
                    ],
                ],
                'error' => null,
                'metadata' => ['vector_count' => 1, 'fulltext_count' => 1],
            ]);

        $mockAIService = Mockery::mock(AIService::class);
        $mockAIService->shouldReceive('isConfigured')->andReturn(true);
        $mockAIService->shouldReceive('chat')
            ->once()
            ->andReturn([
                'success' => true,
                'content' => 'AI response',
                'error' => null,
                'metadata' => [],
            ]);

        $mockVisibility = Mockery::mock(ResourceVisibilityService::class);
        $mockVisibility->shouldReceive('canView')->andReturn(true);

        $this->app->instance(BookRetrievalService::class, $mockRetrieval);
        $this->app->instance(AIService::class, $mockAIService);
        $this->app->instance(ResourceVisibilityService::class, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/resources/{$resource->id}/ai/chat", [
                'message' => 'Test question',
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test that citations are preserved in message metadata.
     */
    public function test_citations_preserved_in_message_metadata(): void
    {
        $mockRetrieval = Mockery::mock(BookRetrievalService::class);
        $mockRetrieval->shouldReceive('retrieve')
            ->once()
            ->andReturn([
                'success' => true,
                'passages' => [
                    [
                        'chunk_id' => 1,
                        'text' => 'Test passage',
                        'page_number' => 42,
                        'chapter' => 'Chapter 1',
                        'heading' => 'Introduction',
                    ],
                ],
                'error' => null,
                'metadata' => [],
            ]);

        $mockAIService = Mockery::mock(AIService::class);
        $mockAIService->shouldReceive('isConfigured')->andReturn(true);
        $mockAIService->shouldReceive('chat')
            ->once()
            ->andReturn([
                'success' => true,
                'content' => 'AI response',
                'error' => null,
                'metadata' => [],
            ]);

        $mockVisibility = Mockery::mock(ResourceVisibilityService::class);
        $mockVisibility->shouldReceive('canView')->andReturn(true);

        $this->app->instance(BookRetrievalService::class, $mockRetrieval);
        $this->app->instance(AIService::class, $mockAIService);
        $this->app->instance(ResourceVisibilityService::class, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/resources/{$resource->id}/ai/chat", [
                'message' => 'Test question',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'citations' => [
                '*' => [
                    'page_number',
                    'chapter',
                    'heading',
                    'chunk_id',
                ],
            ],
        ]);

        $citations = $response->json('citations');
        $this->assertCount(1, $citations);
        $this->assertEquals(42, $citations[0]['page_number']);
        $this->assertEquals('Chapter 1', $citations[0]['chapter']);
    }

    /**
     * Test that context includes book passages.
     */
    public function test_context_includes_book_passages(): void
    {
        $mockRetrieval = Mockery::mock(BookRetrievalService::class);
        $mockRetrieval->shouldReceive('retrieve')
            ->once()
            ->andReturn([
                'success' => true,
                'passages' => [
                    [
                        'chunk_id' => 1,
                        'text' => 'Test passage',
                        'page_number' => 42,
                    ],
                ],
                'error' => null,
                'metadata' => [],
            ]);

        $mockAIService = Mockery::mock(AIService::class);
        $mockAIService->shouldReceive('isConfigured')->andReturn(true);
        $mockAIService->shouldReceive('chat')
            ->once()
            ->with(
                Mockery::on(function ($messages) {
                    return is_array($messages);
                }),
                Mockery::on(function ($context) {
                    return isset($context['book_passages']) && count($context['book_passages']) > 0;
                }),
                Mockery::type('callable')
            )
            ->andReturn([
                'success' => true,
                'content' => 'AI response',
                'error' => null,
                'metadata' => [],
            ]);

        $mockVisibility = Mockery::mock(ResourceVisibilityService::class);
        $mockVisibility->shouldReceive('canView')->andReturn(true);

        $this->app->instance(BookRetrievalService::class, $mockRetrieval);
        $this->app->instance(AIService::class, $mockAIService);
        $this->app->instance(ResourceVisibilityService::class, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/resources/{$resource->id}/ai/chat", [
                'message' => 'Test question',
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test error handling when retrieval fails.
     */
    public function test_error_handling_when_retrieval_fails(): void
    {
        $mockRetrieval = Mockery::mock(BookRetrievalService::class);
        $mockRetrieval->shouldReceive('retrieve')
            ->once()
            ->andReturn([
                'success' => false,
                'passages' => [],
                'error' => 'Retrieval failed',
                'metadata' => null,
            ]);

        $mockAIService = Mockery::mock(AIService::class);
        $mockAIService->shouldReceive('isConfigured')->andReturn(true);
        $mockAIService->shouldReceive('chat')
            ->once()
            ->andReturn([
                'success' => true,
                'content' => 'AI response without context',
                'error' => null,
                'metadata' => [],
            ]);

        $mockVisibility = Mockery::mock(ResourceVisibilityService::class);
        $mockVisibility->shouldReceive('canView')->andReturn(true);

        $this->app->instance(BookRetrievalService::class, $mockRetrieval);
        $this->app->instance(AIService::class, $mockAIService);
        $this->app->instance(ResourceVisibilityService::class, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'media_type' => 'pdf',
        ]);

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/resources/{$resource->id}/ai/chat", [
                'message' => 'Test question',
            ]);

        // Should still succeed, just without book context
        $response->assertStatus(200);
    }

    /**
     * Test that non-PDF resources don't trigger retrieval.
     */
    public function test_non_pdf_resources_dont_trigger_retrieval(): void
    {
        $mockRetrieval = Mockery::mock(BookRetrievalService::class);
        $mockRetrieval->shouldNotReceive('retrieve');

        $mockAIService = Mockery::mock(AIService::class);
        $mockAIService->shouldReceive('isConfigured')->andReturn(true);
        $mockAIService->shouldReceive('chat')
            ->once()
            ->andReturn([
                'success' => true,
                'content' => 'AI response',
                'error' => null,
                'metadata' => [],
            ]);

        $mockVisibility = Mockery::mock(ResourceVisibilityService::class);
        $mockVisibility->shouldReceive('canView')->andReturn(true);

        $this->app->instance(BookRetrievalService::class, $mockRetrieval);
        $this->app->instance(AIService::class, $mockAIService);
        $this->app->instance(ResourceVisibilityService::class, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'media_type' => 'image', // Not PDF
        ]);
        $resource->current_version_id = $version->id;
        $resource->save();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/resources/{$resource->id}/ai/chat", [
                'message' => 'Test question',
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test that citations are included in conversation message response.
     */
    public function test_citations_included_in_conversation_response(): void
    {
        $mockRetrieval = Mockery::mock(BookRetrievalService::class);
        $mockRetrieval->shouldReceive('retrieve')
            ->once()
            ->andReturn([
                'success' => true,
                'passages' => [
                    [
                        'chunk_id' => 1,
                        'text' => 'Test passage',
                        'page_number' => 42,
                        'chapter' => 'Chapter 1',
                    ],
                ],
                'error' => null,
                'metadata' => [],
            ]);

        $mockAIService = Mockery::mock(AIService::class);
        $mockAIService->shouldReceive('isConfigured')->andReturn(true);
        $mockAIService->shouldReceive('chat')
            ->once()
            ->andReturn([
                'success' => true,
                'content' => 'AI response',
                'error' => null,
                'metadata' => [],
            ]);

        $mockVisibility = Mockery::mock(ResourceVisibilityService::class);
        $mockVisibility->shouldReceive('canView')->andReturn(true);

        $this->app->instance(BookRetrievalService::class, $mockRetrieval);
        $this->app->instance(AIService::class, $mockAIService);
        $this->app->instance(ResourceVisibilityService::class, $mockVisibility);

        $user = User::factory()->create();
        $resource = Resource::factory()->create();
        $version = ResourceVersion::factory()->create([
            'resource_id' => $resource->id,
            'media_type' => 'pdf',
        ]);
        $resource->current_version_id = $version->id;
        $resource->save();

        $processing = ResourceDocumentProcessing::factory()->create([
            'resource_version_id' => $version->id,
            'status' => 'completed',
        ]);

        // Call chat first to trigger retrieval and create messages with citations
        $this->actingAs($user)
            ->postJson("/api/v1/resources/{$resource->id}/ai/chat", [
                'message' => 'Test question',
            ])
            ->assertStatus(200);

        // Then get the conversation and verify citations are included
        $conversation = ResourceConversation::where('user_id', $user->id)
            ->where('resource_id', $resource->id)
            ->first();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/ai/conversations/{$conversation->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'messages' => [
                '*' => [
                    'citations',
                ],
            ],
        ]);

        $messages = $response->json('messages');
        $assistantMessages = array_values(array_filter($messages, fn ($m) => $m['role'] === 'assistant'));
        $this->assertCount(1, $assistantMessages);
        $this->assertEquals(42, $assistantMessages[0]['citations'][0]['page_number']);
        $this->assertEquals('Chapter 1', $assistantMessages[0]['citations'][0]['chapter']);
    }
}
