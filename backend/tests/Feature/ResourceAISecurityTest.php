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
use App\Models\ResourceSavedExplanation;
use App\Models\ResourceVersion;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceAISecurityTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $uu;

    private OrgUnit $cuc;

    private Campaign $campaignA;

    private Campaign $campaignB;

    private MissionSite $siteA1;

    private Team $teamA1;

    private User $userA;

    private User $userB;

    private Resource $resourceA;

    private Resource $resourceB;

    private ResourceVersion $versionA;

    private ResourceVersion $versionB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed(DatabaseSeeder::class);
        $this->buildFixtures();

        $this->userA = $this->createUserWithGlobalRole('missionary', $this->siteA1, $this->teamA1);
        $this->userB = $this->createUserWithGlobalRole('missionary', $this->siteA1, $this->teamA1);

        $this->resourceA = $this->createResource('Resource A', $this->campaignA);
        $this->resourceB = $this->createResource('Resource B', $this->campaignB);

        $this->versionA = ResourceVersion::factory()->create([
            'resource_id' => $this->resourceA->id,
            'storage_path' => 'test.pdf',
            'file_size' => 1000,
        ]);

        $this->versionB = ResourceVersion::factory()->create([
            'resource_id' => $this->resourceB->id,
            'storage_path' => 'test2.pdf',
            'file_size' => 1000,
        ]);

        $this->resourceA->current_version_id = $this->versionA->id;
        $this->resourceA->save();

        $this->resourceB->current_version_id = $this->versionB->id;
        $this->resourceB->save();
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

        $this->campaignB = Campaign::factory()->create([
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name' => 'Campaign Beta',
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

    private function createUserWithGlobalRole(string $roleCode, ?MissionSite $site = null, ?Team $team = null): User
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
     * Test that another missionary cannot retrieve conversations belonging to user A.
     */
    public function test_another_missionary_cannot_retrieve_conversations(): void
    {
        // Create conversation for user A
        $conversationA = ResourceConversation::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'title' => 'User A Conversation',
        ]);

        // User B tries to retrieve all conversations
        $response = $this->actingAs($this->userB)
            ->getJson('/api/v1/ai/conversations');

        $response->assertStatus(200);

        $conversations = $response->json('conversations');
        $conversationIds = collect($conversations)->pluck('id');

        // User B should not see user A's conversation
        $this->assertNotContains($conversationA->id, $conversationIds);
    }

    /**
     * Test that another missionary cannot retrieve saved explanations.
     */
    public function test_another_missionary_cannot_retrieve_saved_explanations(): void
    {
        // Create conversation and message for user A
        $conversationA = ResourceConversation::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'title' => 'User A Conversation',
        ]);

        $messageA = ResourceConversationMessage::create([
            'conversation_id' => $conversationA->id,
            'role' => 'assistant',
            'content' => 'AI explanation',
            'status' => 'completed',
        ]);

        // Save explanation for user A
        $savedExplanation = ResourceSavedExplanation::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'conversation_message_id' => $messageA->id,
            'title' => 'Saved Explanation',
            'content' => 'Content',
        ]);

        // User B tries to retrieve saved explanations
        $response = $this->actingAs($this->userB)
            ->getJson('/api/v1/ai/saved-explanations');

        $response->assertStatus(200);

        $explanations = $response->json('saved_explanations');
        $explanationIds = collect($explanations)->pluck('id');

        // User B should not see user A's saved explanation
        $this->assertNotContains($savedExplanation->id, $explanationIds);
    }

    /**
     * Test that a user cannot save another user's assistant message.
     */
    public function test_user_cannot_save_another_users_assistant_message(): void
    {
        // Create conversation and message for user A
        $conversationA = ResourceConversation::create([
            'user_id' => $this->userA->id,
            'resource_id' => $this->resourceA->id,
            'resource_version_id' => $this->versionA->id,
            'title' => 'User A Conversation',
        ]);

        $messageA = ResourceConversationMessage::create([
            'conversation_id' => $conversationA->id,
            'role' => 'assistant',
            'content' => 'AI explanation',
            'status' => 'completed',
        ]);

        // User B tries to save user A's message
        $response = $this->actingAs($this->userB)
            ->postJson('/api/v1/ai/saved-explanations', [
                'conversation_message_id' => $messageA->id,
                'title' => 'Stolen Explanation',
                'content' => 'Content',
            ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Message not found or access denied']);
    }
}
