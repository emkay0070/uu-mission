<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\Permission;
use App\Models\RolePermissionVersion;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\UserRoleAssignmentOverride;
use App\Policies\UserRoleAssignmentPolicy;
use App\Services\AuthorizationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PublishesRolePermissions;

class FoundationalRbacTest extends TestCase
{
    use PublishesRolePermissions;
    use RefreshDatabase;

    public function test_permission_defaults_are_pinned_and_denials_win_only_in_applicable_scope(): void
    {
        $view = $this->permission('campaigns.view');
        $edit = $this->permission('campaigns.edit');
        $role = $this->role('reader', 'global');
        $this->publishRolePermissions($role, [$view->id]);
        $user = User::factory()->create();
        $assignment = UserRoleAssignment::create(['user_id' => $user->id, 'system_role_id' => $role->id, 'scope_type' => 'global']);

        $this->publishRolePermissions($role, [$edit->id]);
        $this->assertSame([$view->code], app(AuthorizationService::class)->getEffectivePermissions($user));

        $campaign = Campaign::factory()->create();
        $campaignRole = $this->role('campaign_restrictor', 'campaign');
        $this->publishRolePermissions($campaignRole, []);
        $localAssignment = UserRoleAssignment::create([
            'user_id' => $user->id, 'system_role_id' => $campaignRole->id,
            'scope_type' => 'campaign', 'scoped_campaign_id' => $campaign->id,
        ]);
        UserRoleAssignmentOverride::create([
            'user_role_assignment_id' => $localAssignment->id, 'permission_id' => $view->id,
            'effect' => 'deny', 'created_by_user_id' => User::factory()->create()->id, 'reason' => 'Temporary restriction',
        ]);

        $this->assertFalse(app(AuthorizationService::class)->hasPermissionForCampaign($user, $view->code, $campaign));
        $this->assertTrue(app(AuthorizationService::class)->hasGlobalPermission($user, $view->code));
    }

    public function test_permission_resolution_combines_applicable_assignments_and_respects_campaign_boundaries(): void
    {
        $permission = $this->permission('campaigns.view');
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();
        $user = User::factory()->create();
        $role = $this->role('campaign_reader', 'campaign');
        $this->publishRolePermissions($role, [$permission->id]);
        UserRoleAssignment::create([
            'user_id' => $user->id, 'system_role_id' => $role->id,
            'scope_type' => 'campaign', 'scoped_campaign_id' => $campaignA->id,
        ]);

        $auth = app(AuthorizationService::class);
        $this->assertTrue($auth->hasPermissionForCampaign($user, $permission->code, $campaignA));
        $this->assertFalse($auth->hasPermissionForCampaign($user, $permission->code, $campaignB));
    }

