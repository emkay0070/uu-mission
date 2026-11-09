<?php

namespace Tests\Feature;

use App\Models\AttendanceRegister;
use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\EngagementStage;
use App\Models\FieldReport;
use App\Models\MissionOutcome;
use App\Models\MissionSite;
use App\Models\MissionaryProfile;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\OutcomeType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\PublishesRolePermissions;

/**
 * ScopedAuthorizationTest — verifies that every authorization boundary works.
 *
 * Test coverage matrix:
 *   A. Unauthenticated requests → 401
 *   B. Global scope (platform_admin) → full access
 *   C. Campaign scope → own campaign only, 403 on other campaigns
 *   D. Site scope → own site only, 403 on other sites
 *   E. Report review permission — site_secretary cannot review, campaign_leader can
 *   F. Team member management — only assignments.manage roles can add/remove members
 *   G. Outcome recording is site-aware; follow-up management — outcomes.manage_followup required
 *   H. Campaign transition — only campaign_leader and above can transition
 *   K. Site Operations listing admission — a site-scoped officer may list the
 *      teams/assignments at their OWN site, and only their own site
 *   L. Workspace switching — a person holding both workspaces reaches each one
 *      in the same session, without re-authenticating
 *   M. Attendance register inspection — a site-scoped officer may open a
 *      register at their OWN site, read its participants/reconciliation and
 *      summary, and the existing verification rules are unchanged
 */
class ScopedAuthorizationTest extends TestCase
{
    use PublishesRolePermissions;
    use RefreshDatabase;

    // ── Shared test fixtures ──────────────────────────────────────────────────

    private OrgUnit $uu;

    private OrgUnit $cuc;

    private Campaign $campaignA;

    private Campaign $campaignB;

    private MissionSite $siteA1;

    private MissionSite $siteA2;

    private MissionSite $siteB1;

    private SystemRole $roleAdmin;

    private SystemRole $roleCampaignLeader;

    private SystemRole $roleSiteSecretary;

    private SystemRole $roleSiteCoordinator;

    private SystemRole $roleMissionary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // A. UNAUTHENTICATED REQUESTS
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function unauthenticated_campaign_list_returns_401(): void
    {
        $this->getJson('/api/v1/campaigns')->assertStatus(401);
    }

    #[Test]
    public function unauthenticated_campaign_show_returns_401(): void
    {
        $this->getJson("/api/v1/campaigns/{$this->campaignA->id}")->assertStatus(401);
    }

    #[Test]
    public function unauthenticated_site_list_returns_401(): void
    {
        $this->getJson("/api/v1/campaigns/{$this->campaignA->id}/sites")->assertStatus(401);
    }

