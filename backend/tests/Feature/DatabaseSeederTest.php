<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\ResourceCategory;
use App\Models\RolePermissionVersion;
use App\Models\SkillCategory;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function essential_reference_data_is_seeded(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        // Skill categories
        $this->assertTrue(SkillCategory::where('code', 'evangelism')->exists());
        $this->assertTrue(SkillCategory::where('code', 'media')->exists());

        // Languages
        $this->assertTrue(\App\Models\Language::where('code', 'eng')->exists());
        $this->assertTrue(\App\Models\Language::where('code', 'lug')->exists());

        // Org unit types
        $this->assertTrue(OrgUnitType::where('code', 'union')->exists());
        $this->assertTrue(OrgUnitType::where('code', 'conference')->exists());
        $this->assertTrue(OrgUnitType::where('code', 'local_church')->exists());

        // Org unit hierarchy
        $this->assertTrue(OrgUnit::where('short_code', 'UUM')->exists());
        $this->assertTrue(OrgUnit::where('short_code', 'CUC')->exists());

        // Programme types
        $this->assertTrue(ProgrammeType::where('code', 'global_mission')->exists());
        $this->assertTrue(ProgrammeType::where('code', 'youth_for_christ')->exists());

        // Programme
        $this->assertTrue(
            Programme::where('name', 'Uganda Union Global Mission Initiative')->exists()
        );

        // Activity types
        $this->assertTrue(\App\Models\ActivityType::where('code', 'evangelistic_meeting')->exists());

        // Functional role types
        $this->assertTrue(\App\Models\FunctionalRoleType::where('code', 'site_coordinator')->exists());

        // Resource categories
        $this->assertTrue(ResourceCategory::where('code', 'bible_study')->exists());
        $this->assertTrue(ResourceCategory::where('code', 'general')->exists());

        // Outcome types, encounter methods, engagement stages (from dedicated seeders)
        $this->assertTrue(\App\Models\OutcomeType::where('code', 'decision')->exists());
        $this->assertTrue(\App\Models\EncounterMethod::where('code', 'door_to_door')->exists());
        $this->assertTrue(\App\Models\EngagementStage::where('code', 'new_contact')->exists());
    }

    #[Test]
    public function permissions_roles_and_permission_versions_are_created(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        // Permissions exist across modules
        $this->assertTrue(Permission::where('code', 'campaigns.view')->exists());
        $this->assertTrue(Permission::where('code', 'resources.create')->exists());
        $this->assertTrue(Permission::where('code', 'communications.publish')->exists());
        $this->assertTrue(Permission::where('code', 'outcomes.record')->exists());

        // System roles exist with correct scope types
        $this->assertTrue(SystemRole::where('code', 'platform_admin')->exists());
        $this->assertTrue(SystemRole::where('code', 'union_director')->exists());
        $this->assertTrue(SystemRole::where('code', 'site_coordinator')->exists());
        $this->assertTrue(SystemRole::where('code', 'missionary')->exists());

        // Each role has a current permission version
        foreach (SystemRole::all() as $role) {
            $this->assertNotNull($role->current_permission_version_id);
            $this->assertNotNull($role->currentPermissionVersion);
            $this->assertTrue($role->currentPermissionVersion->permissions()->count() > 0);
        }

        // platform_admin has all permissions
        $adminRole = SystemRole::where('code', 'platform_admin')->first();
        $this->assertGreaterThan(20, $adminRole->currentPermissionVersion->permissions->count());
    }

    #[Test]
    public function media_permissions_are_present(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $mediaPermissions = [
            'media.upload',
            'media.view',
            'media.update',
            'media.delete',
            'media.attach',
            'media.detach',
            'media.manage_collections',
            'media.publish',
            'media.manage_consents',
        ];

        foreach ($mediaPermissions as $code) {
            $this->assertTrue(
                Permission::where('code', $code)->where('module', 'media')->exists(),
                "Media permission '{$code}' should exist."
            );
        }
    }

    #[Test]
    public function no_demo_campaign_or_site_is_created(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertFalse(
            Campaign::where('name', 'Central Uganda Conference Evangelistic Campaign')->exists()
        );
        $this->assertFalse(
            MissionSite::where('name', 'Lira Town Mission Site')->exists()
        );
    }

    #[Test]
    public function no_demo_user_accounts_are_created(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $demoEmails = [
            'admin@uumission.org',
            'director@uumission.org',
            'lira.coordinator@uumission.org',
            'secretary@uumission.org',
        ];

        foreach ($demoEmails as $email) {
            $this->assertFalse(
                User::where('email', $email)->exists(),
                "Demo user '{$email}' should not exist."
            );
        }
    }

    #[Test]
    public function no_demo_person_records_are_created(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        // No person records should exist since no users were created
        $this->assertEquals(0, Person::count());
    }

    #[Test]
    public function repeated_seeding_is_safe(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $permissionCountBefore = Permission::count();
        $roleCountBefore = SystemRole::count();
        $orgUnitCountBefore = OrgUnit::count();

        // Re-seed - should not create duplicates
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($permissionCountBefore, Permission::count());
        $this->assertSame($roleCountBefore, SystemRole::count());
        $this->assertSame($orgUnitCountBefore, OrgUnit::count());

        // No duplicate users (there should be none)
        $this->assertEquals(0, User::count());
    }

    #[Test]
    public function global_permission_delegations_exist_for_all_permissions(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        foreach (Permission::all() as $permission) {
            $delegationExists = \Illuminate\Support\Facades\DB::table('permission_delegations')
                ->where('permission_id', $permission->id)
                ->where('scope_type', 'global')
                ->where('is_active', true)
                ->exists();

            $this->assertTrue(
                $delegationExists,
                "Global delegation missing for permission '{$permission->code}'."
            );
        }
    }

    #[Test]
    public function admin_provision_command_creates_administrator_with_platform_admin_role(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->artisan('admin:provision', [
            '--email' => 'admin@example.org',
            '--name' => 'Test Administrator',
        ])->assertExitCode(0);

        $user = User::where('email', 'admin@example.org')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->status === 'active');

        $person = Person::where('user_id', $user->id)->first();
        $this->assertNotNull($person);
        $this->assertSame('Test', $person->first_name);
        $this->assertSame('Administrator', $person->last_name);

        $roleAssignment = UserRoleAssignment::where('user_id', $user->id)
            ->where('scope_type', 'global')
            ->first();
        $this->assertNotNull($roleAssignment);

        $adminRole = SystemRole::where('code', 'platform_admin')->first();
        $this->assertSame($adminRole->id, $roleAssignment->system_role_id);
    }

    #[Test]
    public function admin_provision_command_is_idempotent_for_duplicate_email(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        // First provision
        $this->artisan('admin:provision', [
            '--email' => 'unique@example.org',
            '--name' => 'First Admin',
        ])->assertExitCode(0);

        $user = User::where('email', 'unique@example.org')->first();
        $this->assertNotNull($user);
        $this->assertEquals(1, UserRoleAssignment::where('user_id', $user->id)->count());
        $this->assertEquals(1, Person::where('user_id', $user->id)->count());

        // Second provision with same email should succeed and not duplicate anything
        $this->artisan('admin:provision', [
            '--email' => 'unique@example.org',
            '--name' => 'First Admin',
        ])->assertExitCode(0);

        $this->assertEquals(1, User::where('email', 'unique@example.org')->count());
        $this->assertEquals(1, Person::where('user_id', $user->id)->count());
        $this->assertEquals(1, UserRoleAssignment::where('user_id', $user->id)->count());
        $this->assertNotNull(UserRoleAssignment::where('user_id', $user->id)->first()->role_permission_version_id);
    }

    #[Test]
    public function admin_provision_command_generates_secure_password(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->artisan('admin:provision', [
            '--email' => 'secure@example.org',
            '--name' => 'Secure Admin',
        ])->assertExitCode(0);

        $user = User::where('email', 'secure@example.org')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->password);
        $this->assertStringStartsWith('$2y$', $user->password); // bcrypt hash prefix
    }

    #[Test]
    public function admin_provision_command_is_idempotent(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        // First provision
        $this->artisan('admin:provision', [
            '--email' => 'idempotent@example.org',
            '--name' => 'First Admin',
        ])->assertExitCode(0);

        $user = User::where('email', 'idempotent@example.org')->first();
        $this->assertNotNull($user);
        $initialAssignmentCount = UserRoleAssignment::where('user_id', $user->id)->count();
        $this->assertEquals(1, $initialAssignmentCount);
        $initialVersionId = $user->roleAssignments()->first()->role_permission_version_id;
        $this->assertNotNull($initialVersionId);

        // Second provision with same email - should not duplicate anything
        $this->artisan('admin:provision', [
            '--email' => 'idempotent@example.org',
            '--name' => 'First Admin',
        ])->assertExitCode(0);

        // User count unchanged
        $this->assertEquals(1, User::where('email', 'idempotent@example.org')->count());
        // Person count unchanged
        $this->assertEquals(1, Person::where('user_id', $user->id)->count());
        // Assignment count unchanged (no duplicate assignment)
        $this->assertEquals($initialAssignmentCount, UserRoleAssignment::where('user_id', $user->id)->count());
        // Permission version id preserved (not reset to null)
        $this->assertNotNull(UserRoleAssignment::where('user_id', $user->id)->first()->role_permission_version_id);
    }

    #[Test]
    public function provisioned_administrator_has_campaigns_create_permission(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->artisan('admin:provision', [
            '--email' => 'admin@example.org',
            '--name' => 'Platform Admin',
        ])->assertExitCode(0);

        $user = User::where('email', 'admin@example.org')->first();
        $this->assertNotNull($user);

        // The provisioned platform_admin must be authorized to create campaigns
        // via canApproveDistribute-style global permission resolution
        $this->assertTrue(
            app(\App\Services\AuthorizationService::class)->hasGlobalPermission(
                $user,
                'campaigns.create'
            ),
            'Provisioned platform_admin must have global campaigns.create permission'
        );

        // Assignment must have a pinned permission version
        $assignment = UserRoleAssignment::where('user_id', $user->id)
            ->where('scope_type', 'global')
            ->first();
        $this->assertNotNull($assignment);
        $this->assertNotNull($assignment->role_permission_version_id);
        $this->assertNotNull($assignment->permissionVersion);
    }
}