    public function test_collection_scope_applies_site_denials_without_hiding_sibling_sites(): void
    {
        $permission = $this->permission('campaigns.view');
        $campaign = Campaign::factory()->create();
        $allowedSite = MissionSite::factory()->create(['campaign_id' => $campaign->id]);
        $deniedSite = MissionSite::factory()->create(['campaign_id' => $campaign->id]);
        $user = User::factory()->create();

        $campaignRole = $this->role('campaign_list_reader', 'campaign');
        $this->publishRolePermissions($campaignRole, [$permission->id]);
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $campaignRole->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id,
        ]);

        $siteRole = $this->role('site_list_restrictor', 'site');
        $this->publishRolePermissions($siteRole, []);
        $siteAssignment = UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $siteRole->id,
            'scope_type' => 'site',
            'scoped_site_id' => $deniedSite->id,
        ]);
        UserRoleAssignmentOverride::create([
            'user_role_assignment_id' => $siteAssignment->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
            'created_by_user_id' => User::factory()->create()->id,
            'reason' => 'Restrict this site',
        ]);

        $auth = app(AuthorizationService::class);
        $scopes = $auth->getAuthorisedScopes($user, $permission->code);
        $siteIds = $auth->scopeQuery(MissionSite::query(), $scopes, 'campaign_id', 'id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $allowedSite->id, $siteIds);
        $this->assertNotContains((int) $deniedSite->id, $siteIds);
    }

    public function test_published_permission_versions_cannot_be_mutated(): void
    {
        $permission = $this->permission('campaigns.view');
        $role = $this->role('immutable_reader', 'global');
        $this->publishRolePermissions($role, [$permission->id]);
        $version = RolePermissionVersion::whereKey($role->current_permission_version_id)->firstOrFail();

        $this->expectException(QueryException::class);
        $version->permissions()->sync([]);
    }

    public function test_permission_version_change_requires_authority_and_is_audited(): void
    {
        $manage = $this->permission('assignments.manage');
        $view = $this->permission('campaigns.view');
        $edit = $this->permission('campaigns.edit');
        $role = $this->role('versioned_user', 'global');
        $this->publishRolePermissions($role, [$view->id]);
        $owner = User::factory()->create();
        $assignment = UserRoleAssignment::create(['user_id' => $owner->id, 'system_role_id' => $role->id, 'scope_type' => 'global']);

        $this->publishRolePermissions($role, [$view->id, $edit->id]);
        $currentVersionId = (int) $role->fresh()->current_permission_version_id;
        $managerRole = $this->role('version_admin', 'global');
        $this->publishRolePermissions($managerRole, [$manage->id]);
        $manager = User::factory()->create();
        UserRoleAssignment::create(['user_id' => $manager->id, 'system_role_id' => $managerRole->id, 'scope_type' => 'global']);
        foreach ([$view, $edit] as $permission) {
            DB::table('permission_delegations')->insert([
                'permission_id' => $permission->id, 'scope_type' => 'global', 'is_active' => true,
                'reason' => 'Version update delegation', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($manager)->patchJson("/api/v1/role-assignments/{$assignment->id}", [
            'role_permission_version_id' => $currentVersionId,
            'reason' => 'Approved permission version update',
        ])->assertOk();

        $this->assertSame($currentVersionId, (int) $assignment->fresh()->role_permission_version_id);
        $this->assertContains($edit->code, app(AuthorizationService::class)->getEffectivePermissions($owner));
        $this->assertDatabaseHas('user_role_assignment_events', [
            'user_role_assignment_id' => $assignment->id, 'action' => 'version_updated',
            'actor_user_id' => $manager->id, 'reason' => 'Approved permission version update',
        ]);
    }

    public function test_inactive_suspended_revoked_and_expired_assignments_do_not_grant_permissions(): void
    {
        $permission = $this->permission('campaigns.view');
        $role = $this->role('lifecycle_reader', 'global');
        $this->publishRolePermissions($role, [$permission->id]);
        $user = User::factory()->create();
        $assignment = UserRoleAssignment::create(['user_id' => $user->id, 'system_role_id' => $role->id, 'scope_type' => 'global']);
        $this->assertTrue(app(AuthorizationService::class)->hasGlobalPermission($user, $permission->code));

        $assignment->update(['status' => 'suspended']);
        $this->assertFalse(app(AuthorizationService::class)->hasGlobalPermission($user, $permission->code));
        $assignment->update(['status' => 'active']);
        $this->assertTrue(app(AuthorizationService::class)->hasGlobalPermission($user, $permission->code));
        $assignment->update(['status' => 'revoked']);
        $this->assertFalse(app(AuthorizationService::class)->hasGlobalPermission($user, $permission->code));

        $expired = UserRoleAssignment::create([
            'user_id' => $user->id, 'system_role_id' => $role->id, 'scope_type' => 'global',
            'valid_until' => now()->subDay()->toDateString(),
        ]);
        $this->assertFalse($expired->isActive());
        $role->update(['is_active' => false]);
        $this->assertFalse(app(AuthorizationService::class)->hasGlobalPermission($user, $permission->code));
    }

    public function test_assignment_policy_requires_scope_compatibility_and_explicit_permission_delegation(): void
    {
        $managerPermission = $this->permission('assignments.manage');
        $targetPermission = $this->permission('campaigns.view');
        $managerRole = $this->role('assignment_manager', 'global');
        $this->publishRolePermissions($managerRole, [$managerPermission->id]);
        $manager = User::factory()->create();
        UserRoleAssignment::create(['user_id' => $manager->id, 'system_role_id' => $managerRole->id, 'scope_type' => 'global']);

        $targetRole = $this->role('scoped_campaign_reader', 'campaign');
        $this->publishRolePermissions($targetRole, [$targetPermission->id]);
        $targetUser = User::factory()->create();
        $payload = [
            'user_id' => $targetUser->id, 'system_role_id' => $targetRole->id,
            'scope_type' => 'campaign', 'scoped_campaign_id' => Campaign::factory()->create()->id,
        ];
        $policy = app(UserRoleAssignmentPolicy::class);
        $this->assertFalse($policy->create($manager, $payload));

        DB::table('permission_delegations')->insert([
            'permission_id' => $targetPermission->id, 'scope_type' => 'campaign',
            'scoped_campaign_id' => $payload['scoped_campaign_id'], 'is_active' => true,
            'reason' => 'Test delegation', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue($policy->create($manager, $payload));
        DB::table('role_scope_compatibilities')->where('system_role_id', $targetRole->id)->where('scope_type', 'campaign')->delete();
        $this->assertFalse($policy->create($manager, $payload));
        $payload['scope_type'] = 'global';
        $payload['scoped_campaign_id'] = null;
        $this->assertFalse($policy->create($manager, $payload));
    }

    public function test_override_grants_require_delegation_and_override_changes_are_audited(): void
    {
        $manage = $this->permission('assignments.manage');
        $permission = $this->permission('campaigns.view');
        $managerRole = $this->role('override_manager', 'global');
        $this->publishRolePermissions($managerRole, [$manage->id]);
        $manager = User::factory()->create();
        UserRoleAssignment::create(['user_id' => $manager->id, 'system_role_id' => $managerRole->id, 'scope_type' => 'global']);

        $targetRole = $this->role('override_target', 'global');
        $this->publishRolePermissions($targetRole, []);
        $owner = User::factory()->create();
        $assignment = UserRoleAssignment::create(['user_id' => $owner->id, 'system_role_id' => $targetRole->id, 'scope_type' => 'global']);

        $this->actingAs($manager)->putJson("/api/v1/role-assignments/{$assignment->id}/permission-overrides", [
            'permission_code' => $permission->code, 'effect' => 'grant', 'reason' => 'Approved test grant',
        ])->assertForbidden();

        DB::table('permission_delegations')->insert([
            'permission_id' => $permission->id, 'scope_type' => 'global', 'is_active' => true,
            'reason' => 'Configured global delegation', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->actingAs($manager)->putJson("/api/v1/role-assignments/{$assignment->id}/permission-overrides", [
            'permission_code' => $permission->code, 'effect' => 'grant', 'reason' => 'Approved test grant',
        ])->assertCreated();

        $this->assertDatabaseHas('user_role_assignment_override_events', [
            'user_role_assignment_id' => $assignment->id, 'permission_id' => $permission->id,
            'action' => 'created', 'actor_user_id' => $manager->id, 'reason' => 'Approved test grant',
        ]);
        $this->assertSame($permission->code, $response->json('permission.code'));
    }

    private function permission(string $code): Permission
    {
        return Permission::firstOrCreate(['code' => $code], ['label' => $code, 'module' => str_contains($code, '.') ? explode('.', $code)[0] : 'test']);
    }

    private function role(string $code, string $scope): SystemRole
    {
        return SystemRole::create(['code' => $code, 'label' => $code, 'scope_type' => $scope, 'is_active' => true]);
    }
}
