<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Media;
use App\Models\MediaAttachment;
use App\Models\MissionSite;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\PublishesRolePermissions;

/**
 * MediaAccessControlTest — verifies the media library is scoped per user and
 * per mission scope.
 *
 * The library used to return every non-private upload to every authenticated
 * user, so one missionary's images appeared in another missionary's gallery.
 */
class MediaAccessControlTest extends TestCase
{
    use PublishesRolePermissions;
    use RefreshDatabase;

    private Campaign $campaignA;

    private Campaign $campaignB;

    private MissionSite $siteA1;

    private MissionSite $siteA2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campaignA = Campaign::factory()->create([
            'name' => 'Campaign Alpha',
            'status' => 'active',
        ]);

        $this->campaignB = Campaign::factory()->create([
            'name' => 'Campaign Beta',
            'status' => 'active',
        ]);

        $this->siteA1 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'name' => 'Site A1',
        ]);

        $this->siteA2 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'name' => 'Site A2',
        ]);
    }

    #[Test]
    public function unauthenticated_media_list_returns_401(): void
    {
        $this->getJson('/api/v1/media')->assertStatus(401);
    }

    #[Test]
    public function private_media_is_only_visible_to_its_uploader(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $media = $this->createMedia($owner, 'private');

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/media')
            ->assertOk()
            ->assertJsonMissing(['uuid' => $media->uuid]);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}")
            ->assertStatus(403);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}/url")
            ->assertStatus(403);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}")
            ->assertOk()
            ->assertJsonFragment(['uuid' => $media->uuid]);
    }

    #[Test]
    public function public_media_is_visible_to_another_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $media = $this->createMedia($owner, 'public');

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}")
            ->assertOk()
            ->assertJsonFragment(['uuid' => $media->uuid]);
    }

    #[Test]
    public function organisational_media_is_visible_through_its_entity_scope(): void
    {
        $owner = User::factory()->create();
        $campaignALeader = $this->userWithCampaignScope($this->campaignA);
        $campaignBLeader = $this->userWithCampaignScope($this->campaignB);
        $media = $this->createMedia($owner, 'organisational', $this->campaignA);

        $this->actingAs($campaignALeader, 'sanctum')
            ->getJson('/api/v1/media')
            ->assertOk()
            ->assertJsonFragment(['uuid' => $media->uuid]);

        $this->actingAs($campaignALeader, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}")
            ->assertOk();

        $this->actingAs($campaignBLeader, 'sanctum')
            ->getJson('/api/v1/media')
            ->assertOk()
            ->assertJsonMissing(['uuid' => $media->uuid]);

        $this->actingAs($campaignBLeader, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}")
            ->assertStatus(403);
    }

    #[Test]
    public function organisational_media_at_a_sibling_site_is_hidden(): void
    {
        $owner = User::factory()->create();
        $siteA1User = $this->userWithSiteScope($this->siteA1);
        $siteA2User = $this->userWithSiteScope($this->siteA2);
        $media = $this->createMedia($owner, 'organisational', $this->siteA2);

        $this->actingAs($siteA2User, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}")
            ->assertOk();

        $this->actingAs($siteA1User, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}")
            ->assertStatus(403);
    }

    #[Test]
    public function unattached_organisational_media_stays_with_its_uploader(): void
    {
        $owner = User::factory()->create();
        $campaignLeader = $this->userWithCampaignScope($this->campaignA);
        $media = $this->createMedia($owner, 'organisational');

        $this->actingAs($campaignLeader, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}")
            ->assertStatus(403);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/media/{$media->uuid}")
            ->assertOk();
    }

    #[Test]
    public function another_user_cannot_update_or_delete_someone_elses_media(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $media = $this->createMedia($owner, 'private');

        $this->actingAs($otherUser, 'sanctum')
            ->patchJson("/api/v1/media/{$media->uuid}", ['title' => 'Hijacked'])
            ->assertStatus(403);

        $this->actingAs($otherUser, 'sanctum')
            ->deleteJson("/api/v1/media/{$media->uuid}")
            ->assertStatus(403);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/media/{$media->uuid}", ['title' => 'Mine'])
            ->assertOk()
            ->assertJsonFragment(['title' => 'Mine']);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/media/{$media->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('media', ['id' => $media->id]);
    }

    #[Test]
    public function attaching_media_requires_authority_over_the_target_entity(): void
    {
        $owner = $this->userWithCampaignScope($this->campaignA, ['campaigns.edit']);
        $media = $this->createMedia($owner, 'private');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/media/{$media->uuid}/attach", [
                'attachable_type' => Campaign::class,
                'attachable_id' => $this->campaignB->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('media_attachments', [
            'media_id' => $media->id,
            'attachable_type' => Campaign::class,
            'attachable_id' => $this->campaignB->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/media/{$media->uuid}/attach", [
                'attachable_type' => Campaign::class,
                'attachable_id' => $this->campaignA->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('media_attachments', [
            'media_id' => $media->id,
            'attachable_type' => Campaign::class,
            'attachable_id' => $this->campaignA->id,
        ]);
    }

    #[Test]
    public function detaching_media_requires_authority_over_the_target_entity(): void
    {
        $owner = $this->userWithCampaignScope($this->campaignA, ['campaigns.edit']);
        $media = $this->createMedia($owner, 'private', $this->campaignB);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/media/{$media->uuid}/detach", [
                'attachable_type' => Campaign::class,
                'attachable_id' => $this->campaignB->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('media_attachments', [
            'media_id' => $media->id,
            'attachable_type' => Campaign::class,
            'attachable_id' => $this->campaignB->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/media/{$media->uuid}/detach", [
                'attachable_type' => Campaign::class,
                'attachable_id' => $this->campaignA->id,
            ])
            ->assertStatus(404);
    }

    private function createMedia(User $uploader, string $visibility, ?Model $attachable = null): Media
    {
        $media = Media::create([
            'uuid' => (string) Str::uuid(),
            'original_filename' => 'photo.jpg',
            'stored_filename' => Str::uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'image',
            'storage_disk' => 'local',
            'storage_path' => 'media/image/2026/11/photo.jpg',
            'file_size' => 1024,
            'uploaded_by_user_id' => $uploader->id,
            'visibility' => $visibility,
            'processing_status' => 'completed',
        ]);

        if ($attachable !== null) {
            MediaAttachment::create([
                'media_id' => $media->id,
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
                'attached_by_user_id' => $uploader->id,
                'attachment_type' => 'general',
            ]);
        }

        return $media;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithCampaignScope(Campaign $campaign, array $permissions = []): User
    {
        return $this->userWithScope('campaign', $campaign, null, $permissions);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithSiteScope(MissionSite $site, array $permissions = []): User
    {
        return $this->userWithScope('site', null, $site, $permissions);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithScope(
        string $scopeType,
        ?Campaign $campaign = null,
        ?MissionSite $site = null,
        array $permissions = []
    ): User {
        $role = SystemRole::factory()->create(['scope_type' => $scopeType]);
        $permissionIds = collect($permissions)
            ->map(fn (string $code) => Permission::firstOrCreate(
                ['code' => $code],
                ['label' => $code, 'module' => explode('.', $code)[0]]
            )->id)
            ->all();
        $this->publishRolePermissions($role, $permissionIds);

        $user = User::factory()->create();

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => $scopeType,
            'scoped_campaign_id' => $campaign?->id,
            'scoped_site_id' => $site?->id,
        ]);

        return $user;
    }
}
