<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ResourceConversation;
use App\Models\ResourceConversationMessage;
use App\Models\ResourceVersion;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\ResourceVisibilityService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResourceAIReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $uu;

    private OrgUnit $cuc;

    private Campaign $campaignA;

    private MissionSite $siteA1;

    private Team $teamA1;

    private User $user;

    private Resource $resource;

    private ResourceVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->buildFixtures();

        $this->user = $this->createUserWithGlobalRole('missionary');

        $this->resource = $this->createResource('Test Resource', $this->campaignA);

        $this->version = ResourceVersion::factory()->create([
            'resource_id' => $this->resource->id,
            'storage_path' => 'test.pdf',
            'file_size' => 1000,
        ]);

        $this->resource->current_version_id = $this->version->id;
        $this->resource->save();
    }

    private function buildFixtures(): void
    {
        $typeUnion = OrgUnitType::where('code', 'union')->first();
        $typeConf = OrgUnitType::where('code', 'conference')->first();

        $this->uu = OrgUnit::where('short_code', 'UUM')->first();
        $this->cuc = OrgUnit::where('short_code', 'CUC')->first();

        $pt = ProgrammeType::where('code', 'global_mission')->first();
        $programme = Programme::where('programme_type_id', $pt->id)->first();

        $this->campaignA = Campaign::factory()->create([
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name' => 'Campaign Alpha',
            'status' => 'active',
        ]);

        $this->siteA1 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'name' => 'Site A1',
        ]);

        $this->teamA1 = Team::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'name' => 'Team A1',
        ]);
    }

    private function createUserWithGlobalRole(string $roleCode): User
    {
        $role = SystemRole::where('code', $roleCode)->first();
        $user = User::factory()->create([
            'email' => uniqid().'@example.com',
        ]);
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'global',
        ]);

        return $user;
    }

    private function createResource(string $title, Campaign $campaign): Resource
    {
        $category = ResourceCategory::first();

        if (! $category) {
            $category = ResourceCategory::factory()->create();
        }

        return Resource::factory()->create([
            'title' => $title,
            'category_id' => $category->id,
            'status' => 'published',
        ]);
    }

    /**
     * Test that user message persists even if AI fails.
     */
    public function test_user_message_persists_on_ai_failure(): void
    {
        // Mock visibility service to allow access
        $this->mock(ResourceVisibilityService::class, function ($mock) {
            $mock->shouldReceive('canView')->andReturn(true);
        });

        // Mock AI service to fail
        Config::set('services.gemini.api_key', 'test-key');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Service unavailable'], 503),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$this->resource->id}/ai/chat", [
                'message' => 'Test message',
            ]);

        $response->assertStatus(503);

        // Verify user message was persisted
        $this->assertDatabaseHas('resource_conversation_messages', [
            'role' => 'user',
            'content' => 'Test message',
            'status' => 'completed',
        ]);

        // Verify assistant message was marked as failed
        $this->assertDatabaseHas('resource_conversation_messages', [
            'role' => 'assistant',
            'status' => 'failed',
        ]);
    }

    /**
     * Test that conversation history is ordered correctly.
     */
    public function test_conversation_history_ordered_correctly(): void
    {
        // Mock visibility service to allow access
        $this->mock(ResourceVisibilityService::class, function ($mock) {
            $mock->shouldReceive('canView')->andReturn(true);
        });

        $conversation = ResourceConversation::create([
            'user_id' => $this->user->id,
            'resource_id' => $this->resource->id,
            'resource_version_id' => $this->version->id,
            'title' => 'Test Conversation',
        ]);

        $msg1 = ResourceConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'First message',
            'status' => 'completed',
        ]);

        $msg2 = ResourceConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'First response',
            'status' => 'completed',
        ]);

        $msg3 = ResourceConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Second message',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/ai/conversations/{$conversation->id}");

        $response->assertStatus(200);

        $messages = $response->json('messages');

        // Verify chronological order
        $this->assertEquals($msg1->id, $messages[0]['id']);
        $this->assertEquals($msg2->id, $messages[1]['id']);
        $this->assertEquals($msg3->id, $messages[2]['id']);
    }

    /**
     * Test that retry updates failed message without duplicating user message.
     */
    public function test_retry_updates_failed_message_without_duplication(): void
    {
        // Mock visibility service to allow access
        $this->mock(ResourceVisibilityService::class, function ($mock) {
            $mock->shouldReceive('canView')->andReturn(true);
        });

        $conversation = ResourceConversation::create([
            'user_id' => $this->user->id,
            'resource_id' => $this->resource->id,
            'resource_version_id' => $this->version->id,
            'title' => 'Test Conversation',
        ]);

        $userMsg = ResourceConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Test message',
            'status' => 'completed',
        ]);

        $failedMsg = ResourceConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'failed',
            'error_message' => 'AI service error',
            'context_metadata' => ['context_type' => 'general'],
        ]);

        // Mock successful AI response
        Config::set('services.gemini.api_key', 'test-key');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Successful response']],
                        ],
                    ],
                ],
                'usageMetadata' => ['totalTokenCount' => 100],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ai/messages/{$failedMsg->id}/retry");

        $response->assertStatus(200);

        // Verify failed message was updated
        $failedMsg->refresh();
        $this->assertEquals('completed', $failedMsg->status);
        $this->assertEquals('Successful response', $failedMsg->content);
        $this->assertNull($failedMsg->error_message);

        // Verify no duplicate user message was created
        $userMessageCount = ResourceConversationMessage::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->count();
        $this->assertEquals(1, $userMessageCount);

        // Verify only one assistant message exists (the retried one)
        $assistantMessageCount = ResourceConversationMessage::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->count();
        $this->assertEquals(1, $assistantMessageCount);
    }

    /**
     * Test that retry is rejected for non-failed messages.
     */
    public function test_retry_rejected_for_non_failed_messages(): void
    {
        $conversation = ResourceConversation::create([
            'user_id' => $this->user->id,
            'resource_id' => $this->resource->id,
            'resource_version_id' => $this->version->id,
            'title' => 'Test Conversation',
        ]);

        $completedMsg = ResourceConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Completed response',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ai/messages/{$completedMsg->id}/retry");

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Only failed assistant messages can be retried']);
    }
}
