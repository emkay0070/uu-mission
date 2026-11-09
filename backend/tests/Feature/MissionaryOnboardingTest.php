<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\OrgMembership;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\MissionaryBaselineProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MissionaryOnboardingTest — verifies the missionary onboarding lifecycle
 * and baseline role-provisioning behaviour.
 *
 * Coverage:
 *   1. Registration alone grants no operational permissions.
 *   2. Email verification alone grants no missionary role assignment.
 *   3. A registered account without a Person record cannot access operational
 *      missionary workspace pages (primary_role stays null).
 *   4. Completing onboarding with a declared local church creates Person,
 *      MissionaryProfile and OrgMembership records.
 *   5. Home local-church affiliation is automatically verified under the
 *      current rollout policy.
 *   6. Completing onboarding provisions the Missionary role at the
 *      local-church scope.
 *   7. Repeating onboarding does not create duplicate active baseline
 *      assignments.
 *   8. The baseline Missionary role does not grant church administration or
 *      leadership permissions.
 *   9. A local-church baseline assignment coexists with a separately
 *      authorised campaign/site/team appointment.
 *  10. Ending a campaign/site/team appointment does not remove the permanent
 *      local-church baseline assignment.
 *  11. An existing unrelated UUM assignment is not silently overwritten or
 *      deleted.
 *  12. A future mandatory-verification policy prevents provisioning until
 *      authorised approval occurs.
 */
class MissionaryOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private OrgUnit $uu;

    private OrgUnit $cuc;

    private OrgUnit $localChurchA;

    private Campaign $campaignA;

    private SystemRole $roleMissionary;

    private SystemRole $roleUnionDirector;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed production roles and permissions so the missionary role and
        // its permission version exist.
        $this->seed(DatabaseSeeder::class);

        $this->buildFixtures();
    }

    private function buildFixtures(): void
    {
        // ── Organisational hierarchy ────────────────────────────────────
        $typeUnion = OrgUnitType::where('code', 'union')->first();
        $typeConf = OrgUnitType::where('code', 'conference')->first();
        $typeLocalChurch = OrgUnitType::where('code', 'local_church')->first();

        $this->uu = OrgUnit::where('short_code', 'UUM')->first()
            ?? OrgUnit::factory()->create([
                'org_unit_type_id' => $typeUnion->id,
                'name' => 'Uganda Union Mission',
                'short_code' => 'UUM',
            ]);

        $this->cuc = OrgUnit::where('short_code', 'CUC')->first()
            ?? OrgUnit::factory()->create([
                'org_unit_type_id' => $typeConf->id,
                'parent_id' => $this->uu->id,
                'name' => 'Central Uganda Conference',
                'short_code' => 'CUC',
            ]);

        $this->localChurchA = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeLocalChurch->id,
            'parent_id' => $this->cuc->id,
            'name' => 'Kira Local Church',
            'short_code' => 'KIR',
        ]);

        // ── Programme and campaign ─────────────────────────────────────
        $pt = ProgrammeType::where('code', 'global_mission')->first();
        $programme = Programme::where('programme_type_id', $pt->id)->first()
            ?? Programme::factory()->create([
                'programme_type_id' => $pt->id,
                'name' => 'Global Mission Programme',
            ]);

        $this->campaignA = Campaign::factory()->create([
            'programme_id' => $programme->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name' => 'Campaign Alpha',
            'status' => 'active',
        ]);

        // ── Roles ──────────────────────────────────────────────────────
        $this->roleMissionary = SystemRole::where('code', 'missionary')->first();
        $this->roleUnionDirector = SystemRole::where('code', 'union_director')->first();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // A. REGISTRATION AND EMAIL VERIFICATION GRANT NOTHING
    // ═══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function registration_alone_creates_no_person_profile_or_assignment(): void
    {
        $user = User::factory()->create([
            'email' => 'new.register@example.org',
            'status' => 'pending',
        ]);

        $this->assertNull($user->person);
        $this->assertDatabaseMissing('missionary_profiles', ['person_id' => $user->person?->id]);
        $this->assertDatabaseMissing('user_role_assignments', ['user_id' => $user->id]);
    }

    #[Test]
    public function email_verification_alone_grants_no_missionary_role_assignment(): void
    {
        $user = User::factory()->create([
            'email' => 'verify.example@example.org',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->assertNull($user->person);
        $this->assertDatabaseMissing('user_role_assignments', ['user_id' => $user->id]);
    }

    #[Test]
    public function registered_account_without_person_has_null_primary_role(): void
    {
        $user = User::factory()->create([
            'email' => 'noperson.example@example.org',
            'status' => 'active',
        ]);

        // Give the user a missionary role assignment (simulating the Emkay
        // development-account situation) — but no Person record.
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $this->roleMissionary->id,
            'scope_type' => 'org_unit',
            'scoped_org_unit_id' => $this->uu->id,
            'role_permission_version_id' => $this->roleMissionary->current_permission_version_id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertNull($response->json('user.primary_role'));
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // B. ONBOARDING CREATES IDENTITY, AFFILIATION AND BASELINE ROLE
    // ═══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function completing_onboarding_creates_person_missionary_profile_and_membership(): void
    {
        $user = User::factory()->create([
            'email' => 'onboard.example@example.org',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/onboarding/register', [
                'mode' => 'missionary',
                'personal' => [
                    'first_name' => 'Test',
                    'last_name' => 'Missionary',
                ],
                'church_affiliation' => [
                    'local_church_id' => $this->localChurchA->id,
                    'membership_type' => 'member',
                ],
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('people', ['user_id' => $user->id]);

        // Refresh so the person relationship resolves from the database.
        $user->refresh();

        $this->assertDatabaseHas('missionary_profiles', [
            'person_id' => $user->person->id,
        ]);
        $this->assertDatabaseHas('org_memberships', [
            'person_id' => $user->person->id,
            'org_unit_id' => $this->localChurchA->id,
            'approval_status' => 'approved',
        ]);
    }

    #[Test]
    public function home_local_church_affiliation_is_automatically_verified(): void
    {
        $user = User::factory()->create([
            'email' => 'auto.verify.example@example.org',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/onboarding/register', [
                'mode' => 'missionary',
                'personal' => [
                    'first_name' => 'Auto',
                    'last_name' => 'Verify',
                ],
                'church_affiliation' => [
                    'local_church_id' => $this->localChurchA->id,
                    'membership_type' => 'member',
                ],
            ]);

        $user->refresh();

        $this->assertDatabaseHas('org_memberships', [
            'person_id' => $user->person->id,
            'org_unit_id' => $this->localChurchA->id,
            'approval_status' => 'approved',
        ]);
    }

    #[Test]
    public function completing_onboarding_provisions_missionary_role_at_local_church_scope(): void
    {
        $user = User::factory()->create([
            'email' => 'provision.example@example.org',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/onboarding/register', [
                'mode' => 'missionary',
                'personal' => [
                    'first_name' => 'Provision',
                    'last_name' => 'Test',
                ],
                'church_affiliation' => [
                    'local_church_id' => $this->localChurchA->id,
                    'membership_type' => 'member',
                ],
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'system_role_id' => $this->roleMissionary->id,
            'scope_type' => 'org_unit',
            'scoped_org_unit_id' => $this->localChurchA->id,
            'status' => 'active',
        ]);

        // Verify the /auth/me payload now reports primary_role = missionary.
        $me = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('user');

        $this->assertSame('missionary', $me['primary_role']);
        $this->assertNotNull($me['person']['id']);
        $this->assertNotNull($me['person']['missionary_profile']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // C. IDEMPOTENCY AND COEXISTENCE
    // ═══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function repeating_onboarding_does_not_create_duplicate_baseline_assignments(): void
    {
        $user = User::factory()->create([
            'email' => 'idempotent.example@example.org',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/onboarding/register', [
                'mode' => 'missionary',
                'personal' => [
                    'first_name' => 'Idem',
                    'last_name' => 'Potent',
                ],
                'church_affiliation' => [
                    'local_church_id' => $this->localChurchA->id,
                    'membership_type' => 'member',
                ],
            ]);

        $user->refresh();

        // Submit onboarding again with the same church.
        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/onboarding/people/'.$user->person->id, [
                'section' => 'church_affiliation',
                'data' => [
                    'local_church_id' => $this->localChurchA->id,
                    'membership_type' => 'member',
                ],
            ]);

        $count = UserRoleAssignment::where('user_id', $user->id)
            ->where('system_role_id', $this->roleMissionary->id)
            ->where('scope_type', 'org_unit')
            ->where('scoped_org_unit_id', $this->localChurchA->id)
            ->where('status', 'active')
            ->count();

        $this->assertSame(1, $count);
    }

    #[Test]
    public function baseline_missionary_role_does_not_grant_church_administration(): void
    {
        $user = User::factory()->create([
            'email' => 'nobaseline.admin@example.org',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/onboarding/register', [
                'mode' => 'missionary',
                'personal' => [
                    'first_name' => 'No',
                    'last_name' => 'Admin',
                ],
                'church_affiliation' => [
                    'local_church_id' => $this->localChurchA->id,
                    'membership_type' => 'member',
                ],
            ]);

        $user->refresh();

        // The baseline missionary role has resources.view and resources.create,
        // but NOT people.manage, assignments.manage, or any administrative codes.
        $permissions = $user->roleAssignments->first()
            ->permissionVersion
            ->permissions
            ->pluck('code');

        $this->assertContains('resources.view', $permissions);
        $this->assertContains('resources.create', $permissions);
        $this->assertNotContains('people.manage', $permissions);
        $this->assertNotContains('assignments.manage', $permissions);
        $this->assertNotContains('sites.edit', $permissions);
        $this->assertNotContains('campaigns.edit', $permissions);
    }

    #[Test]
    public function local_church_baseline_coexists_with_campaign_appointment(): void
    {
        $user = User::factory()->create([
            'email' => 'coexist.example@example.org',
            'status' => 'active',
        ]);

        // Complete onboarding → baseline assignment at local church.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/onboarding/register', [
                'mode' => 'missionary',
                'personal' => [
                    'first_name' => 'Co',
                    'last_name' => 'Exist',
                ],
                'church_affiliation' => [
                    'local_church_id' => $this->localChurchA->id,
                    'membership_type' => 'member',
                ],
            ]);

        // Add a separate campaign-scoped appointment (e.g. campaign_leader)
        // at a different scope — this is an additional appointment, not the
        // baseline role.
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $this->roleUnionDirector->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $this->campaignA->id,
            'role_permission_version_id' => $this->roleUnionDirector->current_permission_version_id,
            'status' => 'active',
        ]);

        $user->refresh();

        $assignments = $user->roleAssignments()->where('status', 'active')->get();

        $this->assertCount(2, $assignments);
        $this->assertTrue($assignments->contains(fn ($a) => $a->scope_type === 'org_unit'
            && $a->scoped_org_unit_id === $this->localChurchA->id
        ));
        $this->assertTrue($assignments->contains(fn ($a) => $a->scope_type === 'campaign'
            && $a->scoped_campaign_id === $this->campaignA->id
        ));
    }

    #[Test]
    public function ending_campaign_appointment_preserves_local_church_baseline(): void
    {
        $user = User::factory()->create([
            'email' => 'preserve.example@example.org',
            'status' => 'active',
        ]);

        // Complete onboarding → baseline assignment at local church.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/onboarding/register', [
                'mode' => 'missionary',
                'personal' => [
                    'first_name' => 'Preserve',
                    'last_name' => 'Baseline',
                ],
                'church_affiliation' => [
                    'local_church_id' => $this->localChurchA->id,
                    'membership_type' => 'member',
                ],
            ]);

        // Add a separate campaign-scoped appointment.
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $this->roleUnionDirector->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $this->campaignA->id,
            'role_permission_version_id' => $this->roleUnionDirector->current_permission_version_id,
            'status' => 'active',
        ]);

        // End the campaign appointment (revoked is the only non-active status
        // permitted by the ura_status_check constraint).
        $user->roleAssignments()
            ->where('scope_type', 'campaign')
            ->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);

        $user->refresh();

        // The local-church baseline assignment must still be active.
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'system_role_id' => $this->roleMissionary->id,
            'scope_type' => 'org_unit',
            'scoped_org_unit_id' => $this->localChurchA->id,
            'status' => 'active',
        ]);

        // The campaign appointment is revoked.
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'scope_type' => 'campaign',
            'status' => 'revoked',
        ]);
    }

    #[Test]
    public function existing_uum_assignment_is_not_overwritten(): void
    {
        // Simulate the Emkay development account: a UUM-scoped missionary
        // assignment exists without a Person record.
        $user = User::factory()->create([
            'email' => 'uum.preserve@example.org',
            'status' => 'active',
        ]);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $this->roleMissionary->id,
            'scope_type' => 'org_unit',
            'scoped_org_unit_id' => $this->uu->id,
            'role_permission_version_id' => $this->roleMissionary->current_permission_version_id,
            'status' => 'active',
        ]);

        $uumAssignmentId = UserRoleAssignment::where('user_id', $user->id)
            ->where('scoped_org_unit_id', $this->uu->id)
            ->value('id');

        $churchId = $this->localChurchA->id;

        // Complete onboarding with a local church → new baseline assignment
        // at the local church, UUM assignment is ended (not deleted).
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/onboarding/register', [
                'mode' => 'missionary',
                'personal' => [
                    'first_name' => 'Uum',
                    'last_name' => 'Preserve',
                ],
                'church_affiliation' => [
                    'local_church_id' => $churchId,
                    'membership_type' => 'member',
                ],
            ]);

        $user->refresh();

        // New baseline assignment exists at the local church scope (not UUM).
        $localChurchAssignment = UserRoleAssignment::where('user_id', $user->id)
            ->where('system_role_id', $this->roleMissionary->id)
            ->where('scope_type', 'org_unit')
            ->where('scoped_org_unit_id', '!=', $this->uu->id)
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($localChurchAssignment);
        $this->assertSame($churchId, $localChurchAssignment->scoped_org_unit_id);

        // Original UUM assignment SURVIVES — a legitimate higher-level
        // appointment must never be revoked by local-church baseline
        // provisioning.
        $this->assertDatabaseHas('user_role_assignments', [
            'id' => $uumAssignmentId,
            'user_id' => $user->id,
            'scoped_org_unit_id' => $this->uu->id,
            'status' => 'active',
        ]);

        // Both the higher-level UUM appointment and the local-church baseline
        // are active — no duplicate baseline at the local church, and the
        // higher-level appointment was not revoked.
        $activeBaselineCount = UserRoleAssignment::where('user_id', $user->id)
            ->where('system_role_id', $this->roleMissionary->id)
            ->where('scope_type', 'org_unit')
            ->where('status', 'active')
            ->count();

        $this->assertSame(2, $activeBaselineCount);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // D. FUTURE MANDATORY VERIFICATION POLICY
    // ═══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function mandatory_verification_policy_prevents_provisioning_until_approval(): void
    {
        // Temporarily disable automatic verification.
        config(['missionary.auto_verify_home_affiliation' => false]);

        $user = User::factory()->create([
            'email' => 'mandatory.example@example.org',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/onboarding/register', [
                'mode' => 'missionary',
                'personal' => [
                    'first_name' => 'Mandatory',
                    'last_name' => 'Verify',
                ],
                'church_affiliation' => [
                    'local_church_id' => $this->localChurchA->id,
                    'membership_type' => 'member',
                ],
            ]);

        $user->refresh();

        // Membership is created as pending, not approved.
        $this->assertDatabaseHas('org_memberships', [
            'person_id' => $user->person->id,
            'org_unit_id' => $this->localChurchA->id,
            'approval_status' => 'pending',
        ]);

        // No baseline role assignment is provisioned while pending.
        $this->assertDatabaseMissing('user_role_assignments', [
            'user_id' => $user->id,
            'system_role_id' => $this->roleMissionary->id,
        ]);

        // Authorised approval provisions the baseline role.
        $membership = OrgMembership::where('person_id', $user->person->id)
            ->where('org_unit_id', $this->localChurchA->id)
            ->first();

        $membership->update([
            'approval_status' => 'approved',
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
        ]);

        // Call the provisioner directly (simulating the authorised approval
        // controller path). Under mandatory verification, provisioning only
        // happens after approval.
        app(MissionaryBaselineProvisioner::class)->provisionIfVerified($membership);

        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'system_role_id' => $this->roleMissionary->id,
            'scope_type' => 'org_unit',
            'scoped_org_unit_id' => $this->localChurchA->id,
            'status' => 'active',
        ]);

        // Reset config for other tests.
        config(['missionary.auto_verify_home_affiliation' => true]);
    }
}
