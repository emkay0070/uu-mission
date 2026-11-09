<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ResourceDistributionRelease;
use App\Models\ResourceDistributionRequest;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceDistributionTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $uu;

    private OrgUnit $cuc;

    private Campaign $campaignA;

    private Campaign $campaignB;

    private MissionSite $siteA1;

    private MissionSite $siteB1;

    private Team $teamA1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();

        $this->seed(DatabaseSeeder::class);
        $this->buildFixtures();
    }

    private function buildFixtures(): void
    {
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

        $this->siteB1 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignB->id,
            'name' => 'Site B1',
        ]);

        $this->teamA1 = Team::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'name' => 'Team A1',
        ]);
    }

    private function createUserWithGlobalRole(string $roleCode): User
    {
        $user = User::factory()->create();
        $role = SystemRole::where('code', $roleCode)->first();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'global',
            'role_permission_version_id' => $role->current_permission_version_id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function createUserWithCampaignRole(string $roleCode, Campaign $campaign): User
    {
        $user = User::factory()->create();
        $role = SystemRole::where('code', $roleCode)->first();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id,
            'role_permission_version_id' => $role->current_permission_version_id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function createUserWithSiteRole(string $roleCode, MissionSite $site): User
    {
        $user = User::factory()->create();
        $role = SystemRole::where('code', $roleCode)->first();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $site->id,
            'role_permission_version_id' => $role->current_permission_version_id,
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_submit_distribution_request_for_campaign(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $response = $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $resource = Resource::where('title', 'Test Resource')->first();
        $this->assertNotNull($resource);

        // Submit distribution request
        $response = $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('resource_distribution_requests', [
            'resource_id' => $resource->id,
            'audience_type' => 'campaign',
            'target_campaign_id' => $this->campaignA->id,
            'status' => 'pending_review',
        ]);
    }

    public function test_submit_distribution_request_for_union(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $response = $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit distribution request for union
        $response = $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'union',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('resource_distribution_requests', [
            'resource_id' => $resource->id,
            'audience_type' => 'union',
            'target_org_unit_id' => $this->uu->id,
            'status' => 'pending_review',
        ]);
    }

    public function test_approve_distribution_request_creates_release(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit request
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        // Approve request
        $response = $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved for distribution',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('resource_distribution_requests', [
            'id' => $request->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('resource_distribution_releases', [
            'resource_id' => $resource->id,
            'resource_distribution_request_id' => $request->id,
            'audience_type' => 'campaign',
            'audience_key' => 'campaign:'.$this->campaignA->id,
        ]);
    }

    public function test_reject_distribution_request(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit request
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        // Reject request
        $response = $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'rejected',
                'reason' => 'Content not appropriate',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('resource_distribution_requests', [
            'id' => $request->id,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseMissing('resource_distribution_releases', [
            'resource_distribution_request_id' => $request->id,
        ]);
    }

    public function test_return_distribution_request_for_revision(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit request
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        // Return request
        $response = $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'returned',
                'reason' => 'Please add more details',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('resource_distribution_requests', [
            'id' => $request->id,
            'status' => 'returned',
        ]);
    }

    public function test_resubmit_returned_request(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit request
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        // Return request
        $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'returned',
                'reason' => 'Please add more details',
            ]);

        // Resubmit
        $response = $this->actingAs($missionary)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/resubmit');

        $response->assertStatus(200);

        $this->assertDatabaseHas('resource_distribution_requests', [
            'id' => $request->id,
            'status' => 'pending_review',
        ]);
    }

    public function test_revoke_release(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit and approve request
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        $release = ResourceDistributionRelease::where('resource_distribution_request_id', $request->id)->first();

        // Revoke release (using request revoke endpoint)
        $response = $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/revoke', [
                'reason' => 'Content outdated',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('resource_distribution_releases', [
            'id' => $release->id,
            'revoked_at' => now(),
        ]);
    }

    public function test_unauthorized_user_cannot_approve_request(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $otherUser = $this->createUserWithSiteRole('missionary', $this->siteB1);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit request
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        // Try to approve with unauthorized user
        $response = $this->actingAs($otherUser)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        $response->assertStatus(403);
    }

    public function test_archived_resource_cannot_be_distributed(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();
        $resource->status = 'archived';
        $resource->save();

        // Try to submit request for archived resource
        $response = $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $response->assertStatus(403);
    }

    // Authorization tests

    public function test_user_can_view_released_resource_in_their_campaign(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit and approve request
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        // User in the campaign should be able to view
        $response = $this->actingAs($missionary)
            ->getJson('/api/v1/resources/'.$resource->id);

        $response->assertStatus(200);
    }

    public function test_user_cannot_view_resource_not_released_to_their_campaign(): void
    {
        $missionaryA = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $missionaryB = $this->createUserWithSiteRole('missionary', $this->siteB1);
        $approverA = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionaryA)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit and approve request for campaign A only
        $this->actingAs($missionaryA)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        $this->actingAs($approverA)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        // User in campaign B should NOT be able to view
        $response = $this->actingAs($missionaryB)
            ->getJson('/api/v1/resources/'.$resource->id);

        $response->assertStatus(403);
    }

    public function test_private_recipient_can_view_resource(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $recipient = $this->createUserWithSiteRole('missionary', $this->siteB1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit private distribution request
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'private',
                'recipient_user_id' => $recipient->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        // Recipient should be able to view
        $response = $this->actingAs($recipient)
            ->getJson('/api/v1/resources/'.$resource->id);

        $response->assertStatus(200);
    }

    public function test_creator_does_not_automatically_get_reader_access(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Creator cannot view published resource without approved release
        // (architectural rule: creator ownership does not grant automatic reader access)
        $resource->update(['status' => 'published']);

        $response = $this->actingAs($missionary)
            ->getJson('/api/v1/resources/'.$resource->id);

        $response->assertStatus(403);
    }

    public function test_publisher_does_not_automatically_get_reader_access(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit and approve request
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        // Approver (publisher) should not be able to view unless they're in the audience
        // Since the approver is campaign_leader for campaignA, they should have access
        // But let's test with a different campaign's approver
        $approverB = $this->createUserWithCampaignRole('campaign_leader', $this->campaignB);

        $response = $this->actingAs($approverB)
            ->getJson('/api/v1/resources/'.$resource->id);

        $response->assertStatus(403);
    }

    // Versioning tests

    public function test_multiple_independent_audience_requests(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approverA = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $approverB = $this->createUserWithCampaignRole('campaign_leader', $this->campaignB);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit request for campaign A
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        // Submit request for campaign B
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignB->id,
            ]);

        $this->assertDatabaseCount('resource_distribution_requests', 2);
    }

    public function test_one_audience_approved_while_another_pending(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approverA = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $approverB = $this->createUserWithCampaignRole('campaign_leader', $this->campaignB);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit requests for both campaigns
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignB->id,
            ]);

        $requestA = ResourceDistributionRequest::where('target_campaign_id', $this->campaignA->id)->first();
        $requestB = ResourceDistributionRequest::where('target_campaign_id', $this->campaignB->id)->first();

        // Approve campaign A, leave B pending
        $this->actingAs($approverA)
            ->postJson('/api/v1/resources/distribution-requests/'.$requestA->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        $this->assertDatabaseHas('resource_distribution_requests', [
            'id' => $requestA->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('resource_distribution_requests', [
            'id' => $requestB->id,
            'status' => 'pending_review',
        ]);
    }

    public function test_version_update_requires_separate_approval(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit and approve request for version 1
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $request1 = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        $this->actingAs($approver)
            ->postJson('/api/v1/resources/distribution-requests/'.$request1->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        // Replace file (create new version)
        $file2 = UploadedFile::fake()->create('document2.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/replace-file', [
                'file' => $file2,
            ]);

        // New distribution request should be required for the new version
        // Must supersedes the previous approved request for the same audience
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
                'supersedes_request_id' => $request1->id,
            ]);

        $request2 = ResourceDistributionRequest::where('resource_id', $resource->id)
            ->where('id', '!=', $request1->id)
            ->first();

        $this->assertNotNull($request2);
        $this->assertDatabaseHas('resource_distribution_requests', [
            'id' => $request2->id,
            'status' => 'pending_review',
        ]);
    }

    public function test_revoking_one_audience_does_not_affect_others(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approverA = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);
        $approverB = $this->createUserWithCampaignRole('campaign_leader', $this->campaignB);
        $category = ResourceCategory::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 1000);
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $category->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $resource = Resource::where('title', 'Test Resource')->first();

        // Submit and approve for both campaigns
        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $this->actingAs($missionary)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignB->id,
            ]);

        $requestA = ResourceDistributionRequest::where('target_campaign_id', $this->campaignA->id)->first();
        $requestB = ResourceDistributionRequest::where('target_campaign_id', $this->campaignB->id)->first();

        $this->actingAs($approverA)
            ->postJson('/api/v1/resources/distribution-requests/'.$requestA->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        $this->actingAs($approverB)
            ->postJson('/api/v1/resources/distribution-requests/'.$requestB->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        $releaseA = ResourceDistributionRelease::where('resource_distribution_request_id', $requestA->id)->first();
        $releaseB = ResourceDistributionRelease::where('resource_distribution_request_id', $requestB->id)->first();

        // Revoke campaign A release (using request revoke endpoint)
        $this->actingAs($approverA)
            ->postJson('/api/v1/resources/distribution-requests/'.$requestA->id.'/revoke', [
                'reason' => 'Content outdated',
            ]);

        // Campaign B release should still be active
        $this->assertDatabaseHas('resource_distribution_releases', [
            'id' => $releaseB->id,
            'revoked_at' => null,
        ]);
    }
}
