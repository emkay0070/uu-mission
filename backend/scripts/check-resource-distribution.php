<?php

/**
 * Diagnostic Script: Resource Distribution Authorization
 *
 * Purpose: Rapid debugging of resource distribution authorization logic
 * without running full PHPUnit suite.
 *
 * Usage: php scripts/check-resource-distribution.php
 *
 * Safety:
 * - Only runs against uu_mission_test database
 * - Uses transactions and always rolls back
 * - Never deletes or modifies existing data
 * - Never disables constraints
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
use App\Models\ResourceVersion;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AuthorizationService;
use App\Services\ResourceDistributionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

echo "=== Resource Distribution Authorization Diagnostic ===\n\n";

// Safety check: verify database
$dbName = DB::connection()->getDatabaseName();
echo "✓ Connected to database: {$dbName}\n";

if ($dbName !== 'uu_mission_test' && $dbName !== 'uu_mission_db') {
    echo "⚠️  WARNING: Unexpected database '{$dbName}'\n";
    echo "Expected 'uu_mission_test' or 'uu_mission_db'\n";
    echo "Proceeding with caution...\n\n";
} elseif ($dbName === 'uu_mission_db') {
    echo "⚠️  WARNING: Running against development database\n";
    echo "All changes will be rolled back, but verify this is intended.\n\n";
} else {
    echo "✓ Using test database (safe)\n\n";
}

// Start transaction for all diagnostic operations
DB::beginTransaction();
try {
    echo "=== Test 1: Inspect user_role_assignments constraint ===\n";

    // Check if table exists
    if (! Schema::hasTable('user_role_assignments')) {
        echo "❌ FAIL: user_role_assignments table does not exist\n";
        throw new Exception('Table missing');
    }
    echo "✓ user_role_assignments table exists\n";

    // Inspect constraint definition
    $constraintQuery = DB::select("
        SELECT 
            conname as constraint_name,
            pg_get_constraintdef(oid) as constraint_definition
        FROM pg_constraint 
        WHERE conrelid = 'user_role_assignments'::regclass 
        AND conname = 'chk_ura_scope_exclusive'
    ");

    if (empty($constraintQuery)) {
        echo "❌ FAIL: chk_ura_scope_exclusive constraint not found\n";
    } else {
        echo '✓ Constraint found: '.$constraintQuery[0]->constraint_name."\n";
        echo '  Definition: '.$constraintQuery[0]->constraint_definition."\n";
    }

    // Inspect scope FK columns
    $columns = DB::select("
        SELECT column_name, data_type, is_nullable
        FROM information_schema.columns
        WHERE table_name = 'user_role_assignments'
        AND column_name LIKE '%scope%'
        ORDER BY ordinal_position
    ");

    echo "\nScope-related columns:\n";
    foreach ($columns as $col) {
        echo "  - {$col->column_name} ({$col->data_type}, nullable: {$col->is_nullable})\n";
    }

    echo "\n=== Test 2: Create valid site-scoped role assignment ===\n";

    // Check if required data exists
    $role = SystemRole::where('code', 'missionary')->first();
    if (! $role) {
        echo "❌ FAIL: missionary role not found in database\n";
        echo "Run: php artisan db:seed --class=DatabaseSeeder\n";
        throw new Exception('Role not found');
    }
    echo "✓ Found role: {$role->code} (id: {$role->id})\n";

    $campaign = Campaign::first();
    if (! $campaign) {
        echo "⚠️  No campaign found, creating minimal test data...\n";
        $pt = ProgrammeType::first();
        if (! $pt) {
            $pt = ProgrammeType::create([
                'code' => 'test',
                'name' => 'Test Programme Type',
                'is_active' => true,
            ]);
        }
        $programme = Programme::first();
        if (! $programme) {
            $programme = Programme::create([
                'name' => 'Test Programme',
                'programme_type_id' => $pt->id,
            ]);
        }
        $orgUnit = OrgUnit::first();
        if (! $orgUnit) {
            $orgUnit = OrgUnit::create([
                'name' => 'Test Org Unit',
                'short_code' => 'TEST',
                'org_unit_type_id' => 1,
            ]);
        }
        $campaign = Campaign::create([
            'name' => 'Diagnostic Test Campaign',
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $orgUnit->id,
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
        ]);
        echo "✓ Created test campaign: {$campaign->name} (id: {$campaign->id})\n";
    } else {
        echo "✓ Found campaign: {$campaign->name} (id: {$campaign->id})\n";
    }

    $site = MissionSite::where('campaign_id', $campaign->id)->first();
    if (! $site) {
        echo "⚠️  No site found, creating minimal test data...\n";
        $site = MissionSite::create([
            'campaign_id' => $campaign->id,
            'name' => 'Diagnostic Test Site',
            'location' => 'Test Location',
        ]);
        echo "✓ Created test site: {$site->name} (id: {$site->id})\n";
    } else {
        echo "✓ Found site: {$site->name} (id: {$site->id})\n";
    }

    // Create test user
    $user = User::create([
        'name' => 'Diagnostic Test User',
        'email' => 'diagnostic_'.time().'@test.local',
        'password' => bcrypt('password'),
    ]);
    echo "✓ Created test user: {$user->email} (id: {$user->id})\n";

    // Attempt to create site-scoped assignment
    echo "\nAttempting to create site-scoped role assignment...\n";
    try {
        $assignment = UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $site->id,
            'role_permission_version_id' => $role->current_permission_version_id,
            'status' => 'active',
        ]);
        echo "✓ PASS: Site-scoped assignment created successfully\n";
        echo "  Assignment ID: {$assignment->id}\n";
        echo "  scope_type: {$assignment->scope_type}\n";
        echo "  scoped_site_id: {$assignment->scoped_site_id}\n";
    } catch (QueryException $e) {
        echo "❌ FAIL: Could not create site-scoped assignment\n";
        echo '  Error: '.$e->getMessage()."\n";
        throw $e;
    }

    echo "\n=== Test 3: Test distribution service authorization ===\n";

    // Create test resource
    $category = ResourceCategory::first();
    if (! $category) {
        echo "❌ FAIL: No resource category found\n";
        throw new Exception('Category not found');
    }
    echo "✓ Found category: {$category->name} (id: {$category->id})\n";

    $resource = Resource::create([
        'title' => 'Diagnostic Test Resource',
        'description' => 'Test resource for diagnostics',
        'category_id' => $category->id,
        'status' => 'draft',
        'created_by_user_id' => $user->id,
        'origin_assignment_id' => $assignment->id,
    ]);
    echo "✓ Created test resource: {$resource->title} (id: {$resource->id})\n";

    // Test distribution service
    $distributionService = app(ResourceDistributionService::class);
    $authService = app(AuthorizationService::class);

    echo "\nTesting contributionAssignment()...\n";
    $contributionAssignment = $distributionService->contributionAssignment($user);
    if ($contributionAssignment) {
        echo "✓ PASS: User has contribution assignment\n";
        echo "  Assignment ID: {$contributionAssignment->id}\n";
        echo "  scope_type: {$contributionAssignment->scope_type}\n";
    } else {
        echo "❌ FAIL: User has no contribution assignment\n";
    }

    echo "\nTesting hasPermissionForAudienceTarget()...\n";
    $hasPermission = $authService->hasPermissionForAudienceTarget(
        $user,
        'resources.view',
        'site',
        null,
        null,
        $site->id,
        null
    );
    if ($hasPermission) {
        echo "✓ PASS: User has resources.view permission at site scope\n";
    } else {
        echo "❌ FAIL: User lacks resources.view permission at site scope\n";
    }

    echo "\n=== Test 4: Distribution request submission ===\n";

    try {
        // Create a resource version first (required for distribution)
        $version = ResourceVersion::create([
            'resource_id' => $resource->id,
            'file_path' => 'test.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'test/test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1000,
        ]);
        $resource->update(['current_version_id' => $version->id]);
        echo "✓ Created resource version (id: {$version->id})\n";

        // Submit distribution request
        $request = $distributionService->submitRequest(
            $resource,
            $user,
            [
                'audience_type' => 'campaign',
                'target_campaign_id' => $campaign->id,
            ],
            null
        );
        echo "✓ PASS: Distribution request submitted\n";
        echo "  Request ID: {$request->id}\n";
        echo "  audience_type: {$request->audience_type}\n";
        echo "  status: {$request->status}\n";
    } catch (Exception $e) {
        echo "❌ FAIL: Distribution request submission failed\n";
        echo '  Error: '.$e->getMessage()."\n";
        if ($e instanceof ValidationException) {
            echo '  Validation errors: '.json_encode($e->errors())."\n";
        }
        throw $e;
    }

    echo "\n=== All Tests PASSED ===\n";

} catch (Exception $e) {
    echo "\n=== Diagnostic FAILED ===\n";
    echo 'Exception: '.get_class($e)."\n";
    echo 'Message: '.$e->getMessage()."\n";
    echo 'File: '.$e->getFile().':'.$e->getLine()."\n";

    if ($e->getPrevious()) {
        echo "\nPrevious exception:\n";
        echo '  '.get_class($e->getPrevious()).': '.$e->getPrevious()->getMessage()."\n";
    }
} finally {
    // Always rollback to leave test database clean
    DB::rollBack();
    echo "\n✓ Transaction rolled back - no data persisted\n";
}

echo "\n=== Diagnostic Complete ===\n";
