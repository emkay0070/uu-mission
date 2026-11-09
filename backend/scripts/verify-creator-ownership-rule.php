<?php

/**
 * Creator Ownership Architectural Rule Verification
 *
 * Verifies that creator ownership does NOT automatically grant reader access to published resources.
 *
 * Required scenarios:
 * 1. Creator can view and edit their own unpublished draft.
 * 2. Creator cannot read a published resource solely because they created it, unless approved release.
 * 3. Creator can read a published resource when their account is in an approved audience.
 */

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ResourceDistributionRelease;
use App\Models\ResourceDistributionRequest;
use App\Models\ResourceVersion;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Policies\ResourcePolicy;
use App\Services\AuthorizationService;
use App\Services\ResourceVisibilityService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

echo "=== Creator Ownership Architectural Rule Verification ===\n\n";

$dbName = DB::connection()->getDatabaseName();
echo "Database: {$dbName}\n";

DB::beginTransaction();
$passed = 0;
$failed = 0;

try {
    // Setup fixtures
    echo "Setting up test fixtures...\n";

    $role = SystemRole::where('code', 'missionary')->first();
    $leaderRole = SystemRole::where('code', 'campaign_leader')->first();

    $cuc = OrgUnit::where('short_code', 'CUC')->first();
    $pt = ProgrammeType::where('code', 'global_mission')->first();
    $programme = Programme::where('programme_type_id', $pt->id)->first();

    $campaign = Campaign::first();
    if (! $campaign) {
        $campaign = Campaign::create([
            'name' => 'Test Campaign',
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $cuc->id,
            'status' => 'active',
        ]);
    }

    $site = MissionSite::where('campaign_id', $campaign->id)->first();
    if (! $site) {
        $site = MissionSite::create([
            'campaign_id' => $campaign->id,
            'name' => 'Test Site',
        ]);
    }

    $category = ResourceCategory::first();
    if (! $category) {
        $category = ResourceCategory::create([
            'code' => 'test',
            'label' => 'Test Category',
            'is_active' => true,
        ]);
    }

    Storage::fake('local');

    echo "✓ Fixtures ready\n\n";

    // Scenario 1: Creator can view and edit their own unpublished draft
    echo "Scenario 1: Creator can view and edit their own unpublished draft\n";
    try {
        $creator = User::create(['name' => 'Creator', 'email' => 'creator@test.com', 'password' => bcrypt('pass')]);

        UserRoleAssignment::create([
            'user_id' => $creator->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $site->id,
            'role_permission_version_id' => $role->current_permission_version_id,
            'status' => 'active',
        ]);

        $assignment = UserRoleAssignment::where('user_id', $creator->id)->first();

        $draftResource = Resource::create([
            'uuid' => Str::uuid(),
            'title' => 'Draft Resource',
            'category_id' => $category->id,
            'status' => 'draft',
            'created_by_user_id' => $creator->id,
            'origin_assignment_id' => $assignment->id,
        ]);

        $version = ResourceVersion::create([
            'resource_id' => $draftResource->id,
            'version_number' => '1.0',
            'storage_disk' => 'local',
            'storage_path' => 'test/test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1000,
        ]);
        $draftResource->update(['current_version_id' => $version->id]);

        $policy = new ResourcePolicy(app(AuthorizationService::class), app(ResourceVisibilityService::class));

        $canViewDraft = $policy->view($creator, $draftResource);
        $canEditDraft = $policy->update($creator, $draftResource);

        if ($canViewDraft && $canEditDraft) {
            echo "✓ PASS: Creator can view and edit their own draft\n";
            $passed++;
        } else {
            echo '❌ FAIL: Creator cannot view/edit their own draft (view: '.($canViewDraft ? 'true' : 'false').', edit: '.($canEditDraft ? 'true' : 'false').")\n";
            $failed++;
        }

    } catch (Exception $e) {
        echo '❌ FAIL: '.$e->getMessage()."\n";
        $failed++;
    }

    // Scenario 2: Creator cannot read published resource without approved release
    echo "\nScenario 2: Creator cannot read published resource without approved release\n";
    try {
        $creator2 = User::create(['name' => 'Creator2', 'email' => 'creator2@test.com', 'password' => bcrypt('pass')]);

        UserRoleAssignment::create([
            'user_id' => $creator2->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $site->id,
            'role_permission_version_id' => $role->current_permission_version_id,
            'status' => 'active',
        ]);

        $assignment2 = UserRoleAssignment::where('user_id', $creator2->id)->first();

        $publishedResource = Resource::create([
            'uuid' => Str::uuid(),
            'title' => 'Published Resource',
            'category_id' => $category->id,
            'status' => 'published',
            'created_by_user_id' => $creator2->id,
            'origin_assignment_id' => $assignment2->id,
        ]);

        $version2 = ResourceVersion::create([
            'resource_id' => $publishedResource->id,
            'version_number' => '1.0',
            'storage_disk' => 'local',
            'storage_path' => 'test/test2.pdf',
            'original_filename' => 'test2.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1000,
        ]);
        $publishedResource->update(['current_version_id' => $version2->id]);

        // No distribution request/release created - resource is published but not distributed

        $canViewPublished = $policy->view($creator2, $publishedResource);

        if (! $canViewPublished) {
            echo "✓ PASS: Creator cannot view published resource without approved release\n";
            $passed++;
        } else {
            echo "❌ FAIL: Creator CAN view published resource without approved release (VIOLATES ARCHITECTURAL RULE)\n";
            $failed++;
        }

    } catch (Exception $e) {
        echo '❌ FAIL: '.$e->getMessage()."\n";
        $failed++;
    }

    // Scenario 3: Creator can read published resource when in approved audience
    echo "\nScenario 3: Creator can read published resource when in approved audience\n";
    try {
        $creator3 = User::create(['name' => 'Creator3', 'email' => 'creator3@test.com', 'password' => bcrypt('pass')]);
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@test.com', 'password' => bcrypt('pass')]);

        UserRoleAssignment::create([
            'user_id' => $creator3->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $site->id,
            'role_permission_version_id' => $role->current_permission_version_id,
            'status' => 'active',
        ]);

        UserRoleAssignment::create([
            'user_id' => $approver->id,
            'system_role_id' => $leaderRole->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id,
            'role_permission_version_id' => $leaderRole->current_permission_version_id,
            'status' => 'active',
        ]);

        $assignment3 = UserRoleAssignment::where('user_id', $creator3->id)->first();

        $distributedResource = Resource::create([
            'uuid' => Str::uuid(),
            'title' => 'Distributed Resource',
            'category_id' => $category->id,
            'status' => 'published',
            'created_by_user_id' => $creator3->id,
            'origin_assignment_id' => $assignment3->id,
        ]);

        $version3 = ResourceVersion::create([
            'resource_id' => $distributedResource->id,
            'version_number' => '1.0',
            'storage_disk' => 'local',
            'storage_path' => 'test/test3.pdf',
            'original_filename' => 'test3.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1000,
        ]);
        $distributedResource->update(['current_version_id' => $version3->id]);

        // Create and approve distribution request for the campaign
        $request = ResourceDistributionRequest::create([
            'resource_id' => $distributedResource->id,
            'requested_by_user_id' => $creator3->id,
            'audience_type' => 'campaign',
            'target_campaign_id' => $campaign->id,
            'audience_key' => 'campaign:'.$campaign->id,
            'resource_version_id' => $version3->id,
            'status' => 'approved',
            'submitted_at' => now(),
            'decided_at' => now(),
            'decided_by_user_id' => $approver->id,
        ]);

        ResourceDistributionRelease::create([
            'resource_id' => $distributedResource->id,
            'resource_distribution_request_id' => $request->id,
            'resource_version_id' => $version3->id,
            'audience_type' => 'campaign',
            'audience_key' => 'campaign:'.$campaign->id,
            'released_by_user_id' => $approver->id,
            'released_at' => now(),
        ]);

        $canViewDistributed = $policy->view($creator3, $distributedResource);

        if ($canViewDistributed) {
            echo "✓ PASS: Creator can view published resource when in approved audience\n";
            $passed++;
        } else {
            echo "❌ FAIL: Creator cannot view published resource even with approved release\n";
            $failed++;
        }

    } catch (Exception $e) {
        echo '❌ FAIL: '.$e->getMessage()."\n";
        $failed++;
    }

    echo "\n=== Results ===\n";
    echo "Passed: {$passed}/3\n";
    echo "Failed: {$failed}/3\n";

    if ($failed === 0) {
        echo "\n✓ ARCHITECTURAL RULE VERIFIED\n";
    } else {
        echo "\n❌ ARCHITECTURAL RULE VIOLATED\n";
    }

} catch (Exception $e) {
    echo "\n❌ FATAL ERROR: ".$e->getMessage()."\n";
    echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
    echo "\n✓ Transaction rolled back\n";
}

echo "\n=== Complete ===\n";