    #[Test]
    public function unauthenticated_report_list_returns_401(): void
    {
        $this->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports")->assertStatus(401);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // B. GLOBAL SCOPE — platform_admin has full access
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function platform_admin_can_view_any_campaign(): void
    {
        $admin = $this->createUserWithGlobalRole($this->roleAdmin);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $this->campaignA->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignB->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $this->campaignB->id]);
    }

    #[Test]
    public function platform_admin_can_transition_campaign(): void
    {
        $admin = $this->createUserWithGlobalRole($this->roleAdmin);
        $planning = $this->createCampaign('Planning Campaign', 'planning');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/campaigns/{$planning->id}/transition", ['to' => 'active'])
            ->assertOk()
            ->assertJsonFragment(['status' => 'active']);
    }

    #[Test]
    public function platform_admin_can_list_campaigns_for_both(): void
    {
        $admin = $this->createUserWithGlobalRole($this->roleAdmin);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/campaigns')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($this->campaignA->id, $ids);
        $this->assertContains($this->campaignB->id, $ids);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // C. CAMPAIGN SCOPE — campaign_leader for Campaign A
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function campaign_leader_for_campaign_a_can_view_campaign_a(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}")
            ->assertOk();
    }

    #[Test]
    public function campaign_leader_for_campaign_a_cannot_view_campaign_b(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignB->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function campaign_leader_for_campaign_a_cannot_update_campaign_b(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignB->id}", ['name' => 'Hacked'])
            ->assertStatus(403);
    }

    #[Test]
    public function campaign_leader_can_list_sites_for_own_campaign(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/sites")
            ->assertOk();
    }

    #[Test]
    public function campaign_leader_for_campaign_a_cannot_list_sites_for_campaign_b(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignB->id}/sites")
            ->assertStatus(403);
    }

    #[Test]
    public function campaign_leader_can_view_campaign_stats(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/stats")
            ->assertOk()
            ->assertJsonFragment(['campaign_id' => $this->campaignA->id]);
    }

    #[Test]
    public function campaign_leader_can_transition_own_campaign_from_planning_to_active(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $planning = $this->createCampaign('Transition Test', 'planning');
        // Give the leader access to this planning campaign too
        $this->assignCampaignRole($leader, $this->roleCampaignLeader, $planning);

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$planning->id}/transition", ['to' => 'active'])
            ->assertOk();
    }

    #[Test]
    public function campaign_leader_cannot_transition_campaign_b(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignB->id}/transition", ['to' => 'completed'])
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D. SITE SCOPE — site_secretary for siteA1 only
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function site_secretary_for_site_a1_can_view_site_a1(): void
    {
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);

        $this->actingAs($secretary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/sites/{$this->siteA1->id}")
            ->assertOk();
    }

    #[Test]
    public function site_secretary_for_site_a1_cannot_view_site_a2(): void
    {
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);

        $this->actingAs($secretary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/sites/{$this->siteA2->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function site_secretary_cannot_update_site_details(): void
    {
        // site_secretary has sites.view only, not sites.edit
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);

        $this->actingAs($secretary, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/sites/{$this->siteA1->id}", [
                'description' => 'Attempted edit',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function site_secretary_for_site_a1_cannot_access_campaign_b_site(): void
    {
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);

        $this->actingAs($secretary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignB->id}/sites/{$this->siteB1->id}")
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // E. REPORT REVIEW PERMISSION
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function site_secretary_can_submit_draft_report(): void
    {
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $report = $this->createDraftReport($this->campaignA, $this->siteA1);

        $this->actingAs($secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/submit")
            ->assertOk()
            ->assertJsonFragment(['status' => 'submitted']);
    }

    #[Test]
    public function site_secretary_cannot_review_submitted_report(): void
    {
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $report = $this->createDraftReport($this->campaignA, $this->siteA1);
        $report->update(['status' => 'submitted']);

        $this->actingAs($secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/review", [
                'action' => 'approve',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function campaign_leader_can_review_and_approve_report(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $report = $this->createDraftReport($this->campaignA, $this->siteA1);
        $report->update(['status' => 'submitted']);

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/review", [
                'action' => 'approve',
            ])
            ->assertOk()
            ->assertJsonPath('report.status', 'approved');
    }

    #[Test]
    public function campaign_leader_for_campaign_a_cannot_review_report_in_campaign_b(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $reportB = $this->createDraftReport($this->campaignB, $this->siteB1);
        $reportB->update(['status' => 'submitted']);

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignB->id}/reports/{$reportB->id}/review", [
                'action' => 'approve',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function missionary_cannot_review_reports(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);
        $report = $this->createDraftReport($this->campaignA, $this->siteA1);
        $report->update(['status' => 'submitted']);

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/review", [
                'action' => 'approve',
            ])
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // F. TEAM MEMBER MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function site_secretary_cannot_add_team_members(): void
    {
        // site_secretary has assignments.view but NOT assignments.manage
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $team = Team::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => null,
        ]);
        $person = Person::factory()->create();

        $this->actingAs($secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}/members", [
                'person_id' => $person->id,
                'role_in_team' => 'member',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function campaign_leader_can_add_team_members(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $team = Team::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => null,
        ]);
        $person = Person::factory()->create();

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}/members", [
                'person_id' => $person->id,
                'role_in_team' => 'member',
            ])
            ->assertStatus(201);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // G. OUTCOME RECORDING & FOLLOW-UP MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function missionary_can_record_outcome(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                'mission_site_id' => $this->siteA1->id,
                'outcome_type' => 'decision',
                'outcome_date' => now()->toDateString(),
                'visitor_name' => 'Jane Doe',
            ])
            ->assertStatus(201);
    }

    #[Test]
    public function site_scoped_missionary_cannot_record_outcome_for_another_site(): void
    {
        // siteA2 belongs to the same campaign, so the campaign/site pairing is
        // valid — the 403 comes purely from the site-scoped authorisation.
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                'mission_site_id' => $this->siteA2->id,
                'outcome_type' => 'decision',
                'outcome_date' => now()->toDateString(),
                'visitor_name' => 'Out Of Scope',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function outcome_store_rejects_site_from_another_campaign(): void
    {
        // siteB1 belongs to campaign B, so the campaignA/siteB1 pairing is
        // invalid and is rejected before authorisation is considered.
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                'mission_site_id' => $this->siteB1->id,
                'outcome_type' => 'decision',
                'outcome_date' => now()->toDateString(),
                'visitor_name' => 'Wrong Campaign',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function campaign_scoped_leader_can_record_outcome_for_any_campaign_site(): void
    {
        // Campaign scope keeps working: the leader may record at any site of
        // the campaign they hold, not just one.
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                'mission_site_id' => $this->siteA2->id,
                'outcome_type' => 'decision',
                'outcome_date' => now()->toDateString(),
                'visitor_name' => 'Campaign Wide',
            ])
            ->assertStatus(201);
    }

    #[Test]
    public function user_without_outcomes_record_permission_cannot_record_outcome(): void
    {
        // A role that does not hold outcomes.record at all is denied at the
        // coarse create gate, before any site is considered.
        $role = $this->createRole('site_observer', 'site', ['sites.view']);
        $user = $this->createUserWithSiteRole($role, $this->siteA1);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                'mission_site_id' => $this->siteA1->id,
                'outcome_type' => 'decision',
                'outcome_date' => now()->toDateString(),
                'visitor_name' => 'Unauthorised',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function missionary_cannot_assign_follow_up(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);
        $outcome = $this->createOutcome($this->campaignA, $this->siteA1);
        $person = Person::factory()->create();

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$outcome->id}/follow-up", [
                'follow_up_assigned_to_person_id' => $person->id,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function campaign_leader_can_assign_follow_up(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $outcome = $this->createOutcome($this->campaignA, $this->siteA1);
        $person = Person::factory()->create();

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$outcome->id}/follow-up", [
                'follow_up_assigned_to_person_id' => $person->id,
            ])
            ->assertOk();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // G.2 FOLLOW-UP MANAGEMENT IS SITE-AWARE
    //
    // The audit found follow-up assignment/completion was authorised on the
    // CAMPAIGN alone while viewing was site-aware. A real site coordinator
    // holds outcomes.manage_followup AT THEIR SITE and no campaign-wide
    // authority, so managing a follow-up for an outcome at their own site was
    // refused with 403. These tests pin the corrected contract: campaign
    // authority still works unchanged, and a site grant reaches only its own
    // site's outcomes.
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function site_coordinator_can_assign_follow_up_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $outcome = $this->createOutcome($this->campaignA, $this->siteA1);
        $assignee = Person::factory()->create();

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$outcome->id}/follow-up", [
                'follow_up_assigned_to_person_id' => $assignee->id,
            ])
            ->assertOk()
            ->assertJsonPath('outcome.follow_up_status', 'assigned');
    }

    #[Test]
    public function site_coordinator_can_complete_follow_up_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $outcome = $this->createOutcome($this->campaignA, $this->siteA1);
        $outcome->update(['follow_up_status' => 'assigned']);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$outcome->id}/complete-follow-up", [
                'result' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('outcome.follow_up_status', 'completed');
    }

    #[Test]
    public function site_coordinator_cannot_assign_follow_up_for_sibling_site(): void
    {
        // Same campaign, so the campaign/site pairing is valid; the refusal
        // comes purely from the site boundary (the outcome is not at the
        // coordinator's own site).
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $siblingOutcome = $this->createOutcome($this->campaignA, $this->siteA2);
        $assignee = Person::factory()->create();

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$siblingOutcome->id}/follow-up", [
                'follow_up_assigned_to_person_id' => $assignee->id,
            ])
            ->assertStatus(403);

        // The refused mutation must not have touched the record.
        $this->assertSame('pending', $siblingOutcome->fresh()->follow_up_status);
    }

    #[Test]
    public function site_coordinator_cannot_complete_follow_up_for_sibling_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $siblingOutcome = $this->createOutcome($this->campaignA, $this->siteA2);
        $siblingOutcome->update(['follow_up_status' => 'assigned']);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$siblingOutcome->id}/complete-follow-up", [
                'result' => 'completed',
            ])
            ->assertStatus(403);

        $this->assertSame('assigned', $siblingOutcome->fresh()->follow_up_status);
    }

    #[Test]
    public function campaign_leader_can_complete_follow_up_across_campaign(): void
    {
        // Regression guard: campaign-wide follow-up authority is unchanged by
        // the site-aware fix, and still reaches any site in the campaign.
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $outcome = $this->createOutcome($this->campaignA, $this->siteA2);

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$outcome->id}/complete-follow-up", [
                'result' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('outcome.follow_up_status', 'completed');
    }

    #[Test]
    public function missionary_cannot_complete_follow_up(): void
    {
        // Holding outcomes.record is not enough: manage_followup is required.
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);
        $outcome = $this->createOutcome($this->campaignA, $this->siteA1);

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$outcome->id}/complete-follow-up", [
                'result' => 'completed',
            ])
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // H. MISSIONARY DEPLOYMENT
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function campaign_leader_can_create_assignment(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $person = Person::factory()->create();

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/assignments", [
                'person_id' => $person->id,
                'mission_site_id' => $this->siteA1->id,
            ])
            ->assertStatus(201);
    }

    #[Test]
    public function site_secretary_cannot_create_assignments(): void
    {
        // assignments.manage is required; site_secretary has assignments.view only
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $person = Person::factory()->create();

        $this->actingAs($secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/assignments", [
                'person_id' => $person->id,
                'mission_site_id' => $this->siteA1->id,
            ])
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // I. OUTCOME READ PATH & SITE VISIBILITY (permission-driven workspace)
    //
    // Reading is authorised with the same scope model as writing: a
    // campaign-scoped user keeps the whole campaign, a site-scoped user sees
    // only their own site, and a team-scoped user is resolved to the parent
    // site server-side. Sibling-site data is never returned.
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function site_scoped_missionary_lists_only_own_site_outcomes(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $ownOutcome = $this->createOutcome($this->campaignA, $this->siteA1);
        $siblingOutcome = $this->createOutcome($this->campaignA, $this->siteA2);

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($ownOutcome->id, $ids);
        $this->assertNotContains($siblingOutcome->id, $ids);
    }

    #[Test]
    public function site_scoped_missionary_cannot_list_sibling_site_outcomes_via_filter(): void
    {
        // Even when the client explicitly asks for the sibling site, the
        // server-side scope narrowing keeps its outcomes out of the result set.
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);
        $siblingOutcome = $this->createOutcome($this->campaignA, $this->siteA2);

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes?site_id={$this->siteA2->id}")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertNotContains($siblingOutcome->id, $ids);
        $this->assertSame([], $ids);
    }

    #[Test]
    public function team_scoped_user_lists_parent_site_outcomes(): void
    {
        // The team has no site of its own in the request; the backend resolves
        // the parent site from the team assignment.
        $teamUser = $this->createUserWithTeamRole($this->siteA1);

        $ownOutcome = $this->createOutcome($this->campaignA, $this->siteA1);
        $siblingOutcome = $this->createOutcome($this->campaignA, $this->siteA2);

        $response = $this->actingAs($teamUser, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($ownOutcome->id, $ids);
        $this->assertNotContains($siblingOutcome->id, $ids);
    }

    #[Test]
    public function campaign_scoped_leader_lists_outcomes_across_campaign(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $siteA1Outcome = $this->createOutcome($this->campaignA, $this->siteA1);
        $siteA2Outcome = $this->createOutcome($this->campaignA, $this->siteA2);

        $response = $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($siteA1Outcome->id, $ids);
        $this->assertContains($siteA2Outcome->id, $ids);
    }

    #[Test]
    public function user_without_outcome_read_permission_cannot_list_outcomes(): void
    {
        $role = $this->createRole('site_observer_read', 'site', ['sites.view']);
        $user = $this->createUserWithSiteRole($role, $this->siteA1);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes")
            ->assertStatus(403);
    }

    #[Test]
    public function site_listing_returns_only_authorised_sites(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/sites")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($this->siteA1->id, $ids);
        $this->assertNotContains($this->siteA2->id, $ids);
    }

    #[Test]
    public function campaign_context_is_limited_for_site_scoped_user(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}")
            ->assertOk()
            ->assertJsonPath('access_level', 'limited')
            ->assertJsonFragment(['id' => $this->campaignA->id]);

        $siteIds = collect($response->json('sites'))->pluck('id')->all();

        $this->assertContains($this->siteA1->id, $siteIds);
        $this->assertNotContains($this->siteA2->id, $siteIds);
    }

    #[Test]
    public function campaign_context_is_full_for_campaign_scoped_user(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $response = $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}")
            ->assertOk();

        // Full visibility: no limited marker, and both sites are present.
        $this->assertNull($response->json('access_level'));

        $siteIds = collect($response->json('sites'))->pluck('id')->all();
        $this->assertContains($this->siteA1->id, $siteIds);
        $this->assertContains($this->siteA2->id, $siteIds);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // /workspace/my-sites — authorised SITE CONTEXT (permission-agnostic)
    //
    // This endpoint reports which site contexts a person's effective, active
    // assignments cover, so the workspace can offer the correct site selector.
    // It is deliberately NOT filtered by a module-specific capability such as
    // outcomes.record: a site officer's authority is expressed by the SCOPE
    // their appointment covers, not by one particular permission. The endpoint
    // is read-only and fails closed — it returns a site as context only, grants
    // no permission to act within it, and every real operation is still
    // authorised against the specific resource scope by its own policy.
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function workspace_my_sites_returns_only_authorised_site_contexts(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/workspace/my-sites')
            ->assertOk();

        $ids = collect($response->json('sites'))->pluck('id')->all();

        $this->assertContains($this->siteA1->id, $ids);
        $this->assertNotContains($this->siteA2->id, $ids);
        $this->assertNotContains($this->siteB1->id, $ids);
    }

    #[Test]
    public function workspace_my_sites_returns_site_context_without_recording_permission(): void
    {
        // A legitimately site-scoped officer sees their site as CONTEXT even
        // though they hold no outcomes.record capability. Context is resolved
        // from the scope the appointment covers — not from a module permission.
        $role = $this->createRole('site_observer_no_rec', 'site', ['sites.view']);
        $user = $this->createUserWithSiteRole($role, $this->siteA1);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workspace/my-sites')
            ->assertOk();

        $ids = collect($response->json('sites'))->pluck('id')->all();

        $this->assertContains($this->siteA1->id, $ids);
        $this->assertNotContains($this->siteA2->id, $ids);
        $this->assertNotContains($this->siteB1->id, $ids);
    }

    #[Test]
    public function workspace_my_sites_is_empty_without_any_qualifying_scope(): void
    {
        // No assignment at all → no authorised scope → the endpoint fails
        // closed rather than falling back to a default site.
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workspace/my-sites')
            ->assertOk()
            ->assertJsonCount(0, 'sites');
    }

    #[Test]
    public function workspace_my_sites_excludes_expired_assignment(): void
    {
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id'        => $user->id,
            'system_role_id' => $this->roleMissionary->id,
            'scope_type'     => 'site',
            'scoped_site_id' => $this->siteA1->id,
            'valid_from'     => now()->subMonths(2)->toDateString(),
            'valid_until'    => now()->subMonth()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workspace/my-sites')
            ->assertOk()
            ->assertJsonCount(0, 'sites');
    }

    #[Test]
    public function workspace_my_sites_excludes_revoked_assignment(): void
    {
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id'           => $user->id,
            'system_role_id'    => $this->roleMissionary->id,
            'scope_type'        => 'site',
            'scoped_site_id'    => $this->siteA1->id,
            'status'            => 'revoked',
            'revoked_at'        => now(),
            'revocation_reason' => 'Appointment withdrawn',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workspace/my-sites')
            ->assertOk()
            ->assertJsonCount(0, 'sites');
    }

    #[Test]
    public function team_scoped_assignment_exposes_parent_site_context_not_site_authority(): void
    {
        // A team sitting inside a site resolves to that site as CONTEXT...
        $teamUser = $this->createUserWithTeamRole($this->siteA1);

        $response = $this->actingAs($teamUser, 'sanctum')
            ->getJson('/api/v1/workspace/my-sites')
            ->assertOk();

        $ids = collect($response->json('sites'))->pluck('id')->all();
        $this->assertContains($this->siteA1->id, $ids);
        $this->assertNotContains($this->siteA2->id, $ids);

        // ...but a team-scoped appointment confers NO site-wide authority:
        // standing up a team at that site still requires assignments.manage.
        $this->actingAs($teamUser, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams", [
                'name'            => 'Should Not Exist',
                'mission_site_id' => $this->siteA1->id,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function returning_a_site_context_does_not_grant_operation_permission(): void
    {
        // Holding sites.view alone is enough to be given the site as context...
        $role = $this->createRole('site_context_only', 'site', ['sites.view']);
        $user = $this->createUserWithSiteRole($role, $this->siteA1);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workspace/my-sites')
            ->assertOk();

        $this->assertContains(
            $this->siteA1->id,
            collect($response->json('sites'))->pluck('id')->all()
        );

        // ...but recording an outcome at that same site is still refused,
        // because the person holds no outcomes.record capability.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                'mission_site_id' => $this->siteA1->id,
                'outcome_type'    => 'decision',
                'outcome_date'    => now()->toDateString(),
                'visitor_name'    => 'Test Visitor',
            ])
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // J. SITE COORDINATOR — own-site teams and outreach registration
    //
    // The coordinator is the top officer at a SITE. Their assignments.manage and
    // outreach-contacts.create resolve at the site scope, so they must be able
    // to manage teams and register contacts at their OWN site — and never at a
    // sibling site or campaign-wide.
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function site_coordinator_can_create_team_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams", [
                'name' => 'Site A1 Evangelism Team',
                'mission_site_id' => $this->siteA1->id,
            ])
            ->assertStatus(201)
            ->assertJsonFragment(['mission_site_id' => $this->siteA1->id]);
    }

    #[Test]
    public function site_coordinator_cannot_create_team_at_sibling_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams", [
                'name' => 'Site A2 Team',
                'mission_site_id' => $this->siteA2->id,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function site_coordinator_cannot_create_campaign_wide_team(): void
    {
        // A team with no site has campaign reach, beyond site authority.
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams", [
                'name' => 'Campaign Wide Team',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function site_coordinator_can_add_member_to_team_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $team = $this->createTeam($this->campaignA, $this->siteA1);
        $person = Person::factory()->create();

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}/members", [
                'person_id' => $person->id,
                'role_in_team' => 'member',
            ])
            ->assertStatus(201);
    }

    #[Test]
    public function site_coordinator_cannot_manage_team_at_sibling_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $siblingTeam = $this->createTeam($this->campaignA, $this->siteA2);
        $person = Person::factory()->create();

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$siblingTeam->id}/members", [
                'person_id' => $person->id,
                'role_in_team' => 'member',
            ])
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // J.2 TEAM DETAIL & MANAGEMENT
    //
    // Teams can be listed and created from Site Operations; the audit found the
    // DETAIL workflow missing (open a team, edit it, view the current roster,
    // appoint or change its leader, add/remove members). These tests pin the
    // contract behind that workflow: leadership IS a membership role, removal
    // is a soft end, and every operation stays inside the coordinator's own
    // site.
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function site_coordinator_can_update_team_details_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $team = $this->createTeam($this->campaignA, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}", [
                'name' => 'Renamed Outreach Team',
                'description' => 'Updated at site level',
            ])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Renamed Outreach Team']);

        $this->assertSame('Renamed Outreach Team', $team->fresh()->name);
    }

    #[Test]
    public function site_coordinator_cannot_update_team_details_at_sibling_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $siblingTeam = $this->createTeam($this->campaignA, $this->siteA2);

        $this->actingAs($coordinator, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$siblingTeam->id}", [
                'name' => 'Should Not Change',
            ])
            ->assertStatus(403);

        $this->assertNotSame('Should Not Change', $siblingTeam->fresh()->name);
    }

    #[Test]
    public function team_detail_returns_only_the_current_roster(): void
    {
        // A removed member is soft-ended, never deleted. The roster must show
        // CURRENT membership only, otherwise a departed person — or a departed
        // LEADER — would still be presented as being on the team.
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $team = $this->createTeam($this->campaignA, $this->siteA1);

        $active = TeamMember::create([
            'team_id' => $team->id,
            'person_id' => Person::factory()->create()->id,
            'role_in_team' => 'member',
        ]);
        $departed = TeamMember::create([
            'team_id' => $team->id,
            'person_id' => Person::factory()->create()->id,
            'role_in_team' => 'leader',
            'ended_on' => now()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}")
            ->assertOk();

        $memberIds = collect($response->json('members'))->pluck('id')->all();

        $this->assertContains($active->id, $memberIds);
        $this->assertNotContains($departed->id, $memberIds);
    }

    #[Test]
    public function team_listing_counts_only_current_members(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $team = $this->createTeam($this->campaignA, $this->siteA1);

        TeamMember::create([
            'team_id' => $team->id,
            'person_id' => Person::factory()->create()->id,
            'role_in_team' => 'member',
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'person_id' => Person::factory()->create()->id,
            'role_in_team' => 'member',
            'ended_on' => now()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/teams?site_id={$this->siteA1->id}")
            ->assertOk();

        $row = collect($response->json())->firstWhere('id', $team->id);

        $this->assertSame(1, $row['members_count']);
    }

    #[Test]
    public function site_coordinator_can_change_team_leader_at_own_site(): void
    {
        // Leadership is the `leader` value of an existing membership, so
        // transferring it is a role change on that member — there is no second
        // leadership model. Promoting a member demotes the sitting leader.
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $team = $this->createTeam($this->campaignA, $this->siteA1);

        $sittingLeader = TeamMember::create([
            'team_id' => $team->id,
            'person_id' => Person::factory()->create()->id,
            'role_in_team' => 'leader',
        ]);
        $candidate = TeamMember::create([
            'team_id' => $team->id,
            'person_id' => Person::factory()->create()->id,
            'role_in_team' => 'member',
        ]);

        $this->actingAs($coordinator, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}/members/{$candidate->id}", [
                'role_in_team' => 'leader',
            ])
            ->assertOk()
            ->assertJsonPath('role_in_team', 'leader');

        $this->assertSame('member', $sittingLeader->fresh()->role_in_team);
        $this->assertSame(
            1,
            $team->members()->where('role_in_team', 'leader')->whereNull('ended_on')->count(),
            'Changing the leader must leave exactly one active leader.'
        );
    }

    #[Test]
    public function site_coordinator_cannot_change_member_role_at_sibling_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $siblingTeam = $this->createTeam($this->campaignA, $this->siteA2);
        $member = TeamMember::create([
            'team_id' => $siblingTeam->id,
            'person_id' => Person::factory()->create()->id,
            'role_in_team' => 'member',
        ]);

        $this->actingAs($coordinator, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$siblingTeam->id}/members/{$member->id}", [
                'role_in_team' => 'leader',
            ])
            ->assertStatus(403);

        $this->assertSame('member', $member->fresh()->role_in_team);
    }

    #[Test]
    public function a_departed_leader_does_not_block_appointing_a_new_leader(): void
    {
        // Before the fix, a soft-ended leader still satisfied the
        // "team already has a leader" check, so a team could never appoint a
        // replacement. Only an ACTIVE leader may block the appointment.
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $team = $this->createTeam($this->campaignA, $this->siteA1);

        TeamMember::create([
            'team_id' => $team->id,
            'person_id' => Person::factory()->create()->id,
            'role_in_team' => 'leader',
            'ended_on' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}/members", [
                'person_id' => Person::factory()->create()->id,
                'role_in_team' => 'leader',
            ])
            ->assertStatus(201);
    }

    #[Test]
    public function campaign_leader_can_still_manage_any_team_in_the_campaign(): void
    {
        // Regression guard: the corrected site branch never narrows the
        // campaign-wide authority a campaign leader already held.
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $team = $this->createTeam($this->campaignA, $this->siteA2);

        $this->actingAs($leader, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}", [
                'name' => 'Campaign Managed',
            ])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Campaign Managed']);
    }

    #[Test]
    public function site_coordinator_can_register_outreach_contact_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson('/api/v1/outreach-contacts', [
                'first_name' => 'Grace',
                'last_name' => 'Aciro',
                'engagement_stage' => 'new_contact',
                'campaign_id' => $this->campaignA->id,
                'mission_site_id' => $this->siteA1->id,
            ])
            ->assertStatus(201);
    }

    #[Test]
    public function site_coordinator_cannot_register_outreach_contact_at_sibling_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson('/api/v1/outreach-contacts', [
                'first_name' => 'Out',
                'last_name' => 'Of Scope',
                'engagement_stage' => 'new_contact',
                'campaign_id' => $this->campaignA->id,
                'mission_site_id' => $this->siteA2->id,
            ])
            ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // K. SITE OPERATIONS LISTING ADMISSION
    //
    // The Site Operations overview lists teams, assignments and their rosters
    // for the ACTIVE site. A site-scoped officer holds no campaign-wide
    // authority, so admitting those listings with a campaign-only check denied
    // the whole overview with "This action is unauthorized." These tests pin
    // the corrected contract: the listing is admitted at the NAMED site, still
    // narrows to the caller's own site, and never widens to the campaign.
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function site_coordinator_can_list_teams_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $ownTeam = $this->createTeam($this->campaignA, $this->siteA1);
        $siblingTeam = $this->createTeam($this->campaignA, $this->siteA2);

        $response = $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/teams?site_id={$this->siteA1->id}")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($ownTeam->id, $ids);
        $this->assertNotContains($siblingTeam->id, $ids);
    }

    #[Test]
    public function site_coordinator_can_list_assignments_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $this->createAssignment($this->campaignA, $this->siteA1);
        $this->createAssignment($this->campaignA, $this->siteA2);

        $response = $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/assignments?site_id={$this->siteA1->id}")
            ->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame($this->siteA1->id, $response->json('0.mission_site_id'));
    }

    #[Test]
    public function site_coordinator_can_view_a_team_at_own_site(): void
    {
        // The overview resolves each team's roster to name its leader, so a
        // single-team read must succeed for the coordinator too.
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $team = $this->createTeam($this->campaignA, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $team->id]);
    }

    #[Test]
    public function site_coordinator_cannot_list_teams_for_a_sibling_site(): void
    {
        // The site branch authorises at the NAMED site, so asking for a site
        // the coordinator does not hold is refused rather than silently
        // returning the sibling's teams.
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/teams?site_id={$this->siteA2->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function site_coordinator_cannot_list_assignments_for_a_sibling_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/assignments?site_id={$this->siteA2->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function site_coordinator_listing_teams_without_a_site_stays_campaign_denied(): void
    {
        // No widening: without a named site the admission is campaign-wide,
        // which a site-scoped officer does not hold.
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/teams")
            ->assertStatus(403);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/assignments")
            ->assertStatus(403);
    }

    #[Test]
    public function site_secretary_can_list_assignments_at_own_site(): void
    {
        // The same defect affected the secretary, who holds assignments.view
        // only inside their own site.
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $this->createAssignment($this->campaignA, $this->siteA1);

        $this->actingAs($secretary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/assignments?site_id={$this->siteA1->id}")
            ->assertOk();
    }

    #[Test]
    public function campaign_leader_still_lists_the_whole_campaign_without_a_site(): void
    {
        // Regression guard: the campaign-wide admission path is unchanged.
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $teamA1 = $this->createTeam($this->campaignA, $this->siteA1);
        $teamA2 = $this->createTeam($this->campaignA, $this->siteA2);

        $response = $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/teams")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($teamA1->id, $ids);
        $this->assertContains($teamA2->id, $ids);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // L. WORKSPACE SWITCHING
    //
    // A person may hold several workspaces at once. Both must be advertised by
    // /auth/me and both must be reachable within one session — switching is a
    // routing concern on the client, never a re-authentication.
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function user_with_both_workspaces_reaches_each_in_one_session(): void
    {
        $user = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $user->id]);
        MissionaryProfile::create(['person_id' => $person->id]);

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $this->roleSiteCoordinator->id,
            'scope_type' => 'site',
            'scoped_site_id' => $this->siteA1->id,
        ]);

        // /auth/me advertises both workspaces, each with its own landing route.
        $workspaces = collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()->json('user.workspaces')
        );
        $keys = $workspaces->pluck('key')->all();

        $this->assertContains('missionary', $keys);
        $this->assertContains('site_operations', $keys);
        $this->assertSame('/site-operations', $workspaces->firstWhere('key', 'site_operations')['home']);
        $this->assertSame('/dashboard', $workspaces->firstWhere('key', 'missionary')['home']);

        // Site Operations surface — the endpoint whose 403 broke the overview.
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/teams?site_id={$this->siteA1->id}")
            ->assertOk();

        // Missionary surface, same session, no re-login.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                'mission_site_id' => $this->siteA1->id,
                'outcome_type' => 'decision',
                'outcome_date' => now()->toDateString(),
                'visitor_name' => 'Switch Test',
            ])
            ->assertStatus(201);

        // Switching never promotes a site officer into administrative
        // authority they were not granted.
        $this->assertNotContains('campaign_management', $keys);
        $this->assertNotContains('platform_administration', $keys);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // M. ATTENDANCE REGISTER INSPECTION
    //
    // Registers can be listed from Site Operations; the audit found the
    // INSPECTION workflow missing (open a register, read its entries and its
    // summary/reconciliation). These tests pin the contract behind that
    // workflow: inspection is decided on the register's real site, the reading
    // endpoints inherit that decision, and the existing verification rules are
    // unchanged.
    // ══════════════════════════════════════════════════════════════════════════

    #[Test]
    public function site_coordinator_can_open_a_register_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $register = $this->createRegister($this->campaignA, $this->siteA1);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$register->id}")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $register->id,
                'register_name' => 'Fieldwork Register',
            ]);
    }

    #[Test]
    public function site_coordinator_cannot_open_a_register_at_a_sibling_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $siblingRegister = $this->createRegister($this->campaignA, $this->siteA2);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$siblingRegister->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function register_reading_endpoints_inherit_the_site_decision(): void
    {
        // participants and reconciliation are the reads the detail page is
        // built on, so they must resolve to the SAME site decision as show.
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $own = $this->createRegister($this->campaignA, $this->siteA1);
        $sibling = $this->createRegister($this->campaignA, $this->siteA2);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$own->id}/participants")
            ->assertOk();

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$own->id}/reconciliation")
            ->assertOk();

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$sibling->id}/participants")
            ->assertStatus(403);

        $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$sibling->id}/reconciliation")
            ->assertStatus(403);
    }

    #[Test]
    public function register_listing_narrows_to_the_coordinators_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $own = $this->createRegister($this->campaignA, $this->siteA1);
        $sibling = $this->createRegister($this->campaignA, $this->siteA2);

        $response = $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers?campaign_id={$this->campaignA->id}")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($sibling->id, $ids);
    }

    #[Test]
    public function register_entries_and_summary_are_scoped_to_the_coordinators_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $own = $this->createRegister($this->campaignA, $this->siteA1);
        $this->createRegister($this->campaignA, $this->siteA2);

        // Record one identified attendance at the coordinator's own site
        // through the ordinary entries endpoint.
        $person = Person::factory()->create();
        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$own->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'person_id' => $person->id,
                ]],
            ])
            ->assertOk();

        // The entry is visible on the register itself …
        $register = $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$own->id}")
            ->assertOk();

        $this->assertCount(1, $register->json('entries'));

        // … and the aggregate covers ONLY the coordinator's own site: the
        // sibling register is excluded, so exactly one register is counted.
        $summary = $this->actingAs($coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/summary?campaign_id={$this->campaignA->id}")
            ->assertOk();

        $this->assertSame(1, $summary->json('site_attendance.registers'));
        $this->assertSame(1, $summary->json('site_attendance.identified'));
    }

    #[Test]
    public function site_coordinator_can_verify_a_submitted_register_at_own_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $register = $this->createRegister($this->campaignA, $this->siteA1, ['status' => 'submitted']);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/verify")
            ->assertOk();

        $this->assertSame('verified', $register->fresh()->status);
    }

    #[Test]
    public function a_register_cannot_be_verified_twice(): void
    {
        // Verification closes a register; the status rule is unchanged.
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $register = $this->createRegister($this->campaignA, $this->siteA1, ['status' => 'verified']);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/verify")
            ->assertStatus(403);
    }

    #[Test]
    public function site_secretary_cannot_verify_a_register(): void
    {
        // attendance.submit is not attendance.verify — recording attendance
        // and certifying it are different responsibilities, held by different
        // roles. The secretary holds submit + view only.
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $register = $this->createRegister($this->campaignA, $this->siteA1, ['status' => 'submitted']);

        $this->actingAs($secretary, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/verify")
            ->assertStatus(403);

        $this->assertSame('submitted', $register->fresh()->status);
    }

    #[Test]
    public function site_coordinator_cannot_verify_a_register_at_a_sibling_site(): void
    {
        $coordinator = $this->createUserWithSiteRole($this->roleSiteCoordinator, $this->siteA1);
        $sibling = $this->createRegister($this->campaignA, $this->siteA2, ['status' => 'submitted']);

        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$sibling->id}/verify")
            ->assertStatus(403);

        $this->assertSame('submitted', $sibling->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FIXTURE BUILDERS
    // ══════════════════════════════════════════════════════════════════════════

    private function buildFixtures(): void
    {
        // Org hierarchy
        $typeUnion = OrgUnitType::factory()->create(['code' => 'union', 'label' => 'Union']);
        $typeConf = OrgUnitType::factory()->create(['code' => 'conference', 'label' => 'Conference']);

        $this->uu = OrgUnit::factory()->create(['org_unit_type_id' => $typeUnion->id, 'name' => 'Uganda Union']);
        $this->cuc = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeConf->id,
            'parent_id' => $this->uu->id,
            'name' => 'Central Uganda Conference',
        ]);

        // Programme
        $pt = ProgrammeType::factory()->create(['code' => 'global_mission', 'label' => 'Global Mission']);
        $programme = Programme::factory()->create(['programme_type_id' => $pt->id, 'owning_org_unit_id' => $this->uu->id]);

        // Campaigns
        $this->campaignA = $this->createCampaign('Campaign Alpha', 'active', $programme);
        $this->campaignB = $this->createCampaign('Campaign Beta', 'active', $programme);

        // Sites
        $this->siteA1 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A1']);
        $this->siteA2 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A2']);
        $this->siteB1 = MissionSite::factory()->create(['campaign_id' => $this->campaignB->id, 'name' => 'Site B1']);

        // Outcome types are DB-driven reference data validated at store time,
        // so the code used by these tests must actually exist and be active.
        OutcomeType::factory()->create(['code' => 'decision', 'is_active' => true]);

        // Engagement stages are likewise reference data required by the
        // outreach-contact endpoint.
        EngagementStage::create([
            'code' => 'new_contact',
            'label' => 'New Contact',
            'color_key' => 'blue',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        // Roles with permissions
        $this->roleAdmin = $this->createRole('platform_admin', 'global', $this->adminPerms());
        $this->roleCampaignLeader = $this->createRole('campaign_leader', 'campaign', $this->leaderPerms());
        $this->roleSiteSecretary = $this->createRole('site_secretary', 'site', $this->secretaryPerms());
        $this->roleSiteCoordinator = $this->createRole('site_coordinator', 'site', $this->coordinatorPerms());
        $this->roleMissionary = $this->createRole('missionary', 'site', $this->missionaryPerms());
    }

    private function createCampaign(string $name, string $status, ?Programme $programme = null): Campaign
    {
        $prog = $programme ?? Campaign::first()?->programme ?? Programme::first();

        return Campaign::factory()->create([
            'programme_id' => $prog->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name' => $name,
            'status' => $status,
        ]);
    }

    private function createTeam(Campaign $campaign, ?MissionSite $site = null): Team
    {
        return Team::factory()->create([
            'campaign_id' => $campaign->id,
            'mission_site_id' => $site?->id,
        ]);
    }

    /**
     * A standalone (site-level) attendance register — the campaign/day model
     * the Site Secretary inspects. activity_id stays null, which is what
     * distinguishes it from an activity register.
     */
    private function createRegister(Campaign $campaign, MissionSite $site, array $overrides = []): AttendanceRegister
    {
        return AttendanceRegister::create([
            'campaign_id' => $campaign->id,
            'mission_site_id' => $site->id,
            'register_name' => 'Fieldwork Register',
            'register_date' => now()->toDateString(),
            'register_type' => 'roll_call',
            'status' => 'open',
            ...$overrides,
        ]);
    }

    /**
     * Deploy a fresh person to a campaign/site, as the assignment listing sees
     * it. The assignment is deliberately NOT a role assignment: it is a
     * deployment fact, separate from any authority the person may hold.
     */
    private function createAssignment(Campaign $campaign, MissionSite $site): CampaignAssignment
    {
        $person = Person::factory()->create();

        return CampaignAssignment::create([
            'campaign_id' => $campaign->id,
            'person_id' => $person->id,
            'mission_site_id' => $site->id,
            'status' => 'confirmed',
            'assigned_by_user_id' => User::factory()->create()->id,
        ]);
    }

    private function createRole(string $code, string $scopeType, array $permCodes): SystemRole
    {
        $role = SystemRole::factory()->create([
            'code' => $code,
            'label' => ucwords(str_replace('_', ' ', $code)),
            'scope_type' => $scopeType,
        ]);

        $permIds = collect($permCodes)->map(function ($pc) {
            return Permission::firstOrCreate(['code' => $pc], ['label' => $pc, 'module' => explode('.', $pc)[0]])->id;
        })->toArray();

        $this->publishRolePermissions($role, $permIds);

        return $role;
    }

    private function createUserWithGlobalRole(SystemRole $role): User
    {
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'global',
        ]);

        return $user;
    }

    private function createUserWithCampaignRole(SystemRole $role, Campaign $campaign): User
    {
        $user = User::factory()->create();
        $this->assignCampaignRole($user, $role, $campaign);

        return $user;
    }

    private function assignCampaignRole(User $user, SystemRole $role, Campaign $campaign): void
    {
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $campaign->id,
        ]);
    }

    private function createUserWithSiteRole(SystemRole $role, MissionSite $site): User
    {
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'site',
            'scoped_site_id' => $site->id,
        ]);

        return $user;
    }

    /**
     * Create a user holding a team-scoped assignment on a team that belongs to
     * the given site. Used to prove the backend resolves the team's parent site
     * rather than trusting a client-supplied site id.
     */
    private function createUserWithTeamRole(MissionSite $site): User
    {
        $role = $this->createRole('team_leader_scoped', 'team', ['outcomes.record', 'outcomes.view']);

        $team = Team::factory()->create([
            'campaign_id' => $site->campaign_id,
            'mission_site_id' => $site->id,
        ]);

        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => 'team',
            'scoped_team_id' => $team->id,
        ]);

        return $user;
    }

    private function createDraftReport(Campaign $campaign, MissionSite $site): FieldReport
    {
        return FieldReport::factory()->create([
            'campaign_id' => $campaign->id,
            'mission_site_id' => $site->id,
            'report_period_type' => 'daily',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => 'draft',
        ]);
    }

    private function createOutcome(Campaign $campaign, MissionSite $site): MissionOutcome
    {
        return MissionOutcome::factory()->create([
            'campaign_id' => $campaign->id,
            'mission_site_id' => $site->id,
            'outcome_type' => 'decision',
            'outcome_date' => now()->toDateString(),
            'visitor_name' => 'Test Visitor',
            'follow_up_status' => 'pending',
        ]);
    }

    // ── Permission sets ───────────────────────────────────────────────────────

    private function adminPerms(): array
    {
        return [
            'admin.users.manage', 'admin.roles.manage', 'admin.org.manage',
            'campaigns.create', 'campaigns.edit', 'campaigns.view', 'campaigns.archive',
            'sites.create', 'sites.edit', 'sites.view',
            'assignments.manage', 'assignments.view',
            'attendance.submit', 'attendance.verify', 'attendance.view',
            'report.submit', 'report.review', 'report.view',
            'outcomes.record', 'outcomes.view', 'outcomes.manage_followup',
            'people.create', 'people.edit', 'people.view',
        ];
    }

    private function leaderPerms(): array
    {
        return [
            'campaigns.edit', 'campaigns.view',
            'sites.create', 'sites.edit', 'sites.view',
            'assignments.manage', 'assignments.view',
            'report.review', 'report.view', 'report.submit',
            'attendance.verify', 'attendance.view',
            'outcomes.view', 'outcomes.manage_followup', 'outcomes.record',
            'people.create', 'people.edit', 'people.view',
        ];
    }

    private function secretaryPerms(): array
    {
        return [
            'sites.view',
            'assignments.view',
            'attendance.submit', 'attendance.view',
            'report.submit', 'report.view',
            'outcomes.record', 'outcomes.view',
            'people.view',
        ];
    }

    private function missionaryPerms(): array
    {
        return [
            'attendance.submit',
            'outcomes.record',
            'people.view',
        ];
    }

    /**
     * A site coordinator is the top officer at a site: they manage teams and
     * register outreach contacts, but only within their own site.
     *
     * outcomes.manage_followup is included because the seeded site_coordinator
     * role genuinely holds it at site scope — the P0 follow-up defect was that
     * a site-scoped holder was still evaluated against the campaign alone.
     *
     * The attendance codes mirror the seeded role too: the coordinator may
     * fill registers AND certify them, which is why they are the role that
     * exercises verification in the inspection tests.
     */
    private function coordinatorPerms(): array
    {
        return [
            'sites.view', 'sites.edit',
            'assignments.view', 'assignments.manage',
            'attendance.submit', 'attendance.verify', 'attendance.view',
            'outreach-contacts.create', 'outreach-contacts.edit', 'outreach-contacts.view',
            'outcomes.record', 'outcomes.view', 'outcomes.manage_followup',
            'people.view',
        ];
    }
}
