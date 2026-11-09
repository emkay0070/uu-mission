<?php

namespace Tests\Feature;

use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ResourceConversation;
use App\Models\ResourceConversationMessage;
use App\Models\ResourceLearningNote;
use App\Models\ResourceSavedExplanation;
use App\Models\ResourceVersion;
use App\Models\User;
use App\Services\ResourceVisibilityService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceLearningNotebookTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private User $userB;

    private Resource $resourceA;

    private ResourceVersion $versionA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        $category = ResourceCategory::first() ?? ResourceCategory::factory()->create();
        $this->resourceA = Resource::factory()->create([
            'category_id' => $category->id,
            'status' => 'published',
        ]);

        $this->versionA = ResourceVersion::factory()->create([
            'resource_id' => $this->resourceA->id,
            'storage_path' => 'test.pdf',
            'file_size' => 1000,
        ]);

        $this->resourceA->current_version_id = $this->versionA->id;
        $this->resourceA->save();

        // Mock visibility service to grant access
        $this->mock(ResourceVisibilityService::class, function ($mock) {
            $mock->shouldReceive('canView')->andReturn(true);
            $mock->shouldReceive('apply')->andReturnUsing(fn ($q) => $q);
        });
    }

    private function createConversation(User $user, Resource $resource, ResourceVersion $version): ResourceConversation
    {
        return ResourceConversation::create([
            'user_id' => $user->id,
            'resource_id' => $resource->id,
            'resource_version_id' => $version->id,
            'title' => 'Test Conversation',
        ]);
    }

    private function createAssistantMessage(ResourceConversation $conversation): ResourceConversationMessage
    {
        return ResourceConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'This is an AI explanation',
            'status' => 'completed',
        ]);
    }

    // ── Saved Explanation Ownership Tests ─────────────────────────────

    public function test_user_can_save_their_own_explanation(): void
    {
        $conversation = $this->createConversation($this->userA, $this->resourceA, $this->versionA);
        $message = $this->createAssistantMessage($conversation);

        $this->actingAs($this->userA)
            ->post('/api/v1/ai/saved-explanations', [
                'conversation_message_id' => $message->id,
                'title' => 'My Saved Explanation',
                'content' => 'The explanation content',
                'page_number' => 10,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('resource_saved_explanations', [
            'user_id' => $this->userA->id,
            'conversation_message_id' => $message->id,
            'title' => 'My Saved Explanation',
        ]);
    }

    public function test_user_cannot_save_explanation_from_another_users_conversation(): void
    {
        $conversation = $this->createConversation($this->userB, $this->resourceA, $this->versionA);
        $message = $this->createAssistantMessage($conversation);

        $this->actingAs($this->userA)
            ->post('/api/v1/ai/saved-explanations', [
                'conversation_message_id' => $message->id,
                'title' => 'My Saved Explanation',
                'content' => 'The explanation content',
            ])
            ->assertStatus(404);
    }

    public function test_user_cannot_save_same_explanation_twice(): void
    {
        $conversation = $this->createConversation($this->userA, $this->resourceA, $this->versionA);
        $message = $this->createAssistantMessage($conversation);

        ResourceSavedExplanation::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'conversation_message_id' => $message->id,
            'title' => 'First Save',
            'content' => 'Content',
        ]);

        $this->actingAs($this->userA)
            ->post('/api/v1/ai/saved-explanations', [
                'conversation_message_id' => $message->id,
                'title' => 'Second Save',
                'content' => 'Content',
            ])
            ->assertStatus(400);
    }

    public function test_user_can_only_see_their_own_saved_explanations(): void
    {
        $conversationA = $this->createConversation($this->userA, $this->resourceA, $this->versionA);
        $messageA = $this->createAssistantMessage($conversationA);

        $conversationB = $this->createConversation($this->userB, $this->resourceA, $this->versionA);
        $messageB = $this->createAssistantMessage($conversationB);

        ResourceSavedExplanation::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'conversation_message_id' => $messageA->id,
            'title' => 'User A Explanation',
            'content' => 'Content A',
        ]);

        ResourceSavedExplanation::create([
            'user_id' => $this->userB->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'conversation_message_id' => $messageB->id,
            'title' => 'User B Explanation',
            'content' => 'Content B',
        ]);

        $response = $this->actingAs($this->userA)
            ->get('/api/v1/ai/saved-explanations')
            ->assertStatus(200);

        $explanations = $response->json('saved_explanations');
        $this->assertCount(1, $explanations);
        $this->assertEquals('User A Explanation', $explanations[0]['title']);
    }

    // ── Learning Note Ownership Tests ─────────────────────────────────

    public function test_user_can_create_learning_note(): void
    {
        $this->actingAs($this->userA)
            ->post("/api/v1/resources/{$this->resourceA->id}/learning-notes", [
                'note_type' => 'understanding',
                'title' => 'My Understanding',
                'content' => 'In my own words...',
                'page_number' => 5,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('resource_learning_notes', [
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'note_type' => 'understanding',
            'title' => 'My Understanding',
        ]);
    }

    public function test_user_can_create_all_note_types(): void
    {
        $noteTypes = ['understanding', 'reflection', 'chapter_summary', 'book_summary', 'important_passage', 'question'];

        foreach ($noteTypes as $type) {
            $this->actingAs($this->userA)
                ->post("/api/v1/resources/{$this->resourceA->id}/learning-notes", [
                    'note_type' => $type,
                    'title' => ucfirst(str_replace('_', ' ', $type)),
                    'content' => 'Content for '.$type,
                ])
                ->assertStatus(201);
        }

        $this->assertDatabaseCount('resource_learning_notes', 6);
    }

    public function test_user_can_only_see_their_own_learning_notes(): void
    {
        ResourceLearningNote::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'understanding',
            'title' => 'User A Note',
            'content' => 'Content A',
        ]);

        ResourceLearningNote::create([
            'user_id' => $this->userB->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'understanding',
            'title' => 'User B Note',
            'content' => 'Content B',
        ]);

        $response = $this->actingAs($this->userA)
            ->get("/api/v1/resources/{$this->resourceA->id}/learning-notes")
            ->assertStatus(200);

        $notes = $response->json('learning_notes');
        $this->assertCount(1, $notes);
        $this->assertEquals('User A Note', $notes[0]['title']);
    }

    // ── Learning Note Edit Tests ───────────────────────────────────────

    public function test_user_can_update_their_own_learning_note(): void
    {
        $note = ResourceLearningNote::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'understanding',
            'title' => 'Original Title',
            'content' => 'Original content',
        ]);

        $this->actingAs($this->userA)
            ->put("/api/v1/resources/{$this->resourceA->id}/learning-notes/{$note->id}", [
                'title' => 'Updated Title',
                'content' => 'Updated content',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('resource_learning_notes', [
            'id' => $note->id,
            'title' => 'Updated Title',
            'content' => 'Updated content',
        ]);
    }

    public function test_user_cannot_update_another_users_learning_note(): void
    {
        $note = ResourceLearningNote::create([
            'user_id' => $this->userB->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'understanding',
            'title' => 'User B Note',
            'content' => 'Content',
        ]);

        $this->actingAs($this->userA)
            ->put("/api/v1/resources/{$this->resourceA->id}/learning-notes/{$note->id}", [
                'title' => 'Hacked Title',
                'content' => 'Hacked content',
            ])
            ->assertStatus(403);
    }

    // ── Learning Note Delete Tests ─────────────────────────────────────

    public function test_user_can_delete_their_own_learning_note(): void
    {
        $note = ResourceLearningNote::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'understanding',
            'title' => 'My Note',
            'content' => 'Content',
        ]);

        $this->actingAs($this->userA)
            ->delete("/api/v1/resources/{$this->resourceA->id}/learning-notes/{$note->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('resource_learning_notes', [
            'id' => $note->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_learning_note(): void
    {
        $note = ResourceLearningNote::create([
            'user_id' => $this->userB->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'understanding',
            'title' => 'User B Note',
            'content' => 'Content',
        ]);

        $this->actingAs($this->userA)
            ->delete("/api/v1/resources/{$this->resourceA->id}/learning-notes/{$note->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('resource_learning_notes', [
            'id' => $note->id,
        ]);
    }

    // ── Version Consistency Tests ─────────────────────────────────────

    public function test_learning_note_preserves_resource_version(): void
    {
        $this->actingAs($this->userA)
            ->post("/api/v1/resources/{$this->resourceA->id}/learning-notes", [
                'note_type' => 'understanding',
                'title' => 'My Note',
                'content' => 'Content',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('resource_learning_notes', [
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
        ]);
    }

    public function test_saved_explanation_preserves_resource_version(): void
    {
        $conversation = $this->createConversation($this->userA, $this->resourceA, $this->versionA);
        $message = $this->createAssistantMessage($conversation);

        $this->actingAs($this->userA)
            ->post('/api/v1/ai/saved-explanations', [
                'conversation_message_id' => $message->id,
                'title' => 'My Explanation',
                'content' => 'Content',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('resource_saved_explanations', [
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
        ]);
    }

    public function test_learning_note_can_reference_saved_explanation(): void
    {
        $conversation = $this->createConversation($this->userA, $this->resourceA, $this->versionA);
        $message = $this->createAssistantMessage($conversation);

        $savedExplanation = ResourceSavedExplanation::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'conversation_message_id' => $message->id,
            'title' => 'Saved Explanation',
            'content' => 'AI content',
        ]);

        $this->actingAs($this->userA)
            ->post("/api/v1/resources/{$this->resourceA->id}/learning-notes", [
                'note_type' => 'understanding',
                'title' => 'My Understanding',
                'content' => 'In my own words...',
                'saved_explanation_id' => $savedExplanation->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('resource_learning_notes', [
            'user_id' => $this->userA->id,
            'saved_explanation_id' => $savedExplanation->id,
        ]);
    }

    public function test_learning_note_cannot_reference_another_users_saved_explanation(): void
    {
        $conversation = $this->createConversation($this->userB, $this->resourceA, $this->versionA);
        $message = $this->createAssistantMessage($conversation);

        $savedExplanation = ResourceSavedExplanation::create([
            'user_id' => $this->userB->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'conversation_message_id' => $message->id,
            'title' => 'User B Explanation',
            'content' => 'Content',
        ]);

        $this->actingAs($this->userA)
            ->post("/api/v1/resources/{$this->resourceA->id}/learning-notes", [
                'note_type' => 'understanding',
                'title' => 'My Understanding',
                'content' => 'Content',
                'saved_explanation_id' => $savedExplanation->id,
            ])
            ->assertStatus(404);
    }

    // ── Chapter and Page Context Tests ────────────────────────────────

    public function test_learning_note_can_include_chapter(): void
    {
        $this->actingAs($this->userA)
            ->post("/api/v1/resources/{$this->resourceA->id}/learning-notes", [
                'note_type' => 'chapter_summary',
                'title' => 'Chapter 1 Summary',
                'content' => 'Summary content',
                'chapter' => 'Chapter 1',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('resource_learning_notes', [
            'user_id' => $this->userA->id,
            'chapter' => 'Chapter 1',
        ]);
    }

    public function test_learning_note_can_include_page_number(): void
    {
        $this->actingAs($this->userA)
            ->post("/api/v1/resources/{$this->resourceA->id}/learning-notes", [
                'note_type' => 'important_passage',
                'title' => 'Important Passage',
                'content' => 'Passage content',
                'page_number' => 42,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('resource_learning_notes', [
            'user_id' => $this->userA->id,
            'page_number' => 42,
        ]);
    }

    public function test_learning_notes_can_be_filtered_by_chapter(): void
    {
        ResourceLearningNote::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'chapter_summary',
            'title' => 'Chapter 1',
            'content' => 'Content',
            'chapter' => 'Chapter 1',
        ]);

        ResourceLearningNote::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'chapter_summary',
            'title' => 'Chapter 2',
            'content' => 'Content',
            'chapter' => 'Chapter 2',
        ]);

        $response = $this->actingAs($this->userA)
            ->get("/api/v1/resources/{$this->resourceA->id}/learning-notes?chapter=Chapter 1")
            ->assertStatus(200);

        $notes = $response->json('learning_notes');
        $this->assertCount(1, $notes);
        $this->assertEquals('Chapter 1', $notes[0]['title']);
    }

    public function test_learning_notes_can_be_filtered_by_note_type(): void
    {
        ResourceLearningNote::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'understanding',
            'title' => 'Understanding',
            'content' => 'Content',
        ]);

        ResourceLearningNote::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'note_type' => 'reflection',
            'title' => 'Reflection',
            'content' => 'Content',
        ]);

        $response = $this->actingAs($this->userA)
            ->get("/api/v1/resources/{$this->resourceA->id}/learning-notes?note_type=understanding")
            ->assertStatus(200);

        $notes = $response->json('learning_notes');
        $this->assertCount(1, $notes);
        $this->assertEquals('understanding', $notes[0]['note_type']);
    }
}
