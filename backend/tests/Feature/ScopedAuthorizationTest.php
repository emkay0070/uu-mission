<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\FieldReport;
use App\Models\MissionOutcome;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\OutcomeType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\TeamType;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

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
 */
class ScopedAuthorizationTest extends TestCase
{
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
    private SystemRole $roleMissionary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // A. UNAUTHENTICATED REQUESTS
    // ══════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function unauthenticated_campaign_list_returns_401(): void
    {
        $this->getJson('/api/v1/campaigns')->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unauthenticated_campaign_show_returns_401(): void
    {
        $this->getJson("/api/v1/campaigns/{$this->campaignA->id}")->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unauthenticated_site_list_returns_401(): void
    {
        $this->getJson("/api/v1/campaigns/{$this->campaignA->id}/sites")->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unauthenticated_report_list_returns_401(): void
    {
        $this->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports")->assertStatus(401);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // B. GLOBAL SCOPE — platform_admin has full access
    // ══════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function platform_admin_can_transition_campaign(): void
    {
        $admin    = $this->createUserWithGlobalRole($this->roleAdmin);
        $planning = $this->createCampaign('Planning Campaign', 'planning');

        $this->actingAs($admin, 'sanctum')
             ->postJson("/api/v1/campaigns/{$planning->id}/transition", ['to' => 'active'])
             ->assertOk()
             ->assertJsonFragment(['status' => 'active']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_for_campaign_a_can_view_campaign_a(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignA->id}")
             ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_for_campaign_a_cannot_view_campaign_b(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignB->id}")
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_for_campaign_a_cannot_update_campaign_b(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
             ->patchJson("/api/v1/campaigns/{$this->campaignB->id}", ['name' => 'Hacked'])
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_can_list_sites_for_own_campaign(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignA->id}/sites")
             ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_for_campaign_a_cannot_list_sites_for_campaign_b(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignB->id}/sites")
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_can_view_campaign_stats(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignA->id}/stats")
             ->assertOk()
             ->assertJsonFragment(['campaign_id' => $this->campaignA->id]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_can_transition_own_campaign_from_planning_to_active(): void
    {
        $leader   = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $planning = $this->createCampaign('Transition Test', 'planning');
        // Give the leader access to this planning campaign too
        $this->assignCampaignRole($leader, $this->roleCampaignLeader, $planning);

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$planning->id}/transition", ['to' => 'active'])
             ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_cannot_transition_campaign_b(): void
    {
        $leader   = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignB->id}/transition", ['to' => 'completed'])
             ->assertStatus(403);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // D. SITE SCOPE — site_secretary for siteA1 only
    // ══════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_secretary_for_site_a1_can_view_site_a1(): void
    {
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);

        $this->actingAs($secretary, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignA->id}/sites/{$this->siteA1->id}")
             ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_secretary_for_site_a1_cannot_view_site_a2(): void
    {
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);

        $this->actingAs($secretary, 'sanctum')
             ->getJson("/api/v1/campaigns/{$this->campaignA->id}/sites/{$this->siteA2->id}")
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_secretary_can_submit_draft_report(): void
    {
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $report    = $this->createDraftReport($this->campaignA, $this->siteA1);

        $this->actingAs($secretary, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/submit")
             ->assertOk()
             ->assertJsonFragment(['status' => 'submitted']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_secretary_cannot_review_submitted_report(): void
    {
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $report    = $this->createDraftReport($this->campaignA, $this->siteA1);
        $report->update(['status' => 'submitted']);

        $this->actingAs($secretary, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/review", [
                 'action' => 'approve',
             ])
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_for_campaign_a_cannot_review_report_in_campaign_b(): void
    {
        $leader  = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $reportB = $this->createDraftReport($this->campaignB, $this->siteB1);
        $reportB->update(['status' => 'submitted']);

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignB->id}/reports/{$reportB->id}/review", [
                 'action' => 'approve',
             ])
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function missionary_cannot_review_reports(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);
        $report     = $this->createDraftReport($this->campaignA, $this->siteA1);
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_secretary_cannot_add_team_members(): void
    {
        // site_secretary has assignments.view but NOT assignments.manage
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $team      = Team::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => null,
        ]);
        $person = Person::factory()->create();

        $this->actingAs($secretary, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}/members", [
                 'person_id'    => $person->id,
                 'role_in_team' => 'member',
             ])
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_can_add_team_members(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $team   = Team::factory()->create([
            'campaign_id'     => $this->campaignA->id,
            'mission_site_id' => null,
        ]);
        $person = Person::factory()->create();

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/teams/{$team->id}/members", [
                 'person_id'    => $person->id,
                 'role_in_team' => 'member',
             ])
             ->assertStatus(201);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // G. OUTCOME RECORDING & FOLLOW-UP MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function missionary_can_record_outcome(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $this->actingAs($missionary, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                 'mission_site_id' => $this->siteA1->id,
                 'outcome_type'    => 'decision',
                 'outcome_date'    => now()->toDateString(),
                 'visitor_name'    => 'Jane Doe',
             ])
             ->assertStatus(201);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_scoped_missionary_cannot_record_outcome_for_another_site(): void
    {
        // siteA2 belongs to the same campaign, so the campaign/site pairing is
        // valid — the 403 comes purely from the site-scoped authorisation.
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $this->actingAs($missionary, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                 'mission_site_id' => $this->siteA2->id,
                 'outcome_type'    => 'decision',
                 'outcome_date'    => now()->toDateString(),
                 'visitor_name'    => 'Out Of Scope',
             ])
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function outcome_store_rejects_site_from_another_campaign(): void
    {
        // siteB1 belongs to campaign B, so the campaignA/siteB1 pairing is
        // invalid and is rejected before authorisation is considered.
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $this->actingAs($missionary, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                 'mission_site_id' => $this->siteB1->id,
                 'outcome_type'    => 'decision',
                 'outcome_date'    => now()->toDateString(),
                 'visitor_name'    => 'Wrong Campaign',
             ])
             ->assertStatus(422);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_scoped_leader_can_record_outcome_for_any_campaign_site(): void
    {
        // Campaign scope keeps working: the leader may record at any site of
        // the campaign they hold, not just one.
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                 'mission_site_id' => $this->siteA2->id,
                 'outcome_type'    => 'decision',
                 'outcome_date'    => now()->toDateString(),
                 'visitor_name'    => 'Campaign Wide',
             ])
             ->assertStatus(201);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function user_without_outcomes_record_permission_cannot_record_outcome(): void
    {
        // A role that does not hold outcomes.record at all is denied at the
        // coarse create gate, before any site is considered.
        $role = $this->createRole('site_observer', 'site', ['sites.view']);
        $user = $this->createUserWithSiteRole($role, $this->siteA1);

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes", [
                 'mission_site_id' => $this->siteA1->id,
                 'outcome_type'    => 'decision',
                 'outcome_date'    => now()->toDateString(),
                 'visitor_name'    => 'Unauthorised',
             ])
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function missionary_cannot_assign_follow_up(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);
        $outcome    = $this->createOutcome($this->campaignA, $this->siteA1);
        $person     = Person::factory()->create();

        $this->actingAs($missionary, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$outcome->id}/follow-up", [
                 'follow_up_assigned_to_person_id' => $person->id,
             ])
             ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_can_assign_follow_up(): void
    {
        $leader  = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $outcome = $this->createOutcome($this->campaignA, $this->siteA1);
        $person  = Person::factory()->create();

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes/{$outcome->id}/follow-up", [
                 'follow_up_assigned_to_person_id' => $person->id,
             ])
             ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // H. MISSIONARY DEPLOYMENT
    // ══════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_leader_can_create_assignment(): void
    {
        $leader = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $person = Person::factory()->create();

        $this->actingAs($leader, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/assignments", [
                 'person_id'       => $person->id,
                 'mission_site_id' => $this->siteA1->id,
             ])
             ->assertStatus(201);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_secretary_cannot_create_assignments(): void
    {
        // assignments.manage is required; site_secretary has assignments.view only
        $secretary = $this->createUserWithSiteRole($this->roleSiteSecretary, $this->siteA1);
        $person    = Person::factory()->create();

        $this->actingAs($secretary, 'sanctum')
             ->postJson("/api/v1/campaigns/{$this->campaignA->id}/assignments", [
                 'person_id'       => $person->id,
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_scoped_missionary_lists_only_own_site_outcomes(): void
    {
        $missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);

        $ownOutcome     = $this->createOutcome($this->campaignA, $this->siteA1);
        $siblingOutcome = $this->createOutcome($this->campaignA, $this->siteA2);

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($ownOutcome->id, $ids);
        $this->assertNotContains($siblingOutcome->id, $ids);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_scoped_missionary_cannot_list_sibling_site_outcomes_via_filter(): void
    {
        // Even when the client explicitly asks for the sibling site, the
        // server-side scope narrowing keeps its outcomes out of the result set.
        $missionary     = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);
        $siblingOutcome = $this->createOutcome($this->campaignA, $this->siteA2);

        $response = $this->actingAs($missionary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes?site_id={$this->siteA2->id}")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertNotContains($siblingOutcome->id, $ids);
        $this->assertSame([], $ids);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function team_scoped_user_lists_parent_site_outcomes(): void
    {
        // The team has no site of its own in the request; the backend resolves
        // the parent site from the team assignment.
        $teamUser = $this->createUserWithTeamRole($this->siteA1);

        $ownOutcome     = $this->createOutcome($this->campaignA, $this->siteA1);
        $siblingOutcome = $this->createOutcome($this->campaignA, $this->siteA2);

        $response = $this->actingAs($teamUser, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes")
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($ownOutcome->id, $ids);
        $this->assertNotContains($siblingOutcome->id, $ids);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function user_without_outcome_read_permission_cannot_list_outcomes(): void
    {
        $role = $this->createRole('site_observer_read', 'site', ['sites.view']);
        $user = $this->createUserWithSiteRole($role, $this->siteA1);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/outcomes")
            ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function workspace_my_sites_returns_only_authorised_recording_sites(): void
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function workspace_my_sites_is_empty_without_recording_permission(): void
    {
        $role = $this->createRole('site_observer_no_rec', 'site', ['sites.view']);
        $user = $this->createUserWithSiteRole($role, $this->siteA1);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workspace/my-sites')
            ->assertOk()
            ->assertJsonCount(0, 'sites');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FIXTURE BUILDERS
    // ══════════════════════════════════════════════════════════════════════════

    private function buildFixtures(): void
    {
        // Org hierarchy
        $typeUnion = OrgUnitType::factory()->create(['code' => 'union', 'label' => 'Union']);
        $typeConf  = OrgUnitType::factory()->create(['code' => 'conference', 'label' => 'Conference']);

        $this->uu  = OrgUnit::factory()->create(['org_unit_type_id' => $typeUnion->id, 'name' => 'Uganda Union']);
        $this->cuc = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeConf->id,
            'parent_id'        => $this->uu->id,
            'name'             => 'Central Uganda Conference',
        ]);

        // Programme
        $pt        = ProgrammeType::factory()->create(['code' => 'global_mission', 'label' => 'Global Mission']);
        $programme = Programme::factory()->create(['programme_type_id' => $pt->id, 'owning_org_unit_id' => $this->uu->id]);

        // Campaigns
        $this->campaignA = $this->createCampaign('Campaign Alpha', 'active', $programme);
        $this->campaignB = $this->createCampaign('Campaign Beta',  'active', $programme);

        // Sites
        $this->siteA1 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A1']);
        $this->siteA2 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A2']);
        $this->siteB1 = MissionSite::factory()->create(['campaign_id' => $this->campaignB->id, 'name' => 'Site B1']);

        // Outcome types are DB-driven reference data validated at store time,
        // so the code used by these tests must actually exist and be active.
        OutcomeType::factory()->create(['code' => 'decision', 'is_active' => true]);

        // Roles with permissions
        $this->roleAdmin          = $this->createRole('platform_admin',   'global',   $this->adminPerms());
        $this->roleCampaignLeader = $this->createRole('campaign_leader',  'campaign', $this->leaderPerms());
        $this->roleSiteSecretary  = $this->createRole('site_secretary',   'site',     $this->secretaryPerms());
        $this->roleMissionary     = $this->createRole('missionary',       'site',     $this->missionaryPerms());
    }

    private function createCampaign(string $name, string $status, ?Programme $programme = null): Campaign
    {
        $prog = $programme ?? Campaign::first()?->programme ?? Programme::first();
        return Campaign::factory()->create([
            'programme_id'     => $prog->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name'             => $name,
            'status'           => $status,
        ]);
    }

    private function createRole(string $code, string $scopeType, array $permCodes): SystemRole
    {
        $role = SystemRole::factory()->create([
            'code'       => $code,
            'label'      => ucwords(str_replace('_', ' ', $code)),
            'scope_type' => $scopeType,
        ]);

        $permIds = collect($permCodes)->map(function ($pc) {
            return Permission::firstOrCreate(['code' => $pc], ['label' => $pc, 'module' => explode('.', $pc)[0]])->id;
        })->toArray();

        $role->permissions()->sync($permIds);

        return $role;
    }

    private function createUserWithGlobalRole(SystemRole $role): User
    {
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id'        => $user->id,
            'system_role_id' => $role->id,
            'scope_type'     => 'global',
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
            'user_id'            => $user->id,
            'system_role_id'     => $role->id,
            'scope_type'         => 'campaign',
            'scoped_campaign_id' => $campaign->id,
        ]);
    }

    private function createUserWithSiteRole(SystemRole $role, MissionSite $site): User
    {
        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id'        => $user->id,
            'system_role_id' => $role->id,
            'scope_type'     => 'site',
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
            'campaign_id'     => $site->campaign_id,
            'mission_site_id' => $site->id,
        ]);

        $user = User::factory()->create();
        UserRoleAssignment::create([
            'user_id'        => $user->id,
            'system_role_id' => $role->id,
            'scope_type'     => 'team',
            'scoped_team_id' => $team->id,
        ]);

        return $user;
    }

    private function createDraftReport(Campaign $campaign, MissionSite $site): FieldReport
    {
        return FieldReport::factory()->create([
            'campaign_id'        => $campaign->id,
            'mission_site_id'    => $site->id,
            'report_period_type' => 'daily',
            'period_start'       => now()->toDateString(),
            'period_end'         => now()->toDateString(),
            'status'             => 'draft',
        ]);
    }

    private function createOutcome(Campaign $campaign, MissionSite $site): MissionOutcome
    {
        return MissionOutcome::factory()->create([
            'campaign_id'     => $campaign->id,
            'mission_site_id' => $site->id,
            'outcome_type'    => 'decision',
            'outcome_date'    => now()->toDateString(),
            'visitor_name'    => 'Test Visitor',
            'follow_up_status'=> 'pending',
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
}
