<?php

/**
 * Standalone File Security Test Runner
 *
 * Runs ResourceFileSecurityTest without PHPUnit overhead
 */

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Http\Controllers\ResourceFileController;
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
use App\Services\ResourceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

echo "=== Resource File Security Tests ===\n\n";

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

    $pt = ProgrammeType::first();
    $programme = Programme::first();
    $orgUnit = OrgUnit::first();

    $campaignA = Campaign::first();
    if (! $campaignA) {
        $campaignA = Campaign::create([
            'name' => 'Test Campaign A',
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $orgUnit->id,
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
        ]);
    }

    $campaignB = Campaign::where('name', 'Test Campaign B')->first();
    if (! $campaignB) {
        $campaignB = Campaign::create([
            'name' => 'Test Campaign B',
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $orgUnit->id,
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
        ]);
    }

    $siteA1 = MissionSite::where('campaign_id', $campaignA->id)->first();
    if (! $siteA1) {
        $siteA1 = MissionSite::create([
            'campaign_id' => $campaignA->id,
            'name' => 'Test Site A1',
            'location' => 'Location A1',
        ]);
    }

    $siteB1 = MissionSite::where('campaign_id', $campaignB->id)->first();
    if (! $siteB1) {
        $siteB1 = MissionSite::create([
            'campaign_id' => $campaignB->id,
            'name' => 'Test Site B1',
            'location' => 'Location B1',
        ]);
    }

    $category = ResourceCategory::first();
    if (! $category) {
        $category = ResourceCategory::create([
            'code' => 'test',
            'label' => 'Test Category',
            'description' => 'Test category',
            'is_active' => true,
        ]);
    }

    Storage::fake('local');

    echo "✓ Fixtures ready\n\n";

    // Declare variables outside try blocks for scope sharing
    $missionaryA = null;
    $missionaryB = null;
    $approverA = null;
    $resource = null;
    $request = null;

    // Test 1: Unauthorized user cannot preview
    echo "Test 1: Unauthorized user cannot preview file\n";
    try {
        $missionaryA = User::create(['name' => 'MA', 'email' => 'ma@test.com', 'password' => bcrypt('pass')]);
        $missionaryB = User::create(['name' => 'MB', 'email' => 'mb@test.com', 'password' => bcrypt('pass')]);
        $approverA = User::create(['name' => 'AA', 'email' => 'aa@test.com', 'password' => bcrypt('pass')]);

        UserRoleAssignment::create([
            'user_id' => $missionaryA->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $siteA1->id,
            'role_permission_version_id' => $role->current_permission_version_id,
            'status' => 'active',
        ]);

        UserRoleAssignment::create([
            'user_id' => $missionaryB->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $siteB1->id,
            'role_permission_version_id' => $role->current_permission_version_id,
            'status' => 'active',
        ]);

        UserRoleAssignment::create([
            'user_id' => $approverA->id,
            'system_role_id' => $leaderRole->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaignA->id,
            'role_permission_version_id' => $leaderRole->current_permission_version_id,
            'status' => 'active',
        ]);

        $file = UploadedFile::fake()->create('doc.pdf', 1000);
        $resource = Resource::create([
            'uuid' => Str::uuid(),
            'title' => 'Test Resource',
            'description' => 'Test',
            'category_id' => $category->id,
            'status' => 'draft',
            'created_by_user_id' => $missionaryA->id,
        ]);

        $version = ResourceVersion::create([
            'resource_id' => $resource->id,
            'version_number' => '1.0',
            'storage_disk' => 'local',
            'storage_path' => 'test/test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1000,
        ]);
        $resource->update(['current_version_id' => $version->id]);

        $request = ResourceDistributionRequest::create([
            'resource_id' => $resource->id,
            'requested_by_user_id' => $missionaryA->id,
            'audience_type' => 'campaign',
            'target_campaign_id' => $campaignA->id,
            'audience_key' => 'campaign:'.$campaignA->id,
            'resource_version_id' => $version->id,
            'status' => 'approved',
            'submitted_at' => now(),
            'decided_at' => now(),
            'decided_by_user_id' => $approverA->id,
        ]);

        ResourceDistributionRelease::create([
            'resource_id' => $resource->id,
            'resource_distribution_request_id' => $request->id,
            'resource_version_id' => $version->id,
            'audience_type' => 'campaign',
            'audience_key' => 'campaign:'.$campaignA->id,
            'released_by_user_id' => $approverA->id,
            'released_at' => now(),
        ]);

        // Test unauthorized access
        auth()->login($missionaryB);
        $controller = new ResourceFileController(app(ResourceService::class));

        try {
            $controller->preview(new Request, $resource);
            echo "❌ FAIL: Should have denied access\n";
            $failed++;
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                echo "✓ PASS: Unauthorized access denied (403)\n";
                $passed++;
            } else {
                echo "❌ FAIL: Wrong status code {$e->getStatusCode()}\n";
                $failed++;
            }
        }

    } catch (Exception $e) {
        echo '❌ FAIL: '.$e->getMessage()."\n";
        $failed++;
    }

    // Test 2: Authorized user can preview
    echo "\nTest 2: Authorized user can preview file\n";
    try {
        auth()->login($missionaryA);
        Storage::disk('local')->put('test/test.pdf', 'test content');

        $controller = new ResourceFileController(app(ResourceService::class));
        $response = $controller->preview(new Request, $resource);

        if ($response->getStatusCode() === 200) {
            echo "✓ PASS: Authorized access granted (200)\n";
            $passed++;
        } else {
            echo "❌ FAIL: Wrong status code {$response->getStatusCode()}\n";
            $failed++;
        }

    } catch (Exception $e) {
        echo '❌ FAIL: '.$e->getMessage()."\n";
        $failed++;
    }

    // Test 3: Revoked access blocks preview
    echo "\nTest 3: Revoked access blocks preview\n";
    try {
        $request->update(['status' => 'revoked']);
        ResourceDistributionRelease::where('resource_distribution_request_id', $request->id)
            ->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $approverA->id,
                'revocation_reason' => 'Test revocation',
            ]);

        auth()->login($missionaryA);
        $controller = new ResourceFileController(app(ResourceService::class));

        try {
            $controller->preview(new Request, $resource);
            echo "❌ FAIL: Should have denied access after revocation\n";
            $failed++;
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                echo "✓ PASS: Revoked access denied (403)\n";
                $passed++;
            } else {
                echo "❌ FAIL: Wrong status code {$e->getStatusCode()}\n";
                $failed++;
            }
        }

    } catch (Exception $e) {
        echo '❌ FAIL: '.$e->getMessage()."\n";
        $failed++;
    }

    // Test 4: Archived resource blocks access
    echo "\nTest 4: Archived resource blocks access\n";
    try {
        $resource->update(['archived_at' => now()]);

        auth()->login($missionaryA);
        $controller = new ResourceFileController(app(ResourceService::class));

        try {
            $controller->preview(new Request, $resource);
            echo "❌ FAIL: Should have denied access to archived resource\n";
            $failed++;
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                echo "✓ PASS: Archived resource access denied (403)\n";
                $passed++;
            } else {
                echo "❌ FAIL: Wrong status code {$e->getStatusCode()}\n";
                $failed++;
            }
        }

    } catch (Exception $e) {
        echo '❌ FAIL: '.$e->getMessage()."\n";
        $failed++;
    }

    echo "\n=== Results ===\n";
    echo "Passed: {$passed}\n";
    echo "Failed: {$failed}\n";

    if ($failed === 0) {
        echo "\n✓ ALL TESTS PASSED\n";
    } else {
        echo "\n❌ SOME TESTS FAILED\n";
    }

} catch (Exception $e) {
    echo "\n❌ FATAL ERROR: ".$e->getMessage()."\n";
    echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
    echo "\n✓ Transaction rolled back\n";
}

echo "\n=== Complete ===\n";
