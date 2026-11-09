<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ResourceDistributionRequest;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceFileSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaignA;

    private Campaign $campaignB;

    private MissionSite $siteA1;

    private MissionSite $siteB1;

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
        $cuc = OrgUnit::where('short_code', 'CUC')->first();
        $pt = ProgrammeType::where('code', 'global_mission')->first();
        $programme = Programme::where('programme_type_id', $pt->id)->first();

        $this->campaignA = Campaign::factory()->create([
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $cuc->id,
            'name' => 'Campaign A',
            'status' => 'active',
        ]);

        $this->campaignB = Campaign::factory()->create([
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $cuc->id,
            'name' => 'Campaign B',
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
    }

    private function createUserWithSiteRole(string $roleCode, MissionSite $site): User
    {
        $user = User::factory()->create([
            'email' => uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

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

    private function createUserWithCampaignRole(string $roleCode, Campaign $campaign): User
    {
        $user = User::factory()->create([
            'email' => uniqid().'@test.com',
            'password' => bcrypt('password'),
        ]);

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

    public function test_unauthorized_user_cannot_preview_file(): void
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

        // User from campaign B should not be able to preview
        $response = $this->actingAs($missionaryB)
            ->getJson('/api/v1/resources/'.$resource->id.'/preview');

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_download_file(): void
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

        // User from campaign B should not be able to download
        $response = $this->actingAs($missionaryB)
            ->getJson('/api/v1/resources/'.$resource->id.'/download');

        $response->assertStatus(403);
    }

    public function test_approved_audience_can_access_released_version(): void
    {
        $missionaryA = $this->createUserWithSiteRole('missionary', $this->siteA1);
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

        // Submit and approve request for campaign A
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

        // User from campaign A should be able to preview
        $response = $this->actingAs($missionaryA)
            ->getJson('/api/v1/resources/'.$resource->id.'/preview');

        $response->assertStatus(200);

        // User from campaign A should be able to download
        $response = $this->actingAs($missionaryA)
            ->getJson('/api/v1/resources/'.$resource->id.'/download');

        $response->assertStatus(200);
    }

    public function test_revoking_audience_blocks_access(): void
    {
        $missionaryA = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $missionaryA2 = $this->createUserWithSiteRole('missionary', $this->siteA1);
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

        // Submit and approve request for campaign A
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

        // Verify another user from same campaign can access before revocation
        $response = $this->actingAs($missionaryA2)
            ->getJson('/api/v1/resources/'.$resource->id.'/preview');
        $response->assertStatus(200);

        // Revoke the release
        $this->actingAs($approverA)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/revoke', [
                'reason' => 'Content outdated',
            ]);

        // User should no longer be able to access after revocation
        $response = $this->actingAs($missionaryA2)
            ->getJson('/api/v1/resources/'.$resource->id.'/preview');

        $response->assertStatus(403);
    }

    public function test_private_resource_not_accessible_to_unrelated_users(): void
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

        // Submit private request for missionaryA
        $this->actingAs($missionaryA)
            ->postJson('/api/v1/resources/'.$resource->id.'/distribution-requests', [
                'audience_type' => 'private',
                'recipient_user_id' => $missionaryA->id,
            ]);

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->first();

        $this->actingAs($approverA)
            ->postJson('/api/v1/resources/distribution-requests/'.$request->id.'/decision', [
                'action' => 'approved',
                'reason' => 'Approved',
            ]);

        // Unrelated user should not be able to access
        $response = $this->actingAs($missionaryB)
            ->getJson('/api/v1/resources/'.$resource->id.'/preview');

        $response->assertStatus(403);
    }

    public function test_archived_resource_not_accessible(): void
    {
        $missionaryA = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $missionaryA2 = $this->createUserWithSiteRole('missionary', $this->siteA1);
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

        // Submit and approve request
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

        // Archive the resource
        $resource->update(['archived_at' => now()]);

        // Another user should not be able to access archived resource
        $response = $this->actingAs($missionaryA2)
            ->getJson('/api/v1/resources/'.$resource->id.'/preview');

        $response->assertStatus(403);
    }
}
