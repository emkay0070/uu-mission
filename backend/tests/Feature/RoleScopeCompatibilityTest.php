<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Policies\UserRoleAssignmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PublishesRolePermissions;

/**
 * Verifies the role-scope compatibility matrix end to end:
 * every role creation path populates it, the repair migration restores it
 * idempotently, and UserRoleAssignmentPolicy still enforces scoped access.
 */
class RoleScopeCompatibilityTest extends TestCase
{
    use PublishesRolePermissions;
    use RefreshDatabase;

    private const EXPECTED_MATRIX = [
        'global' => ['global'],
        'org_unit' => ['org_unit'],
        'campaign' => ['org_unit', 'campaign'],
        'site' => ['org_unit', 'campaign', 'site'],
        'team' => ['org_unit', 'campaign', 'site', 'team'],
    ];

    private const SEEDED_ROLE_CODES = [
        'platform_admin',
        'union_director',
        'campaign_leader',
        'site_secretary',
        'site_coordinator',
        'team_leader',
        'team_secretary',
        'missionary',
    ];

    private const REPAIR_MIGRATION = 'migrations/2026_11_10_000000_repair_role_scope_compatibilities_for_all_roles.php';

    /**
     * @return array<int, string>
     */
    private function expectedScopes(string $scopeType): array
    {
        return self::EXPECTED_MATRIX[$scopeType] ?? [$scopeType];
    }

    /**
     * @return array<int, string>
     */
    private function actualScopes(SystemRole $role): array
    {
        return DB::table('role_scope_compatibilities')
            ->where('system_role_id', $role->id)
            ->orderBy('scope_type')
            ->pluck('scope_type')
            ->all();
    }

    private function assertExactCompatibilityMatrix(SystemRole $role): void
    {
        $expected = $this->expectedScopes($role->scope_type);
        sort($expected);

        $this->assertSame(
            $expected,
            $this->actualScopes($role),
            "Role [{$role->code}] with scope [{$role->scope_type}] does not have its exact compatibility matrix."
        );
    }

