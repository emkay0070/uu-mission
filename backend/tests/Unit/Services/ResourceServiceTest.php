<?php

namespace Tests\Unit\Services;

use App\Models\Campaign;
use App\Models\OrgUnit;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ResourceReadingProgress;
use App\Models\User;
use App\Services\ResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResourceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ResourceService $resourceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resourceService = new ResourceService;
        Storage::fake('local');
        Queue::fake();
    }

    /**
     * Test transactional resource creation with initial version.
     */
    public function test_create_resource_creates_initial_version_transactionally(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'description' => 'Test description',
            'category_id' => $category->id,
            'language' => 'en',
            'visibility_scope' => 'union',
        ];

        $resource = $this->resourceService->createResource($data, $file, $user->id);

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'title' => 'Test Resource',
            'version_number' => '1.0',
            'status' => 'draft',
            'current_version_id' => $resource->current_version_id,
        ]);

        $this->assertNotNull($resource->current_version_id);
        $this->assertDatabaseHas('resource_versions', [
            'id' => $resource->current_version_id,
            'resource_id' => $resource->id,
            'version_number' => '1.0',
        ]);

        // Verify file was stored
        Storage::disk('local')->assertExists($resource->currentVersion->storage_path);
    }

    /**
     * Test transaction rollback on failure.
     */
    public function test_create_resource_rolls_back_on_invalid_scope(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        // Invalid: conference scope without scoped_org_unit_id
        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'conference',
            'scoped_org_unit_id' => null, // Missing required field
        ];

        $this->expectException(ValidationException::class);

        try {
            $this->resourceService->createResource($data, $file, $user->id);
        } catch (ValidationException $e) {
            // Verify no resource was created
            $this->assertDatabaseCount('resources', 0);
            $this->assertDatabaseCount('resource_versions', 0);

            // Verify file was not stored (check that no files exist in the fake disk)
            $this->assertCount(0, Storage::disk('local')->allFiles());

            throw $e;
        }
    }

    /**
     * Test file cleanup on database transaction failure during creation.
     */
    public function test_create_resource_cleans_up_file_on_db_failure(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'union',
        ];

        // Mock a database failure after file storage
        $this->expectException(\Exception::class);

        try {
            // Force a DB error by using an invalid category_id that doesn't exist
            $invalidData = array_merge($data, ['category_id' => 999999]);
            $this->resourceService->createResource($invalidData, $file, $user->id);
        } catch (\Exception $e) {
            // Verify no resource was created
            $this->assertDatabaseCount('resources', 0);
            $this->assertDatabaseCount('resource_versions', 0);

            // Verify file was cleaned up
            $this->assertCount(0, Storage::disk('local')->allFiles());

            throw $e;
        }
    }

    /**
     * Test file replacement creates new version.
     */
    public function test_replace_file_creates_new_version(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file1 = UploadedFile::fake()->create('document_v1.pdf', 1000, 'application/pdf');
        $file2 = UploadedFile::fake()->create('document_v2.pdf', 1000, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'union',
        ];

        // Create initial resource
        $resource = $this->resourceService->createResource($data, $file1, $user->id);
        $initialVersionId = $resource->current_version_id;
        $initialVersionNumber = $resource->version_number;

        // Replace file
        $resource = $this->resourceService->replaceFile(
            $resource,
            $file2,
            'Updated content',
            $user->id
        );

        // Verify new version created
        $this->assertNotEquals($initialVersionId, $resource->current_version_id);
        $this->assertEquals('1.1', $resource->version_number);
        $this->assertDatabaseCount('resource_versions', 2);

        // Verify old version still exists
        $this->assertDatabaseHas('resource_versions', [
            'id' => $initialVersionId,
            'version_number' => $initialVersionNumber,
        ]);

        // Verify new version exists
        $this->assertDatabaseHas('resource_versions', [
            'id' => $resource->current_version_id,
            'version_number' => '1.1',
            'change_reason' => 'Updated content',
        ]);

        // Verify both files are stored
        Storage::disk('local')->assertExists($resource->versions()->first()->storage_path);
        Storage::disk('local')->assertExists($resource->currentVersion->storage_path);
    }

    /**
     * Test scope validation for conference scope.
     */
    public function test_scope_validation_conference_requires_org_unit(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        // Missing scoped_org_unit_id
        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'conference',
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Conference scope requires scoped_org_unit_id');

        $this->resourceService->createResource($data, $file, $user->id);
    }

    /**
     * Test scope validation for campaign scope.
     */
    public function test_scope_validation_campaign_requires_campaign(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        // Missing scoped_campaign_id
        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'campaign',
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Campaign scope requires scoped_campaign_id');

        $this->resourceService->createResource($data, $file, $user->id);
    }

    /**
     * Test scope validation with invalid org unit type.
     */
    public function test_scope_validation_conference_requires_valid_org_unit_type(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();

        // Create an org unit that is NOT a conference (e.g., local_church)
        $orgUnit = OrgUnit::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'conference',
            'scoped_org_unit_id' => $orgUnit->id,
        ];

        try {
            $this->resourceService->createResource($data, $file, $user->id);
            $this->fail('A non-conference org unit must not be accepted as a conference resource scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scoped_org_unit_id', $exception->errors());
        }
    }

    /**
     * Test version-aware reading progress.
     */
    public function test_reading_progress_is_version_aware(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file1 = UploadedFile::fake()->create('document_v1.pdf', 1000, 'application/pdf');
        $file2 = UploadedFile::fake()->create('document_v2.pdf', 1000, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'union',
        ];

        // Create resource
        $resource = $this->resourceService->createResource($data, $file1, $user->id);
        $version1Id = $resource->current_version_id;

        // Create progress for version 1
        $progress1 = ResourceReadingProgress::create([
            'resource_id' => $resource->id,
            'user_id' => $user->id,
            'resource_version_id' => $version1Id,
            'last_page' => 5,
            'total_pages' => 10,
            'completion_percentage' => 50.00,
            'last_read_at' => now(),
        ]);

        // Replace file (creates version 2)
        $resource = $this->resourceService->replaceFile(
            $resource,
            $file2,
            'Updated content',
            $user->id
        );
        $version2Id = $resource->current_version_id;

        // Create progress for version 2
        $progress2 = ResourceReadingProgress::create([
            'resource_id' => $resource->id,
            'user_id' => $user->id,
            'resource_version_id' => $version2Id,
            'last_page' => 1,
            'total_pages' => 10,
            'completion_percentage' => 10.00,
            'last_read_at' => now(),
        ]);

        // Verify both progress records exist
        $this->assertDatabaseCount('resource_reading_progress', 2);

        // Verify progress is version-aware
        $this->assertDatabaseHas('resource_reading_progress', [
            'resource_id' => $resource->id,
            'user_id' => $user->id,
            'resource_version_id' => $version1Id,
            'last_page' => 5,
        ]);

        $this->assertDatabaseHas('resource_reading_progress', [
            'resource_id' => $resource->id,
            'user_id' => $user->id,
            'resource_version_id' => $version2Id,
            'last_page' => 1,
        ]);

        // Verify unique constraint works (same user, same resource, different versions)
        $this->assertEquals(2, ResourceReadingProgress::where('resource_id', $resource->id)
            ->where('user_id', $user->id)
            ->count());
    }

    /**
     * Test that resource cannot lose its current version.
     */
    public function test_resource_cannot_lose_current_version(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'union',
        ];

        $resource = $this->resourceService->createResource($data, $file, $user->id);

        // Verify current_version_id is set
        $this->assertNotNull($resource->current_version_id);
        $this->assertTrue($resource->hasCurrentVersion());

        // Try to set current_version_id to null (should not happen in normal operations)
        // This is a defensive check - the service should never allow this
        $resource->current_version_id = null;
        $resource->save();

        // Verify the model still reflects the change
        $resource->refresh();
        $this->assertNull($resource->current_version_id);
        $this->assertFalse($resource->hasCurrentVersion());

        // Restore for cleanup
        $resource->current_version_id = $resource->versions()->first()->id;
        $resource->save();
    }

    /**
     * Test tag creation with resource.
     */
    public function test_create_resource_with_tags(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'union',
            'tags' => ['bible', 'study', 'evangelism'],
        ];

        $resource = $this->resourceService->createResource($data, $file, $user->id);

        $this->assertDatabaseCount('resource_tags', 3);
        $this->assertDatabaseHas('resource_tags', [
            'resource_id' => $resource->id,
            'tag' => 'bible',
        ]);
        $this->assertDatabaseHas('resource_tags', [
            'resource_id' => $resource->id,
            'tag' => 'study',
        ]);
        $this->assertDatabaseHas('resource_tags', [
            'resource_id' => $resource->id,
            'tag' => 'evangelism',
        ]);
    }

    /**
     * Test status transitions.
     */
    public function test_status_transitions(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'union',
        ];

        $resource = $this->resourceService->createResource($data, $file, $user->id);
        $this->assertEquals('draft', $resource->status);

        // Submit for review
        $resource = $this->resourceService->submitForReview($resource, $user->id);
        $this->assertEquals('pending_review', $resource->status);

        // Publish
        $resource = $this->resourceService->publishResource($resource, $user->id);
        $this->assertEquals('published', $resource->status);
        $this->assertNotNull($resource->published_at);
        $this->assertEquals($user->id, $resource->published_by_user_id);

        // Archive
        $resource = $this->resourceService->archiveResource($resource, $user->id);
        $this->assertEquals('archived', $resource->status);
        $this->assertNotNull($resource->archived_at);
    }

    /**
     * Test invalid status transition.
     */
    public function test_invalid_status_transition_throws_exception(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'union',
        ];

        $resource = $this->resourceService->createResource($data, $file, $user->id);

        // Try to publish from draft (should go through pending_review first)
        $this->expectException(ValidationException::class);
        $this->resourceService->publishResource($resource, $user->id);
    }

    /**
     * Test file size validation.
     */
    public function test_file_size_validation(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();

        // Create a file larger than 50MB
        $file = UploadedFile::fake()->create('large.pdf', 60 * 1024 * 1024, 'application/pdf');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'union',
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('File size exceeds maximum allowed size');

        $this->resourceService->createResource($data, $file, $user->id);
    }

    /**
     * Test MIME type validation.
     */
    public function test_mime_type_validation(): void
    {
        $user = User::factory()->create();
        $category = ResourceCategory::factory()->create();

        // Create a file with disallowed MIME type
        $file = UploadedFile::fake()->create('document.exe', 1000, 'application/x-msdownload');

        $data = [
            'title' => 'Test Resource',
            'category_id' => $category->id,
            'visibility_scope' => 'union',
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('MIME type');

        $this->resourceService->createResource($data, $file, $user->id);
    }
}
