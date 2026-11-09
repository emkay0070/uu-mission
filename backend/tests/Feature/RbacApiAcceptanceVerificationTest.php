<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacApiAcceptanceVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_publication_assignment_lifecycle_overrides_and_delegation_api_workflow(): void
    {
        $adminManage = Permission::firstOrCreate(['code' => 'admin.roles.manage'], ['label' => 'Manage roles', 'module' => 'admin']);
        $assignmentManage = Permission::firstOrCreate(['code' => 'assignments.manage'], ['label' => 'Manage assignments', 'module' => 'assignments']);
        $view = Permission::firstOrCreate(['code' => 'campaigns.view'], ['label' => 'View campaigns', 'module' => 'campaigns']);
        $edit = Permission::firstOrCreate(['code' => 'campaigns.edit'], ['label' => 'Edit campaigns', 'module' => 'campaigns']);

        $administrator = User::factory()->create();
        $adminRole = SystemRole::create(['code' => 'rbac_acceptance_admin', 'label' => 'RBAC Acceptance Admin', 'scope_type' => 'global', 'is_active' => true]);
        $adminVersion = $this->version($adminRole, [$adminManage, $assignmentManage]);
        UserRoleAssignment::create(['user_id' => $administrator->id, 'system_role_id' => $adminRole->id, 'scope_type' => 'global', 'role_permission_version_id' => $adminVersion]);
        $this->actingAs($administrator);

        $role = SystemRole::create(['code' => 'rbac_acceptance_reader', 'label' => 'RBAC Acceptance Reader', 'scope_type' => 'campaign', 'is_active' => true]);
        $published = $this->putJson("/api/v1/rbac/roles/{$role->id}/permission-versions", [
            'permission_codes' => [$view->code], 'reason' => 'Acceptance verification version one',
        ])->assertCreated()->assertJsonPath('version', 1);
        $firstVersionId = (int) $published->json('id');

        $campaign = Campaign::factory()->create();
        $delegation = $this->postJson('/api/v1/rbac/permission-delegations', [
            'permission_code' => $view->code, 'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id, 'reason' => 'Acceptance verification delegation',
        ])->assertCreated();
        $delegationId = (int) $delegation->json('id');

        $recipient = User::factory()->create();
        $payload = [
            'user_id' => $recipient->id, 'system_role_id' => $role->id,
            'scope_type' => 'campaign', 'scoped_campaign_id' => $campaign->id,
        ];
        $this->postJson('/api/v1/role-assignments', [
            'user_id' => $recipient->id, 'system_role_id' => $role->id, 'scope_type' => 'global',
        ])
            ->assertForbidden();
        $assignmentResponse = $this->postJson('/api/v1/role-assignments', $payload)->assertCreated();
        $assignmentId = (int) $assignmentResponse->json('id');
        $this->assertSame($firstVersionId, (int) $assignmentResponse->json('role_permission_version_id'));

        $this->putJson("/api/v1/role-assignments/{$assignmentId}/permission-overrides", [
            'permission_code' => $edit->code, 'effect' => 'grant', 'reason' => 'Add delegated test permission',
        ])->assertForbidden();
        $editDelegation = $this->postJson('/api/v1/rbac/permission-delegations', [
            'permission_code' => $edit->code, 'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id, 'reason' => 'Acceptance verification edit delegation',
        ])->assertCreated();
        $this->putJson("/api/v1/role-assignments/{$assignmentId}/permission-overrides", [
            'permission_code' => $edit->code, 'effect' => 'grant', 'reason' => 'Add delegated test permission',
        ])->assertCreated();
        $this->deleteJson("/api/v1/role-assignments/{$assignmentId}/permission-overrides/{$edit->id}", [
            'reason' => 'Remove delegated test permission',
        ])->assertOk();

        $newVersion = $this->putJson("/api/v1/rbac/roles/{$role->id}/permission-versions", [
            'permission_codes' => [$view->code, $edit->code], 'reason' => 'Acceptance verification version two',
        ])->assertCreated()->assertJsonPath('version', 2);
        $secondVersionId = (int) $newVersion->json('id');
        $this->patchJson("/api/v1/role-assignments/{$assignmentId}", [
            'role_permission_version_id' => $secondVersionId, 'reason' => 'Approved test assignment update',
        ])->assertOk();
        $this->assertSame($secondVersionId, (int) UserRoleAssignment::findOrFail($assignmentId)->role_permission_version_id);

        $this->patchJson("/api/v1/role-assignments/{$assignmentId}", [
            'status' => 'suspended', 'reason' => 'Acceptance suspension',
        ])->assertOk()->assertJsonPath('status', 'suspended');
        $this->patchJson("/api/v1/role-assignments/{$assignmentId}", [
            'status' => 'active', 'reason' => 'Acceptance reinstatement',
        ])->assertOk()->assertJsonPath('status', 'active');
        $this->deleteJson("/api/v1/role-assignments/{$assignmentId}", ['reason' => 'Acceptance permanent revocation'])
            ->assertOk();
        $this->assertSame('revoked', UserRoleAssignment::findOrFail($assignmentId)->status);

        $this->deleteJson("/api/v1/rbac/permission-delegations/{$delegationId}", ['reason' => 'Acceptance delegation revocation'])
            ->assertOk();
        $this->deleteJson("/api/v1/rbac/permission-delegations/{$editDelegation->json('id')}", ['reason' => 'Acceptance delegation revocation'])
            ->assertOk();

        $this->assertDatabaseHas('user_role_assignment_events', ['user_role_assignment_id' => $assignmentId, 'action' => 'suspended']);
        $this->assertDatabaseHas('user_role_assignment_events', ['user_role_assignment_id' => $assignmentId, 'action' => 'reinstated']);
        $this->assertDatabaseHas('user_role_assignment_events', ['user_role_assignment_id' => $assignmentId, 'action' => 'revoked']);
        $this->assertDatabaseHas('permission_delegation_events', ['permission_delegation_id' => $delegationId, 'action' => 'revoked']);
        $this->assertDatabaseHas('user_role_assignment_override_events', ['user_role_assignment_id' => $assignmentId, 'action' => 'created']);
        $this->assertDatabaseHas('user_role_assignment_override_events', ['user_role_assignment_id' => $assignmentId, 'action' => 'removed']);
    }

    /** @param array<int, Permission> $permissions */
    private function version(SystemRole $role, array $permissions): int
    {
        $version = $role->permissionVersions()->create([
            'version' => 1,
            'change_reason' => 'Acceptance fixture',
            'published_at' => null,
        ]);
        $version->permissions()->sync(collect($permissions)->pluck('id')->all());
        $version->update(['published_at' => now()]);
        $role->update(['current_permission_version_id' => $version->id]);

        return (int) $version->id;
    }
}