    private function assertNoDuplicateCompatibilityRecords(): void
    {
        $duplicates = DB::table('role_scope_compatibilities')
            ->select('system_role_id', 'scope_type')
            ->groupBy('system_role_id', 'scope_type')
            ->havingRaw('count(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates, 'Duplicate compatibility records were created.');
    }

    private function runRepairMigration(): void
    {
        $migration = require database_path(self::REPAIR_MIGRATION);
        $migration->up();
    }

    private function createRole(string $code, string $scopeType): SystemRole
    {
        return SystemRole::create([
            'code' => $code,
            'label' => ucwords(str_replace('_', ' ', $code)),
            'scope_type' => $scopeType,
            'is_active' => true,
        ]);
    }

    private function createOneRolePerScope(): void
    {
        foreach (array_keys(self::EXPECTED_MATRIX) as $scopeType) {
            $this->createRole('matrix_'.$scopeType.'_role', $scopeType);
        }
    }

    // ── Seeder path (WithoutModelEvents) ─────────────────────────────────────

    public function test_database_seeder_creates_all_eight_roles_with_exact_compatibility_records(): void
    {
        $this->seed();

        $this->assertSame(count(self::SEEDED_ROLE_CODES), SystemRole::count());

        foreach (self::SEEDED_ROLE_CODES as $code) {
            $role = SystemRole::where('code', $code)->firstOrFail();
            $this->assertExactCompatibilityMatrix($role);
        }

        $expectedRecords = SystemRole::orderBy('id')->get()
            ->sum(fn (SystemRole $role): int => count($this->expectedScopes($role->scope_type)));

        $this->assertSame($expectedRecords, DB::table('role_scope_compatibilities')->count());
        $this->assertNoDuplicateCompatibilityRecords();
    }

    public function test_database_seeder_repairs_existing_roles_missing_compatibility_records(): void
    {
        $role = SystemRole::withoutEvents(fn (): SystemRole => $this->createRole('team_leader', 'team'));

        $this->assertSame([], $this->actualScopes($role));

        $this->seed();

        $this->assertExactCompatibilityMatrix($role->fresh());
    }

    // ── Eloquent and factory creation paths ──────────────────────────────────

    public function test_newly_created_roles_receive_compatibility_records_for_every_scope_type(): void
    {
        foreach (array_keys(self::EXPECTED_MATRIX) as $scopeType) {
            $role = $this->createRole('new_'.$scopeType.'_role', $scopeType);

            $this->assertExactCompatibilityMatrix($role);
        }

        $this->assertNoDuplicateCompatibilityRecords();
    }

    public function test_factory_created_roles_receive_compatibility_records(): void
    {
        $role = SystemRole::factory()->create(['scope_type' => 'team']);

        $this->assertExactCompatibilityMatrix($role);
    }

    // ── Repair migration ─────────────────────────────────────────────────────

    public function test_repair_migration_restores_missing_records_without_removing_existing_ones(): void
    {
        $teamRole = $this->createRole('repair_team_role', 'team');
        $siteRole = $this->createRole('repair_site_role', 'site');

        DB::table('role_scope_compatibilities')
            ->where('system_role_id', $teamRole->id)
            ->whereIn('scope_type', ['org_unit', 'campaign'])
            ->delete();

        DB::table('role_scope_compatibilities')
            ->where('system_role_id', $siteRole->id)
            ->where('scope_type', 'site')
            ->delete();

        $this->runRepairMigration();

        $this->assertExactCompatibilityMatrix($teamRole->fresh());
        $this->assertExactCompatibilityMatrix($siteRole->fresh());
        $this->assertNoDuplicateCompatibilityRecords();
    }

    public function test_repair_migration_is_idempotent_and_preserves_permission_delegations(): void
    {
        $this->createOneRolePerScope();

        $permission = Permission::firstOrCreate(
            ['code' => 'campaigns.view'],
            ['label' => 'View campaigns', 'module' => 'campaigns']
        );

        DB::table('permission_delegations')->insert([
            'permission_id' => $permission->id,
            'scope_type' => 'global',
            'is_active' => true,
            'reason' => 'Fixture delegation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $compatibilitiesBefore = DB::table('role_scope_compatibilities')
            ->orderBy('system_role_id')
            ->orderBy('scope_type')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $delegationsBefore = DB::table('permission_delegations')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->runRepairMigration();
        $this->runRepairMigration();

        $this->assertSame($compatibilitiesBefore, DB::table('role_scope_compatibilities')
            ->orderBy('system_role_id')
            ->orderBy('scope_type')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all());

        $this->assertSame($delegationsBefore, DB::table('permission_delegations')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all());

        $this->assertNoDuplicateCompatibilityRecords();
    }

    public function test_repair_migration_preserves_all_seeded_permission_delegations(): void
    {
        $this->seed();

        $delegationsBefore = DB::table('permission_delegations')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->assertCount(Permission::count(), $delegationsBefore);

        $this->runRepairMigration();

        $this->assertSame($delegationsBefore, DB::table('permission_delegations')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all());
    }

    // ── Integrity command ────────────────────────────────────────────────────

    public function test_integrity_command_detects_and_repairs_missing_records(): void
    {
        $role = $this->createRole('diagnostic_team_role', 'team');

        DB::table('role_scope_compatibilities')
            ->where('system_role_id', $role->id)
            ->where('scope_type', 'campaign')
            ->delete();

        $this->artisan('rbac:check-compatibilities')
            ->expectsOutputToContain('missing compatibility record')
            ->assertExitCode(1);

        $this->artisan('rbac:check-compatibilities', ['--repair' => true])
            ->assertExitCode(0);

        $this->assertExactCompatibilityMatrix($role->fresh());

        $this->artisan('rbac:check-compatibilities')
            ->expectsOutputToContain('All roles have their expected compatibility records.')
            ->assertExitCode(0);
    }

    // ── Policy behaviour ─────────────────────────────────────────────────────

    public function test_policy_requires_scope_compatibility_record(): void
    {
        $managePermission = Permission::firstOrCreate(
            ['code' => 'assignments.manage'],
            ['label' => 'Manage assignments', 'module' => 'assignments']
        );
        $viewPermission = Permission::firstOrCreate(
            ['code' => 'campaigns.view'],
            ['label' => 'View campaigns', 'module' => 'campaigns']
        );

        $managerRole = $this->createRole('policy_manager_role', 'global');
        $this->publishRolePermissions($managerRole, [$managePermission->id]);

        $manager = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $manager->id,
            'system_role_id' => $managerRole->id,
            'scope_type' => 'global',
        ]);

        $targetRole = $this->createRole('policy_target_role', 'campaign');
        $this->publishRolePermissions($targetRole, [$viewPermission->id]);

        $campaign = Campaign::factory()->create();
        $targetUser = User::factory()->create();

        DB::table('permission_delegations')->insert([
            'permission_id' => $viewPermission->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id,
            'is_active' => true,
            'reason' => 'Fixture delegation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_scope_compatibilities')
            ->where('system_role_id', $targetRole->id)
            ->delete();

        $payload = [
            'user_id' => $targetUser->id,
            'system_role_id' => $targetRole->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id,
        ];

        $policy = app(UserRoleAssignmentPolicy::class);

        $this->assertFalse($policy->create($manager, $payload));

        $targetRole->syncScopeCompatibilities();

        $this->assertTrue($policy->create($manager, $payload));
    }

    // ── Scoped authorization ─────────────────────────────────────────────────

    public function test_global_administrator_can_assign_role_within_authorised_scope(): void
    {
        $this->seed();

        $adminRole = SystemRole::where('code', 'platform_admin')->firstOrFail();
        $adminUser = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $adminUser->id,
            'system_role_id' => $adminRole->id,
            'scope_type' => 'global',
        ]);

        $campaign = Campaign::factory()->create();
        $site = MissionSite::factory()->create(['campaign_id' => $campaign->id]);
        $targetRole = SystemRole::where('code', 'site_coordinator')->firstOrFail();
        $targetUser = User::factory()->create();

        $this->actingAs($adminUser, 'sanctum')
            ->postJson('/api/v1/role-assignments', [
                'user_id' => $targetUser->id,
                'system_role_id' => $targetRole->id,
                'scope_type' => 'site',
                'scoped_site_id' => $site->id,
            ])
            ->assertCreated();
    }

