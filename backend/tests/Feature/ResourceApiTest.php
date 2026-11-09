<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentVersion;
use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ResourceDistributionRequest;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Shared test fixtures ──────────────────────────────────────────────────

    private OrgUnit $uu;

    private OrgUnit $cuc;

    private Campaign $campaignA;

    private Campaign $campaignB;

    private MissionSite $siteA1;

    private MissionSite $siteA2;

    private MissionSite $siteB1;

    private Team $teamA1;

    private Team $teamB1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();

        // Seed the production roles and permissions
        $this->seed(DatabaseSeeder::class);

        $this->buildFixtures();
        $this->user = $this->createUserWithGlobalRole('platform_admin');
    }

    private function buildFixtures(): void
    {
        // Org hierarchy (matching production seeder structure)
        $typeUnion = OrgUnitType::where('code', 'union')->first();
        $typeConf = OrgUnitType::where('code', 'conference')->first();

        $this->uu = OrgUnit::where('short_code', 'UUM')->first();
        $this->cuc = OrgUnit::where('short_code', 'CUC')->first();

        // Programme
        $pt = ProgrammeType::where('code', 'global_mission')->first();
        $programme = Programme::where('programme_type_id', $pt->id)->first();

        // Campaigns
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

        // Sites
        $this->siteA1 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'name' => 'Site A1',
        ]);

        $this->siteA2 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'name' => 'Site A2',
        ]);

        $this->siteB1 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignB->id,
            'name' => 'Site B1',
        ]);

        // Teams
        $this->teamA1 = Team::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'name' => 'Team A1',
        ]);

        $this->teamB1 = Team::factory()->create([
            'campaign_id' => $this->campaignB->id,
            'mission_site_id' => $this->siteB1->id,
            'name' => 'Team B1',
        ]);
    }

    private function createUserWithGlobalRole(string $roleCode): User
    {
        $role = SystemRole::where('code', $roleCode)->first();
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'global',
        ]);

        return $user;
    }

    private function createUserWithOrgUnitRole(string $roleCode, OrgUnit $orgUnit): User
    {
        $role = SystemRole::where('code', $roleCode)->first();
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'org_unit',
            'scoped_org_unit_id' => $orgUnit->id,
        ]);

        return $user;
    }

    private function createUserWithCampaignRole(string $roleCode, Campaign $campaign): User
    {
        $role = SystemRole::where('code', $roleCode)->first();
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id,
        ]);

        return $user;
    }

    private function createUserWithSiteRole(string $roleCode, MissionSite $site): User
    {
        $role = SystemRole::where('code', $roleCode)->first();
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $site->id,
        ]);

        return $user;
    }

    private function createUserWithTeamRole(string $roleCode, Team $team): User
    {
        $role = SystemRole::where('code', $roleCode)->first();
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'team',
            'scoped_team_id' => $team->id,
        ]);

        return $user;
    }

    /**
     * Test unauthenticated access is rejected.
     */
    public function test_unauthenticated_access_rejected(): void
    {
        $response = $this->getJson('/api/v1/resources');
        $response->assertStatus(401);
    }

    /**
     * Test authenticated user can list published resources.
     */
    public function test_authenticated_user_can_list_published_resources(): void
    {
        [$resource] = $this->createDistributedResource($this->user, $this->user);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/resources');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $resource->id]);
    }

    public function test_missionary_can_read_union_resources_but_cannot_manage_them(): void
    {
        $unionDirector = $this->createUserWithOrgUnitRole('union_director', $this->uu);
        [$resource] = $this->createDistributedResource($this->user, $unionDirector, [], [
            'audience_type' => 'union',
        ]);
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);

        $this->actingAs($missionary)->getJson('/api/v1/resources')
            ->assertOk()->assertJsonFragment(['id' => $resource->id]);

        $this->actingAs($missionary)
            ->patchJson("/api/v1/resources/{$resource->id}", ['title' => 'Not allowed'])
            ->assertForbidden();

        $this->actingAs($missionary)->postJson("/api/v1/resources/{$resource->id}/archive")
            ->assertForbidden();
    }

    public function test_resource_audiences_do_not_expand_to_sibling_scopes(): void
    {
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);

        [$siteResource] = $this->createDistributedResource($this->user, $approver, [], [
            'audience_type' => 'site',
            'target_site_id' => $this->siteA1->id,
        ]);
        $otherSiteCoordinator = $this->createUserWithSiteRole('site_coordinator', $this->siteA2);
        $this->actingAs($otherSiteCoordinator)
            ->getJson("/api/v1/resources/{$siteResource->id}")->assertForbidden();

        [$teamResource] = $this->createDistributedResource($this->user, $approver, [], [
            'audience_type' => 'team',
            'target_team_id' => $this->teamA1->id,
        ]);
        $otherTeamLeader = $this->createUserWithTeamRole('team_leader', $this->teamB1);
        $this->actingAs($otherTeamLeader)
            ->getJson("/api/v1/resources/{$teamResource->id}")->assertForbidden();
    }

    public function test_pending_distribution_requests_are_limited_to_review_authority_and_audience(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $allowedResource = $this->createResourceAs($missionary, ['title' => 'Allowed Resource']);
        $otherResource = $this->createResourceAs($missionary, ['title' => 'Other Resource']);

        $this->actingAs($missionary)
            ->postJson("/api/v1/resources/{$allowedResource->id}/distribution-requests", [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ])->assertCreated();

        $this->actingAs($missionary)
            ->postJson("/api/v1/resources/{$otherResource->id}/distribution-requests", [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignB->id,
            ])->assertCreated();

        $allowedRequest = ResourceDistributionRequest::where('resource_id', $allowedResource->id)->firstOrFail();
        $otherRequest = ResourceDistributionRequest::where('resource_id', $otherResource->id)->firstOrFail();
        $leader = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);

        $this->actingAs($leader)
            ->getJson('/api/v1/resources/distribution-requests?status=pending_review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $allowedRequest->id])
            ->assertJsonMissing(['id' => $otherRequest->id]);
    }

    public function test_seeded_resource_permissions_match_the_approved_role_matrix(): void
    {
        $permissionsFor = fn (string $role) => SystemRole::where('code', $role)->first()
            ->permissions()->pluck('code')->intersect([
                'resources.view', 'resources.create', 'resources.manage', 'resources.publish', 'resources.archive',
            ])->sort()->values()->all();

        $this->assertSame(['resources.create', 'resources.view'], $permissionsFor('missionary'));
        $this->assertSame(['resources.view'], $permissionsFor('site_secretary'));
        $this->assertSame(['resources.create', 'resources.manage', 'resources.view'], $permissionsFor('site_coordinator'));
        $this->assertSame(['resources.archive', 'resources.create', 'resources.manage', 'resources.publish', 'resources.view'], $permissionsFor('union_director'));
    }

    /**
     * Test ordinary users cannot see unpublished resources.
     */
    public function test_ordinary_users_cannot_see_unpublished_resources(): void
    {
        $category = ResourceCategory::factory()->create();
        $resource = Resource::factory()->create([
            'category_id' => $category->id,
            'status' => 'draft',
        ]);
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $response = $this->actingAs($missionary)
            ->getJson('/api/v1/resources');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /**
     * Test resource search by title.
     */
    public function test_search_resources_by_title(): void
    {
        $this->createDistributedResource($this->user, $this->user, ['title' => 'Bible Study Guide']);
        $this->createDistributedResource($this->user, $this->user, ['title' => 'Evangelism Handbook']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/resources?search=Bible');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Bible Study Guide');
    }

    /**
     * Test filter by category.
     */
    public function test_filter_resources_by_category(): void
    {
        $category1 = ResourceCategory::factory()->create();
        $category2 = ResourceCategory::factory()->create();

        [$resource1] = $this->createDistributedResource($this->user, $this->user, [
            'category_id' => $category1->id,
        ]);
        $this->createDistributedResource($this->user, $this->user, [
            'category_id' => $category2->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/resources?category_id={$category1->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $resource1->id]);
    }

    /**
     * Test create resource with file upload.
     */
    public function test_create_resource_with_file_upload(): void
    {
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/resources', [
                'file' => $file,
                'title' => 'Test Resource',
                'category_id' => $category->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('title', 'Test Resource')
            ->assertJsonPath('status', 'draft');

        $this->assertDatabaseHas('resources', ['title' => 'Test Resource']);
        $this->assertDatabaseHas('resource_versions', ['resource_id' => $response->json('id')]);
    }

    /**
     * Test create resource requires file.
     */
    public function test_create_resource_requires_file(): void
    {
        $category = ResourceCategory::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/resources', [
                'title' => 'Test Resource',
                'category_id' => $category->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test submitting a distribution request for review.
     */
    public function test_submit_resource_for_review(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $resource = $this->createResourceAs($missionary, ['title' => 'Resource Awaiting Review']);
        $this->assertSame('draft', $resource->status);

        $response = $this->actingAs($missionary)
            ->postJson("/api/v1/resources/{$resource->id}/distribution-requests", [
                'audience_type' => 'campaign',
                'target_campaign_id' => $this->campaignA->id,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseHas('resource_distribution_requests', [
            'resource_id' => $resource->id,
            'status' => 'pending_review',
        ]);
    }

    /**
     * Test approving a distribution request publishes the resource.
     */
    public function test_publish_resource(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);

        [$resource] = $this->createDistributedResource($missionary, $approver, [
            'title' => 'Published Resource',
        ]);

        $this->assertSame('published', $resource->status);
        $this->assertNotNull($resource->published_at);
        $this->assertDatabaseHas('resource_distribution_releases', [
            'resource_id' => $resource->id,
        ]);
    }

    /**
     * Test that an already-decided distribution request cannot transition again.
     */
    public function test_invalid_workflow_transition(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);

        [, $request] = $this->createDistributedResource($missionary, $approver, [
            'title' => 'Already Decided Resource',
        ]);

        $response = $this->actingAs($approver)
            ->postJson("/api/v1/resources/distribution-requests/{$request->id}/decision", [
                'action' => 'approved',
                'reason' => 'Approved again',
            ]);

        $response->assertStatus(422);
        $this->assertSame('approved', $request->fresh()->status);
    }

    /**
     * Test archive resource.
     */
    public function test_archive_resource(): void
    {
        $category = ResourceCategory::factory()->create();
        $resource = Resource::factory()->create([
            'category_id' => $category->id,
            'status' => 'published',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$resource->id}/archive");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'archived')
            ->assertJsonPath('archived_at', fn ($value) => $value !== null);
    }

    /**
     * Test replace resource file creates new version.
     */
    public function test_replace_file_creates_new_version(): void
    {
        [$resource] = $this->createDistributedResource($this->user, $this->user, [
            'title' => 'Replaceable Resource',
        ]);

        $initialVersionId = $resource->current_version_id;

        $file = UploadedFile::fake()->create('document_v2.pdf', 1000, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$resource->id}/replace-file", [
                'file' => $file,
                'change_reason' => 'Updated content',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('version_number', '1.1');

        $this->assertNotEquals($initialVersionId, $response->json('current_version_id'));
        $this->assertDatabaseCount('resource_versions', 2);
    }

    /**
     * Test secure file preview.
     */
    public function test_secure_file_preview(): void
    {
        [$resource] = $this->createDistributedResource($this->user, $this->user);
        Storage::disk('local')->put($resource->currentVersion->storage_path, 'test pdf content');

        $response = $this->actingAs($this->user)
            ->get("/api/v1/resources/{$resource->id}/preview");

        $response->assertStatus(200);
    }

    /**
     * Test secure file download.
     */
    public function test_secure_file_download(): void
    {
        [$resource] = $this->createDistributedResource($this->user, $this->user);
        Storage::disk('local')->put($resource->currentVersion->storage_path, 'test pdf content');

        $response = $this->actingAs($this->user)
            ->get("/api/v1/resources/{$resource->id}/download");

        $response->assertStatus(200)
            ->assertHeader('Content-Disposition');
    }

    /**
     * Test unauthenticated cannot access files.
     */
    public function test_unauthenticated_cannot_access_files(): void
    {
        $category = ResourceCategory::factory()->create();
        $resource = Resource::factory()->create([
            'category_id' => $category->id,
            'status' => 'published',
        ]);

        $response = $this->get("/api/v1/resources/{$resource->id}/preview");
        $response->assertStatus(401);
    }

    /**
     * Test reading progress is user-owned.
     */
    public function test_reading_progress_is_user_owned(): void
    {
        [$resource] = $this->createDistributedResource($this->user, $this->user);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$resource->id}/progress", [
                'last_page' => 5,
                'total_pages' => 100,
                'completion_percentage' => 5.0,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('resource_reading_progress', [
            'resource_id' => $resource->id,
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * Test reading progress is version-aware.
     */
    public function test_reading_progress_is_version_aware(): void
    {
        [$resource] = $this->createDistributedResource($this->user, $this->user, [
            'title' => 'Versioned Resource',
        ]);

        $version1Id = $resource->current_version_id;

        $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$resource->id}/progress", [
                'last_page' => 5,
                'total_pages' => 100,
                'completion_percentage' => 5.0,
            ])->assertStatus(201);

        // Replace file to create version 2
        $file = UploadedFile::fake()->create('document_v2.pdf', 1000, 'application/pdf');
        $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$resource->id}/replace-file", [
                'file' => $file,
                'change_reason' => 'Updated',
            ])->assertOk();

        // Create progress for version 2
        $resource = $resource->fresh();
        $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$resource->id}/progress", [
                'last_page' => 1,
                'total_pages' => 100,
                'completion_percentage' => 1.0,
            ])->assertStatus(201);

        // Both progress records should exist
        $this->assertDatabaseCount('resource_reading_progress', 2);

        $oldVersionProgress = $this->actingAs($this->user)
            ->getJson("/api/v1/resources/{$resource->id}/progress?version_id={$version1Id}");
        $oldVersionProgress->assertOk()->assertJsonPath('last_page', 5);

        $history = $this->actingAs($this->user)->getJson('/api/v1/reading-progress');
        $history->assertOk()->assertJsonCount(2, 'data');
    }

    /**
     * Upload a resource through the API as the given contributor.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createResourceAs(User $contributor, array $attributes = []): Resource
    {
        $categoryId = $attributes['category_id'] ?? ResourceCategory::factory()->create()->id;

        $response = $this->actingAs($contributor)
            ->postJson('/api/v1/resources', array_merge([
                'title' => 'Test Resource',
                'description' => 'Test description',
                'category_id' => $categoryId,
                'file' => UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf'),
            ], $attributes), ['Accept' => 'application/json']);

        $response->assertCreated();

        return Resource::findOrFail($response->json('id'));
    }

    /**
     * Upload and distribute a resource through the current request/approval workflow.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $audience
     * @return array{0: resource, 1: ResourceDistributionRequest}
     */
    private function createDistributedResource(
        User $contributor,
        User $approver,
        array $attributes = [],
        array $audience = []
    ): array {
        $resource = $this->createResourceAs($contributor, $attributes);

        $audience = array_merge(['audience_type' => 'campaign'], $audience);
        if ($audience['audience_type'] === 'campaign' && ! array_key_exists('target_campaign_id', $audience)) {
            $audience['target_campaign_id'] = $this->campaignA->id;
        }

        $this->actingAs($contributor)
            ->postJson("/api/v1/resources/{$resource->id}/distribution-requests", $audience)
            ->assertCreated();

        $request = ResourceDistributionRequest::where('resource_id', $resource->id)->latest('id')->firstOrFail();

        $this->actingAs($approver)
            ->postJson("/api/v1/resources/distribution-requests/{$request->id}/decision", [
                'action' => 'approved',
                'reason' => 'Approved',
            ])
            ->assertOk();

        return [$resource->fresh(), $request->fresh()];
    }

    /**
     * Build a resource the given missionary can actually read: uploaded and
     * distributed to their campaign through an approved release.
     *
     * @return array{0: User, 1: resource}
     */
    private function createReadableResourceForMissionary(): array
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $approver = $this->createUserWithCampaignRole('campaign_leader', $this->campaignA);

        [$resource] = $this->createDistributedResource($missionary, $approver, [
            'title' => 'Readable Resource',
        ]);

        return [$missionary, $resource];
    }

    /**
     * The reading history listing must resolve to its own route instead of
     * being captured by the wildcard /resources/{resource} route.
     */
    public function test_reading_resources_listing_is_not_captured_by_the_resource_route(): void
    {
        [$missionary, $resource] = $this->createReadableResourceForMissionary();

        $this->actingAs($missionary)
            ->postJson("/api/v1/resources/{$resource->id}/progress", [
                'last_page' => 2,
                'total_pages' => 10,
                'completion_percentage' => 20.0,
            ])
            ->assertStatus(201);

        $response = $this->actingAs($missionary)
            ->getJson('/api/v1/resources/reading-resources?recent=true&per_page=20');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.resource.id', $resource->id);
    }

    /**
     * Saved resources live in the singular "resource_saved" table, so the
     * listing must not rely on Eloquent's pluralization of the model name.
     */
    public function test_saved_resources_listing_uses_the_personal_library_table(): void
    {
        [$missionary, $resource] = $this->createReadableResourceForMissionary();

        $this->actingAs($missionary)
            ->postJson("/api/v1/resources/{$resource->id}/save")
            ->assertStatus(201);

        $this->assertDatabaseHas('resource_saved', [
            'resource_id' => $resource->id,
            'user_id' => $missionary->id,
        ]);

        $response = $this->actingAs($missionary)
            ->getJson('/api/v1/saved-resources?per_page=20');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.resource.id', $resource->id);
    }

    /**
     * Test bookmark ownership.
     */
    public function test_bookmark_ownership(): void
    {
        [$resource] = $this->createDistributedResource($this->user, $this->user);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$resource->id}/bookmarks", [
                'page_number' => 10,
                'title' => 'Important section',
            ]);

        $response->assertStatus(201);

        $bookmarkId = $response->json('id');

        // Different user cannot delete
        $otherUser = User::factory()->create();
        $deleteResponse = $this->actingAs($otherUser)
            ->deleteJson("/api/v1/resources/{$resource->id}/bookmarks/{$bookmarkId}");

        $deleteResponse->assertStatus(403);

        // Owner can delete
        $ownerDeleteResponse = $this->actingAs($this->user)
            ->deleteJson("/api/v1/resources/{$resource->id}/bookmarks/{$bookmarkId}");

        $ownerDeleteResponse->assertStatus(200);
    }

    /**
     * Test highlight ownership.
     */
    public function test_highlight_ownership(): void
    {
        [$resource] = $this->createDistributedResource($this->user, $this->user);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$resource->id}/highlights", [
                'selected_text' => 'Important text',
                'page_number' => 5,
                'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
            ]);

        $response->assertStatus(201);

        $highlightId = $response->json('id');

        // Different user cannot update
        $otherUser = User::factory()->create();
        $updateResponse = $this->actingAs($otherUser)
            ->patchJson("/api/v1/resources/{$resource->id}/highlights/{$highlightId}", [
                'color' => '#ff0000',
            ]);

        $updateResponse->assertStatus(403);
    }

    /**
     * Test note ownership.
     */
    public function test_note_ownership(): void
    {
        [$resource] = $this->createDistributedResource($this->user, $this->user);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/resources/{$resource->id}/notes", [
                'content' => 'My note',
                'page_number' => 5,
            ]);

        $response->assertStatus(201);

        $noteId = $response->json('id');

        // Different user cannot update
        $otherUser = User::factory()->create();
        $updateResponse = $this->actingAs($otherUser)
            ->patchJson("/api/v1/resources/{$resource->id}/notes/{$noteId}", [
                'content' => 'Modified note',
            ]);

        $updateResponse->assertStatus(403);
    }

    /**
     * Test list resource categories.
     */
    public function test_list_resource_categories(): void
    {
        ResourceCategory::factory()->create(['is_active' => true]);
        ResourceCategory::factory()->create(['is_active' => false]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/resource-categories');

        $response->assertStatus(200)
            ->assertJsonCount(11); // 10 seeded + 1 created in test
    }

    /**
     * Test file size validation.
     */
    public function test_file_size_validation(): void
    {
        $category = ResourceCategory::factory()->create();

        // Create a file larger than 500MB
        $file = UploadedFile::fake()->create('large.pdf', 600 * 1024 * 1024, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/resources', [
                'file' => $file,
                'title' => 'Test Resource',
                'category_id' => $category->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test the current distribution audience validation in place of the
     * removed resource visibility scope.
     */
    public function test_invalid_distribution_audience_requires_a_target(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $resource = $this->createResourceAs($missionary, ['title' => 'Invalid Audience Resource']);

        $response = $this->actingAs($missionary)
            ->postJson("/api/v1/resources/{$resource->id}/distribution-requests", [
                'audience_type' => 'conference',
                'target_org_unit_id' => null,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['target_org_unit_id']);
    }

    /**
     * An authorised contributor can upload a supported file, creating a draft
     * resource and dispatching document processing after the transaction
     * commits with the correct resource version.
     */
    public function test_missionary_upload_creates_draft_and_dispatches_processing_after_commit(): void
    {
        $missionary = $this->createUserWithSiteRole('missionary', $this->siteA1);
        $category = ResourceCategory::factory()->create();

        // RefreshDatabase keeps an outer test transaction open. The service's
        // own transaction must have closed before the job is dispatched.
        $outerTransactionLevel = DB::transactionLevel();

        $response = $this->actingAs($missionary)
            ->postJson('/api/v1/resources', [
                'title' => 'Missionary Upload',
                'description' => 'Uploaded by a missionary',
                'category_id' => $category->id,
                'file' => UploadedFile::fake()->create('missionary-resource.pdf', 1000, 'application/pdf'),
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('status', 'draft');

        $resource = Resource::findOrFail($response->json('id'));
        $version = $resource->currentVersion;

        $this->assertSame('draft', $resource->status);
        $this->assertNotNull($version);
        $this->assertSame((int) $resource->current_version_id, (int) $version->id);

        Queue::assertPushed(ProcessDocumentVersion::class, function (ProcessDocumentVersion $job) use ($version, $outerTransactionLevel): bool {
            $property = new \ReflectionProperty($job, 'resourceVersionId');
            $property->setAccessible(true);

            return (int) $property->getValue($job) === (int) $version->id
                && DB::transactionLevel() === $outerTransactionLevel
                && $version->exists;
        });
    }
}
