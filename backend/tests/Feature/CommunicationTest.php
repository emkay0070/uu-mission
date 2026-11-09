<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\Communication;
use App\Models\CommunicationRead;
use App\Models\MissionaryProfile;
use App\Models\MissionSite;
use App\Models\OrgMembership;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\PublishesRolePermissions;

class CommunicationTest extends TestCase
{
    use PublishesRolePermissions;
    use RefreshDatabase;

    private OrgUnit $uu;

    private OrgUnit $cuc;

    private OrgUnit $localChurch;

    private OrgUnit $otherChurch;

    private Campaign $campaignA;

    private Campaign $campaignB;

    private MissionSite $siteA1;

    private MissionSite $siteA2;

    private MissionSite $siteB1;

    private Team $teamA1;

    private Team $teamA2;

    private SystemRole $roleAdmin;

    private SystemRole $roleUnionDirector;

    private SystemRole $roleCampaignLeader;

    private SystemRole $roleSiteSecretary;

    private SystemRole $roleTeamLeader;

    private SystemRole $roleMissionary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildFixtures();
    }

    // ════════════════════════════════════════════════════════════════════
    // A. GLOBAL COMMUNICATIONS
    // ════════════════════════════════════════════════════════════════════

    #[Test]
    public function eligible_missionary_with_only_local_church_receives_global_announcement(): void
    {
        $localOnly = $this->createEligibleMissionary('local-only@example.org');
        $this->assignOrgMembership($localOnly->person, $this->localChurch);
        // No role assignment: local-church membership alone must still receive
        // global announcements.

        $global = $this->publishCommunication([
            'scope_type' => 'global',
            'communication_type' => Communication::TYPE_ANNOUNCEMENT,
            'title' => 'Global announcement',
            'body' => 'Welcome to the mission.',
        ]);

        $response = $this->actingAs($localOnly, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($global->id, $ids);

        // Can open it directly.
        $this->actingAs($localOnly, 'sanctum')
            ->getJson("/api/v1/communications/{$global->id}")
            ->assertOk();
    }

    #[Test]
    public function global_announcement_does_not_require_union_leadership_assignment(): void
    {
        $noLeadership = $this->createEligibleMissionary('nolead@example.org');
        $this->assignOrgMembership($noLeadership->person, $this->localChurch);
        // Deliberately no UserRoleAssignment at all.

        $global = $this->publishCommunication([
            'scope_type' => 'global',
            'title' => 'Global update',
            'body' => 'Body',
        ]);

        $this->actingAs($noLeadership, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonFragment(['id' => $global->id]);
    }

    #[Test]
    public function incomplete_account_does_not_receive_global_announcement(): void
    {
        // Active user but no person / missionary profile: not eligible.
        $ineligible = User::factory()->create(['status' => 'active']);

        $global = $this->publishCommunication([
            'scope_type' => 'global',
            'title' => 'Global update',
            'body' => 'Body',
        ]);

        $this->actingAs($ineligible, 'sanctum')
            ->getJson("/api/v1/communications/{$global->id}")
            ->assertStatus(403);

        $this->actingAs($ineligible, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertStatus(403);
    }

    #[Test]
    public function unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/v1/communications')->assertStatus(401);
        $this->postJson('/api/v1/communications', ['title' => 'x'])->assertStatus(401);
    }

    #[Test]
    public function unauthorised_user_cannot_publish_globally(): void
    {
        $missionary = $this->createEligibleMissionary('pub@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);
        // Missionary role holds communications.view/acknowledge only, not publish.

        $this->actingAs($missionary, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'global',
                'title' => 'Unauthorised global',
                'body' => 'Body',
                'communication_type' => 'announcement',
            ])
            ->assertStatus(403);
    }

    // ════════════════════════════════════════════════════════════════════
    // B. ORGANISATIONAL COMMUNICATIONS
    // ════════════════════════════════════════════════════════════════════

    #[Test]
    public function union_scoped_communication_reaches_descendant_local_church_member(): void
    {
        $member = $this->createEligibleMissionary('descendant@example.org');
        $this->assignOrgMembership($member->person, $this->localChurch);

        $conferenceComm = $this->publishCommunication([
            'scope_type' => 'org_unit',
            'scoped_org_unit_id' => $this->cuc->id,
            'title' => 'Conference update',
            'body' => 'Body',
        ]);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonFragment(['id' => $conferenceComm->id]);
    }

    #[Test]
    public function conference_scoped_communication_is_hidden_from_unrelated_org_member(): void
    {
        $unrelated = $this->createEligibleMissionary('unrelated@example.org');
        $this->assignOrgMembership($unrelated->person, $this->otherChurch);

        $this->publishCommunication([
            'scope_type' => 'org_unit',
            'scoped_org_unit_id' => $this->cuc->id,
            'title' => 'Conference update',
            'body' => 'Body',
        ]);

        $this->actingAs($unrelated, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function union_director_can_publish_org_unit_communication(): void
    {
        $director = $this->createUserWithRole($this->roleUnionDirector, 'org_unit', $this->uu->id);

        $response = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'org_unit',
                'scoped_org_unit_id' => $this->cuc->id,
                'title' => 'Union bulletin',
                'body' => 'Body',
                'communication_type' => 'organisational_update',
                'status' => 'published',
            ])
            ->assertStatus(201);

        $this->assertSame('published', $response->json('status'));
    }

    // ════════════════════════════════════════════════════════════════════
    // C. CAMPAIGN / SITE / TEAM COMMUNICATIONS
    // ════════════════════════════════════════════════════════════════════

    #[Test]
    public function campaign_participant_without_leadership_role_receives_campaign_communication(): void
    {
        // Deployed to campaign A but holds no leadership role assignment.
        $participant = $this->createEligibleMissionary('participant@example.org');
        $this->assignCampaignAssignment($participant->person, $this->campaignA, $this->siteA1, null, 'confirmed');

        $campaignComm = $this->publishCommunication([
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $this->campaignA->id,
            'title' => 'Campaign briefing',
            'body' => 'Body',
        ]);

        $this->actingAs($participant, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonFragment(['id' => $campaignComm->id]);
    }

    #[Test]
    public function campaign_communication_is_hidden_from_other_campaign_participant(): void
    {
        $other = $this->createEligibleMissionary('othercamp@example.org');
        $this->assignCampaignAssignment($other->person, $this->campaignB, $this->siteB1, null, 'confirmed');

        $this->publishCommunication([
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $this->campaignA->id,
            'title' => 'Campaign briefing',
            'body' => 'Body',
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function site_communication_reaches_site_participants_including_team_members(): void
    {
        $siteMember = $this->createEligibleMissionary('sitemember@example.org');
        $this->assignCampaignAssignment($siteMember->person, $this->campaignA, $this->siteA1, null, 'confirmed');

        $teamMember = $this->createEligibleMissionary('teamseat@example.org');
        $this->assignTeamMember($this->teamA1, $teamMember->person);

        $siteComm = $this->publishCommunication([
            'scope_type' => 'site',
            'scoped_site_id' => $this->siteA1->id,
            'title' => 'Site notice',
            'body' => 'Body',
        ]);

        $this->actingAs($siteMember, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonFragment(['id' => $siteComm->id]);

        $this->actingAs($teamMember, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonFragment(['id' => $siteComm->id]);
    }

    #[Test]
    public function site_communication_is_hidden_from_sibling_site_participant(): void
    {
        $sibling = $this->createEligibleMissionary('sibling@example.org');
        $this->assignCampaignAssignment($sibling->person, $this->campaignA, $this->siteA2, null, 'confirmed');

        $this->publishCommunication([
            'scope_type' => 'site',
            'scoped_site_id' => $this->siteA1->id,
            'title' => 'Site notice',
            'body' => 'Body',
        ]);

        $this->actingAs($sibling, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function team_communication_reaches_team_members(): void
    {
        $member = $this->createEligibleMissionary('teammember@example.org');
        $this->assignTeamMember($this->teamA1, $member->person);

        $teamComm = $this->publishCommunication([
            'scope_type' => 'team',
            'scoped_team_id' => $this->teamA1->id,
            'title' => 'Team note',
            'body' => 'Body',
        ]);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonFragment(['id' => $teamComm->id]);
    }

    #[Test]
    public function team_communication_is_hidden_from_other_team_member(): void
    {
        $other = $this->createEligibleMissionary('otherteam@example.org');
        $this->assignTeamMember($this->teamA2, $other->person);

        $this->publishCommunication([
            'scope_type' => 'team',
            'scoped_team_id' => $this->teamA1->id,
            'title' => 'Team note',
            'body' => 'Body',
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function expired_revoked_assignment_loses_restricted_access(): void
    {
        $missionary = $this->createEligibleMissionary('expired@example.org');
        $assignment = $this->assignCampaignAssignment(
            $missionary->person, $this->campaignA, $this->siteA1, null, 'confirmed'
        );

        $campaignComm = $this->publishCommunication([
            'scope_type' => 'campaign',
            'scoped_campaign_id' => $this->campaignA->id,
            'title' => 'Campaign briefing',
            'body' => 'Body',
        ]);

        // While assigned, the missionary receives it.
        $this->actingAs($missionary, 'sanctum')
            ->getJson("/api/v1/communications/{$campaignComm->id}")
            ->assertOk();

        // When the assignment is completed (terminal), access stops.
        $assignment->update(['status' => 'completed']);

        $this->actingAs($missionary, 'sanctum')
            ->getJson("/api/v1/communications/{$campaignComm->id}")
            ->assertStatus(403);

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ════════════════════════════════════════════════════════════════════
    // D. READ AND ACKNOWLEDGEMENT
    // ════════════════════════════════════════════════════════════════════

    #[Test]
    public function unread_count_is_correct(): void
    {
        $missionary = $this->createEligibleMissionary('count@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        $this->publishCommunication(['scope_type' => 'global', 'title' => 'One', 'body' => 'B']);
        $this->publishCommunication(['scope_type' => 'global', 'title' => 'Two', 'body' => 'B']);

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/communications/unread-count')
            ->assertOk()
            ->assertJson(['unread_count' => 2]);

        // Read the first one.
        $all = $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->json('data');

        $firstId = $all[0]['id'];
        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/communications/{$firstId}/read")
            ->assertOk();

        $this->assertDatabaseHas('communication_reads', [
            'communication_id' => $firstId,
            'user_id' => $missionary->id,
        ]);
        $this->assertNotNull(CommunicationRead::where('communication_id', $firstId)
            ->where('user_id', $missionary->id)
            ->value('read_at'));

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/communications/unread-count')
            ->assertOk()
            ->assertJson(['unread_count' => 1]);
    }

    #[Test]
    public function reading_creates_one_personal_tracking_record_and_is_idempotent(): void
    {
        $missionary = $this->createEligibleMissionary('idem@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        $comm = $this->publishCommunication([
            'scope_type' => 'global', 'title' => 'Read me', 'body' => 'B',
        ]);

        $this->assertDatabaseCount('communication_reads', 0);

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/communications/{$comm->id}/read")
            ->assertOk();

        $this->assertDatabaseCount('communication_reads', 1);

        // Repeat read: still one record, read_at not reset.
        $firstReadAt = CommunicationRead::first()->read_at?->toIso8601String();
        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/communications/{$comm->id}/read")
            ->assertOk();

        $this->assertDatabaseCount('communication_reads', 1);
        $this->assertSame($firstReadAt, CommunicationRead::first()->fresh()->read_at?->toIso8601String());
    }

    #[Test]
    public function read_state_is_per_user(): void
    {
        $a = $this->createEligibleMissionary('usera@example.org');
        $this->assignOrgMembership($a->person, $this->localChurch);
        $b = $this->createEligibleMissionary('userb@example.org');
        $this->assignOrgMembership($b->person, $this->localChurch);

        $comm = $this->publishCommunication([
            'scope_type' => 'global', 'title' => 'Shared', 'body' => 'B',
        ]);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/communications/{$comm->id}/read")
            ->assertOk();

        // A has read it; B has not.
        $this->actingAs($a, 'sanctum')
            ->getJson('/api/v1/communications/unread-count')
            ->assertOk()
            ->assertJson(['unread_count' => 0]);

        $this->actingAs($b, 'sanctum')
            ->getJson('/api/v1/communications/unread-count')
            ->assertOk()
            ->assertJson(['unread_count' => 1]);
    }

    #[Test]
    public function acknowledgement_works_only_for_instructions(): void
    {
        $missionary = $this->createEligibleMissionary('ack@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        $instruction = $this->publishCommunication([
            'scope_type' => 'global',
            'communication_type' => Communication::TYPE_INSTRUCTION,
            'requires_acknowledgement' => true,
            'title' => 'Instruction',
            'body' => 'Please acknowledge.',
        ]);

        $announcement = $this->publishCommunication([
            'scope_type' => 'global',
            'communication_type' => Communication::TYPE_ANNOUNCEMENT,
            'requires_acknowledgement' => false,
            'title' => 'Announcement',
            'body' => 'Body',
        ]);

        // Acknowledge the instruction.
        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/communications/{$instruction->id}/acknowledge")
            ->assertOk();

        $this->assertDatabaseHas('communication_reads', [
            'communication_id' => $instruction->id,
            'user_id' => $missionary->id,
        ]);
        $this->assertNotNull(CommunicationRead::where('communication_id', $instruction->id)
            ->where('user_id', $missionary->id)
            ->value('acknowledged_at'));

        // Cannot acknowledge an announcement that does not require it.
        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/communications/{$announcement->id}/acknowledge")
            ->assertStatus(422);
    }

    #[Test]
    public function acknowledgement_is_idempotent(): void
    {
        $missionary = $this->createEligibleMissionary('idemack@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        $instruction = $this->publishCommunication([
            'scope_type' => 'global',
            'communication_type' => Communication::TYPE_INSTRUCTION,
            'requires_acknowledgement' => true,
            'title' => 'Instruction',
            'body' => 'Please acknowledge.',
        ]);

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/communications/{$instruction->id}/acknowledge")
            ->assertOk();

        $firstAckAt = CommunicationRead::first()->acknowledged_at?->toIso8601String();

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/communications/{$instruction->id}/acknowledge")
            ->assertOk();

        $this->assertDatabaseCount('communication_reads', 1);
        $this->assertSame($firstAckAt, CommunicationRead::first()->fresh()->acknowledged_at?->toIso8601String());
    }

    // ════════════════════════════════════════════════════════════════════
    // E. PUBLISHING AND LIFECYCLE
    // ════════════════════════════════════════════════════════════════════

    #[Test]
    public function campaign_leader_can_publish_campaign_communication(): void
    {
        $leader = $this->createUserWithRole($this->roleCampaignLeader, 'campaign', $this->campaignA->id);

        $this->actingAs($leader, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'campaign',
                'scoped_campaign_id' => $this->campaignA->id,
                'title' => 'Campaign update',
                'body' => 'Body',
                'communication_type' => 'announcement',
            ])
            ->assertStatus(201);
    }

    #[Test]
    public function team_leader_can_publish_team_communication(): void
    {
        $leader = $this->createUserWithRole($this->roleTeamLeader, 'team', $this->teamA1->id);

        $this->actingAs($leader, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'team',
                'scoped_team_id' => $this->teamA1->id,
                'title' => 'Team update',
                'body' => 'Body',
                'communication_type' => 'organisational_update',
            ])
            ->assertStatus(201);
    }

    #[Test]
    public function publisher_cannot_publish_outside_their_scope(): void
    {
        // Team leader for team A1 attempts to publish to the whole union.
        $leader = $this->createUserWithRole($this->roleTeamLeader, 'team', $this->teamA1->id);

        $this->actingAs($leader, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'org_unit',
                'scoped_org_unit_id' => $this->uu->id,
                'title' => 'Out of scope',
                'body' => 'Body',
                'communication_type' => 'announcement',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function invalid_scope_combinations_are_rejected(): void
    {
        $director = $this->createUserWithRole($this->roleUnionDirector, 'org_unit', $this->uu->id);

        // Org-unit scope without an org unit.
        $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'org_unit',
                'title' => 'Bad',
                'body' => 'Body',
                'communication_type' => 'announcement',
            ])
            ->assertStatus(422);

        // Global scope with an org unit reference.
        $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'global',
                'scoped_org_unit_id' => $this->uu->id,
                'title' => 'Bad',
                'body' => 'Body',
                'communication_type' => 'announcement',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function draft_is_invisible_to_recipients(): void
    {
        $director = $this->createUserWithRole($this->roleUnionDirector, 'org_unit', $this->uu->id);

        $draft = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'org_unit',
                'scoped_org_unit_id' => $this->uu->id,
                'title' => 'Draft',
                'body' => 'Body',
                'communication_type' => 'announcement',
                'status' => 'draft',
            ])
            ->assertStatus(201)
            ->json('id');

        $missionary = $this->createEligibleMissionary('draftviewer@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonMissing(['id' => $draft]);

        // Direct show of a draft is also denied (not yet published).
        $this->actingAs($missionary, 'sanctum')
            ->getJson("/api/v1/communications/{$draft}")
            ->assertStatus(403);
    }

    #[Test]
    public function revoked_communication_disappears_from_recipient_lists(): void
    {
        $director = $this->createUserWithRole($this->roleUnionDirector, 'org_unit', $this->uu->id);

        $published = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'org_unit',
                'scoped_org_unit_id' => $this->uu->id,
                'title' => 'To be revoked',
                'body' => 'Body',
                'communication_type' => 'announcement',
                'status' => 'published',
            ])
            ->assertStatus(201)
            ->json('id');

        $missionary = $this->createEligibleMissionary('revokedviewer@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonFragment(['id' => $published]);

        // Revoke (soft delete).
        $this->actingAs($director, 'sanctum')
            ->deleteJson("/api/v1/communications/{$published}")
            ->assertOk();

        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonMissing(['id' => $published]);

        // Historical tracking record and soft-deleted communication are preserved.
        $this->assertNotNull(
            Communication::withTrashed()->where('id', $published)->value('deleted_at')
        );
        $this->assertDatabaseCount('communication_reads', 0);
    }

    #[Test]
    public function non_author_user_cannot_revoke_communication(): void
    {
        $director = $this->createUserWithRole($this->roleUnionDirector, 'org_unit', $this->uu->id);
        $missionary = $this->createEligibleMissionary('nonauthor@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        $comm = $this->publishCommunication([
            'scope_type' => 'global',
            'title' => 'Others comm',
            'body' => 'Body',
        ]);

        $this->actingAs($missionary, 'sanctum')
            ->deleteJson("/api/v1/communications/{$comm->id}")
            ->assertStatus(403);
    }

    // ════════════════════════════════════════════════════════════════════
    // F. PUBLISHED-CONTENT INTEGRITY AND ROUTE PRECEDENCE
    // ════════════════════════════════════════════════════════════════════

    #[Test]
    public function draft_can_edit_protected_fields(): void
    {
        $director = $this->createUserWithRole($this->roleUnionDirector, 'org_unit', $this->uu->id);

        $draft = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'org_unit',
                'scoped_org_unit_id' => $this->uu->id,
                'title' => 'Draft title',
                'body' => 'Draft body',
                'communication_type' => 'announcement',
                'status' => 'draft',
            ])
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($director, 'sanctum')
            ->patchJson("/api/v1/communications/{$draft}", [
                'title' => 'Updated title',
                'body' => 'Updated body',
            ])
            ->assertStatus(200);
    }

    #[Test]
    public function published_communication_without_reads_can_edit_protected_fields(): void
    {
        $director = $this->createUserWithRole($this->roleUnionDirector, 'org_unit', $this->uu->id);

        $published = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'org_unit',
                'scoped_org_unit_id' => $this->uu->id,
                'title' => 'Original title',
                'body' => 'Original body',
                'communication_type' => 'announcement',
                'status' => 'published',
            ])
            ->assertStatus(201)
            ->json('id');

        // No read record exists yet, so content may still be corrected.
        $this->actingAs($director, 'sanctum')
            ->patchJson("/api/v1/communications/{$published}", [
                'title' => 'Updated title',
                'body' => 'Updated body',
            ])
            ->assertStatus(200);
    }

    #[Test]
    public function published_communication_with_reads_cannot_edit_protected_fields(): void
    {
        $director = $this->createUserWithRole($this->roleUnionDirector, 'org_unit', $this->uu->id);

        $published = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'org_unit',
                'scoped_org_unit_id' => $this->uu->id,
                'title' => 'Original title',
                'body' => 'Original body',
                'communication_type' => 'instruction',
                'requires_acknowledgement' => true,
                'status' => 'published',
            ])
            ->assertStatus(201)
            ->json('id');

        $missionary = $this->createEligibleMissionary('reader@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        // A missionary reads the communication, creating a read record.
        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/communications/{$published}/read")
            ->assertOk();

        // The author must not be allowed to change core content after a read.
        $this->actingAs($director, 'sanctum')
            ->patchJson("/api/v1/communications/{$published}", [
                'body' => 'Changed body',
            ])
            ->assertStatus(422);

        // The original content and acknowledgement state are preserved.
        $this->assertDatabaseMissing('communications', [
            'id' => $published,
            'body' => 'Changed body',
        ]);
        $this->assertDatabaseHas('communications', [
            'id' => $published,
            'body' => 'Original body',
        ]);
    }

    #[Test]
    public function published_communication_with_reads_can_update_expiry(): void
    {
        $director = $this->createUserWithRole($this->roleUnionDirector, 'org_unit', $this->uu->id);

        $published = $this->actingAs($director, 'sanctum')
            ->postJson('/api/v1/communications', [
                'scope_type' => 'org_unit',
                'scoped_org_unit_id' => $this->uu->id,
                'title' => 'Expiring notice',
                'body' => 'Body',
                'communication_type' => 'announcement',
                'status' => 'published',
            ])
            ->assertStatus(201)
            ->json('id');

        $missionary = $this->createEligibleMissionary('reader2@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        $this->actingAs($missionary, 'sanctum')
            ->postJson("/api/v1/communications/{$published}/read")
            ->assertOk();

        $future = now()->addDays(30)->toDateString();

        // Expiry changes are permitted even after a read.
        $this->actingAs($director, 'sanctum')
            ->patchJson("/api/v1/communications/{$published}", [
                'expires_at' => $future,
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['expires_at' => $future.'T00:00:00+00:00']);
    }

    #[Test]
    public function unread_count_route_is_not_captured_by_communication_id(): void
    {
        $missionary = $this->createEligibleMissionary('countroute@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        // If /unread-count were captured by /{communication}, Laravel would
        // attempt route-model binding on a non-existent ID and return 404/422.
        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/communications/unread-count')
            ->assertOk()
            ->assertJsonStructure(['unread_count']);
    }

    #[Test]
    public function requiring_acknowledgement_route_is_not_captured_by_communication_id(): void
    {
        $missionary = $this->createEligibleMissionary('ackroute@example.org');
        $this->assignOrgMembership($missionary->person, $this->localChurch);

        // If /requiring-acknowledgement were captured by /{communication},
        // Laravel would attempt route-model binding on a non-existent ID.
        $this->actingAs($missionary, 'sanctum')
            ->getJson('/api/v1/communications/requiring-acknowledgement')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ════════════════════════════════════════════════════════════════════
    // FIXTURE BUILDERS
    // ════════════════════════════════════════════════════════════════════

    private function buildFixtures(): void
    {
        // ── Organisational hierarchy ────────────────────────────────
        $typeUnion = OrgUnitType::factory()->create(['code' => 'union', 'label' => 'Union']);
        $typeConf = OrgUnitType::factory()->create(['code' => 'conference', 'label' => 'Conference']);
        $typeLocal = OrgUnitType::factory()->create(['code' => 'local_church', 'label' => 'Local Church']);

        $this->uu = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeUnion->id, 'name' => 'Uganda Union Mission', 'short_code' => 'UUM',
        ]);
        $this->cuc = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeConf->id, 'parent_id' => $this->uu->id,
            'name' => 'Central Uganda Conference', 'short_code' => 'CUC',
        ]);
        $this->localChurch = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeLocal->id, 'parent_id' => $this->cuc->id,
            'name' => 'Kira Local Church', 'short_code' => 'KIR',
        ]);
        $otherConf = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeConf->id, 'parent_id' => $this->uu->id,
            'name' => 'Other Conference', 'short_code' => 'OTC',
        ]);
        $this->otherChurch = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeLocal->id, 'parent_id' => $otherConf->id,
            'name' => 'Other Local Church', 'short_code' => 'OTK',
        ]);

        // ── Programme and campaigns ─────────────────────────────────
        $pt = ProgrammeType::factory()->create(['code' => 'global_mission', 'label' => 'Global Mission']);
        $programme = Programme::factory()->create(['programme_type_id' => $pt->id, 'name' => 'Global Mission Programme']);

        $this->campaignA = Campaign::factory()->create([
            'programme_id' => $programme->id, 'lead_org_unit_id' => $this->cuc->id,
            'name' => 'Campaign Alpha', 'status' => 'active',
        ]);
        $this->campaignB = Campaign::factory()->create([
            'programme_id' => $programme->id, 'lead_org_unit_id' => $this->cuc->id,
            'name' => 'Campaign Beta', 'status' => 'active',
        ]);

        // ── Sites ───────────────────────────────────────────────────
        $this->siteA1 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignA->id, 'name' => 'Site A1', 'status' => 'active',
        ]);
        $this->siteA2 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignA->id, 'name' => 'Site A2', 'status' => 'active',
        ]);
        $this->siteB1 = MissionSite::factory()->create([
            'campaign_id' => $this->campaignB->id, 'name' => 'Site B1', 'status' => 'active',
        ]);

        // ── Teams ───────────────────────────────────────────────────
        $this->teamA1 = Team::factory()->create([
            'campaign_id' => $this->campaignA->id, 'mission_site_id' => $this->siteA1->id,
            'name' => 'Team A1', 'team_type_id' => null,
        ]);
        $this->teamA2 = Team::factory()->create([
            'campaign_id' => $this->campaignA->id, 'mission_site_id' => $this->siteA1->id,
            'name' => 'Team A2', 'team_type_id' => null,
        ]);

        // ── Roles with communication permissions ────────────────────
        $this->roleAdmin = $this->createRole('platform_admin', 'global', [
            'communications.view', 'communications.publish', 'communications.acknowledge', 'communications.revoke',
        ]);
        $this->roleUnionDirector = $this->createRole('union_director', 'org_unit', [
            'communications.view', 'communications.publish', 'communications.acknowledge', 'communications.revoke',
        ]);
        $this->roleCampaignLeader = $this->createRole('campaign_leader', 'campaign', [
            'communications.view', 'communications.publish', 'communications.acknowledge',
        ]);
        $this->roleSiteSecretary = $this->createRole('site_secretary', 'site', [
            'communications.view', 'communications.publish', 'communications.acknowledge',
        ]);
        $this->roleTeamLeader = $this->createRole('team_leader', 'team', [
            'communications.view', 'communications.publish', 'communications.acknowledge',
        ]);
        $this->roleMissionary = $this->createRole('missionary', 'site', [
            'communications.view', 'communications.acknowledge',
        ]);
    }

    private function publishCommunication(array $data): Communication
    {
        // A dedicated author so the author-bypass in the view policy never
        // accidentally grants the test subject access to their own fixture.
        $author = User::factory()->create();

        return Communication::create(array_merge([
            'author_user_id' => $author->id,
            'communication_type' => Communication::TYPE_ANNOUNCEMENT,
            'status' => 'published',
            'published_at' => now(),
        ], $data));
    }

    private function createRole(string $code, string $scopeType, array $permCodes): SystemRole
    {
        $role = SystemRole::factory()->create([
            'code' => $code,
            'label' => ucwords(str_replace('_', ' ', $code)),
            'scope_type' => $scopeType,
        ]);

        $permIds = collect($permCodes)->map(function ($pc) {
            return Permission::firstOrCreate(['code' => $pc], ['label' => $pc, 'module' => 'communications'])->id;
        })->toArray();

        $this->publishRolePermissions($role, $permIds);

        return $role;
    }

    private function createUserWithRole(SystemRole $role, string $scopeType, int $scopeId): User
    {
        $user = $this->createEligibleMissionary(strtolower($role->code).'@example.org');

        $scopeColumn = match ($scopeType) {
            'org_unit' => 'scoped_org_unit_id',
            'campaign' => 'scoped_campaign_id',
            'site' => 'scoped_site_id',
            'team' => 'scoped_team_id',
            default => null,
        };

        $attributes = [
            'user_id' => $user->id,
            'system_role_id' => $role->id,
            'scope_type' => $scopeType,
        ];
        if ($scopeColumn !== null) {
            $attributes[$scopeColumn] = $scopeId;
        }

        UserRoleAssignment::create($attributes);

        return $user;
    }

    private function createEligibleMissionary(string $email = 'missionary@example.org'): User
    {
        $user = User::factory()->create(['email' => $email, 'status' => 'active']);
        $person = Person::factory()->create(['user_id' => $user->id]);
        MissionaryProfile::create(['person_id' => $person->id]);

        return $user;
    }

    private function assignOrgMembership(Person $person, OrgUnit $orgUnit): void
    {
        OrgMembership::create([
            'person_id' => $person->id,
            'org_unit_id' => $orgUnit->id,
            'membership_type' => 'member',
            'joined_on' => now()->toDateString(),
            'approval_status' => 'approved',
        ]);
    }

    private function assignCampaignAssignment(
        Person $person,
        Campaign $campaign,
        ?MissionSite $site = null,
        ?Team $team = null,
        string $status = 'confirmed'
    ): CampaignAssignment {
        return CampaignAssignment::create([
            'campaign_id' => $campaign->id,
            'person_id' => $person->id,
            'mission_site_id' => $site?->id,
            'assigned_team_id' => $team?->id,
            'status' => $status,
        ]);
    }

    private function assignTeamMember(Team $team, Person $person): TeamMember
    {
        return TeamMember::create([
            'team_id' => $team->id,
            'person_id' => $person->id,
            'role_in_team' => 'member',
            'joined_on' => now()->toDateString(),
        ]);
    }
}