    public function test_campaign_scoped_coordinator_can_assign_role_within_authorised_scope(): void
    {
        $this->seed();

        $campaign = Campaign::factory()->create();
        $site = MissionSite::factory()->create(['campaign_id' => $campaign->id]);

        $coordinator = $this->createCampaignCoordinator($campaign);
        $targetRole = SystemRole::where('code', 'site_coordinator')->firstOrFail();
        $targetUser = User::factory()->create();

        $this->actingAs($coordinator, 'sanctum')
            ->postJson('/api/v1/role-assignments', [
                'user_id' => $targetUser->id,
                'system_role_id' => $targetRole->id,
                'scope_type' => 'site',
                'scoped_site_id' => $site->id,
            ])
            ->assertCreated();
    }

    public function test_campaign_scoped_coordinator_cannot_assign_role_outside_authorised_scope(): void
    {
        $this->seed();

        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();
        $siteB = MissionSite::factory()->create(['campaign_id' => $campaignB->id]);

        $coordinator = $this->createCampaignCoordinator($campaignA);
        $targetRole = SystemRole::where('code', 'site_coordinator')->firstOrFail();
        $targetUser = User::factory()->create();

        $this->actingAs($coordinator, 'sanctum')
            ->postJson('/api/v1/role-assignments', [
                'user_id' => $targetUser->id,
                'system_role_id' => $targetRole->id,
                'scope_type' => 'site',
                'scoped_site_id' => $siteB->id,
            ])
            ->assertForbidden();
    }

    private function createCampaignCoordinator(Campaign $campaign): User
    {
        $managePermission = Permission::where('code', 'assignments.manage')->firstOrFail();
        $role = $this->createRole('campaign_coordinator_'.$campaign->id, 'campaign');
        $this->publishRolePermissions($role, [$managePermission->id]);

        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id,
        ]);

        return $user;
    }
}
