<?php

namespace Tests\Feature;

use App\Domain\WorkflowDefinitions;
use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\ActivityType;
use App\Models\AttendanceEntry;
use App\Models\AttendanceRegister;
use App\Models\Campaign;
use App\Models\FieldReport;
use App\Models\FieldReportVersion;
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
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FieldworkReportingTest — the reporting chain, end to end (Phase 11).
 *
 * This suite locks in the behaviour that was deliberately designed into the
 * fieldwork & reporting implementation. It exercises the controller, the
 * FieldReportPolicy, ReportPreparationService and the underlying schema
 * together, because the interesting guarantees live in how those pieces agree
 * with one another.
 *
 * WHAT IS BEING PROTECTED
 * -----------------------
 *   A. LIVE PREPARATION
 *      Compiling a report is a PURE READ. It states the fieldwork the report
 *      covers but never writes to the source records and never mutates the
 *      report while it is still being developed.
 *
 *   B. NARROW VISIBILITY
 *      Official report visibility (report.view) is bounded by the caller's
 *      scope. A team member sees only their OWN team's developing report —
 *      never a sibling team's and never the wider site.
 *
 *   C. THE REVIEW CHAIN
 *      Submission routes by level. A team leader's approval advances the
 *      report to the site rung and marks it `reviewed` — it is NOT `approved`.
 *      A delayed review may be forwarded, but forwarding is explicitly not
 *      approval.
 *
 *   D. CONSOLIDATION & RECOVERY
 *      A site report consolidates submitted team reports; a campaign report
 *      consolidates submitted site reports. Lower-level reports are linked, not
 *      rewritten. Recovery classifies what exists (verified / auto-generated /
 *      human / missing / pending) and invents nothing.
 *
 *   E. IMMUTABLE HISTORY
 *      A submitted report is preserved as an immutable version. Later source
 *      changes are REPORTED by reconciliation, never silently folded in.
 *
 *   F. ISOLATION & PRESERVATION
 *      Site/campaign scope is enforced; an expired assignment removes
 *      operational access; attendance and roster history survive a person
 *      being soft-deleted.
 *
 * Fixtures mirror ScopedAuthorizationTest / FieldworkAttendanceAndRosterTest so
 * the suite is self-contained and deterministic.
 */
class FieldworkReportingTest extends TestCase
{
    use RefreshDatabase;

    // ── Shared test fixtures ─────────────────────────────────────────────────

    private OrgUnit $uu;
    private OrgUnit $cuc;
    private Campaign $campaignA;
    private Campaign $campaignB;
    private MissionSite $siteA1;
    private MissionSite $siteA2;
    private MissionSite $siteB1;
    private ActivityType $activityType;
    private Team $teamT1;
    private Team $teamT2;

    private SystemRole $roleCampaignLeader;
    private SystemRole $roleSiteOfficer;
    private SystemRole $roleTeamLeader;
    private SystemRole $roleTeamViewer;
    private SystemRole $roleMissionary;

    /** Campaign-A leadership. */
    private User $leader;
    /** Site officer (secretary) scoped to site A1. */
    private User $secretary;
    /** Team leader of team T1 at site A1. */
    private User $teamLeader;
    /** Narrow team visibility (report.view_team) on team T1 only. */
    private User $teamViewer;
    /** Site-scoped missionary with no reporting rights. */
    private User $missionary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // A. LIVE PREPARATION (behaviour 1)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function live_preparation_compiles_fieldwork_without_mutating_sources(): void
    {
        $report = $this->createSiteReport($this->siteA1);

        // The fieldwork the report is meant to cover.
        $this->createActivity($this->siteA1, 'Compiled Activity');
        $register = $this->createRegister($this->siteA1);
        $this->addEntry($register->id, ['person_id' => $this->createPerson()->id]);
        MissionOutcome::factory()->create([
            'campaign_id'      => $this->campaignA->id,
            'mission_site_id'  => $this->siteA1->id,
            'outcome_type'     => 'decision',
            'outcome_date'     => now()->toDateString(),
            'visitor_name'     => 'Visitor',
            'follow_up_status' => 'pending',
        ]);

        $body = $this->actingAs($this->secretary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/preparation")
            ->assertOk()
            ->json();

        // The compilation reflects the source records...
        $this->assertSame(1, $body['preparation']['activities']['count']);
        $this->assertSame(1, $body['preparation']['attendance']['site_attendance']['registers']);
        $this->assertSame(1, $body['preparation']['attendance']['site_attendance']['identified']);
        $this->assertSame(1, $body['preparation']['outcomes']['total']);

        // ...but nothing was written: no preserved version, and the report's
        // own preparation metadata is untouched (a pure read).
        $this->assertFalse($body['reconciliation']['has_preserved_version']);
        $this->assertNull($report->fresh()->preparation_meta);

        $this->assertSame(1, Activity::count());
        $this->assertSame(1, AttendanceRegister::count());
        $this->assertSame(1, MissionOutcome::count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // B. NARROW VISIBILITY (behaviours 2 & 15)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function team_visibility_lists_only_own_teams_developing_report(): void
    {
        $ownTeamReport     = $this->createTeamReport($this->siteA1, $this->teamT1);
        $siblingTeamReport = $this->createTeamReport($this->siteA1, $this->teamT2);
        $siteReport        = $this->createSiteReport($this->siteA1);

        $ids = collect(
            $this->actingAs($this->teamViewer, 'sanctum')
                ->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports")
                ->assertOk()
                ->json()
        )->pluck('id')->all();

        $this->assertContains($ownTeamReport->id, $ids);
        $this->assertNotContains($siblingTeamReport->id, $ids, 'A sibling team report must stay invisible.');
        $this->assertNotContains($siteReport->id, $ids, 'A team assignment must not expose the whole site.');

        // Directly readable is the team's own report — and only that one.
        $this->actingAs($this->teamViewer, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$ownTeamReport->id}")
            ->assertOk();

        $this->actingAs($this->teamViewer, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$siblingTeamReport->id}")
            ->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function report_listing_is_narrowed_to_the_callers_site_and_team(): void
    {
        // ── Site narrowing: a site-scoped officer sees only their own site ──
        $ownSiteReport     = $this->createSiteReport($this->siteA1);
        $siblingSiteReport = $this->createSiteReport($this->siteA2);

        $siteIds = collect(
            $this->actingAs($this->secretary, 'sanctum')
                ->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports")
                ->assertOk()
                ->json()
        )->pluck('id')->all();

        $this->assertContains($ownSiteReport->id, $siteIds);
        $this->assertNotContains($siblingSiteReport->id, $siteIds);

        // ── Team narrowing: a team member sees only their own team's report ──
        $ownTeamReport     = $this->createTeamReport($this->siteA1, $this->teamT1);
        $siblingTeamReport = $this->createTeamReport($this->siteA1, $this->teamT2);

        $teamIds = collect(
            $this->actingAs($this->teamViewer, 'sanctum')
                ->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports")
                ->assertOk()
                ->json()
        )->pluck('id')->all();

        $this->assertContains($ownTeamReport->id, $teamIds);
        $this->assertNotContains($siblingTeamReport->id, $teamIds);
        $this->assertNotContains($ownSiteReport->id, $teamIds);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // C. SUBMISSION & THE REVIEW CHAIN (behaviours 3, 4, 5, 11)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function missionary_without_submit_permission_cannot_submit_report(): void
    {
        $report = $this->createSiteReport($this->siteA1);

        $this->actingAs($this->missionary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/submit")
            ->assertStatus(403);

        $this->assertSame('draft', $report->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function team_leader_approval_advances_to_site_review_without_approving(): void
    {
        $report = $this->createTeamReport($this->siteA1, $this->teamT1, [
            'status'       => 'submitted',
            'review_state' => 'pending_team_review',
        ]);

        $this->actingAs($this->teamLeader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/review", [
                'action' => 'approve',
            ])
            ->assertOk()
            // The team leader passes it ON: the document is `reviewed`, and the
            // routing state moves to the site rung. It is emphatically not
            // `approved` — consolidation and campaign review still lie ahead.
            ->assertJsonPath('report.status', 'reviewed')
            ->assertJsonPath('report.review_state', 'pending_site_review');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function forwarding_a_delayed_review_records_the_outstanding_level(): void
    {
        // The team leader's window has already expired.
        $report = $this->createTeamReport($this->siteA1, $this->teamT1, [
            'status'       => 'submitted',
            'review_state' => 'pending_team_review',
            'review_due_at' => now()->subHour(),
        ]);

        $this->actingAs($this->secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/forward")
            ->assertOk()
            // Routing advances...
            ->assertJsonPath('report.review_state', 'pending_site_review')
            // ...but forwarding is NOT approval.
            ->assertJsonPath('report.status', 'submitted')
            ->assertJsonPath('report.has_pending_lower_review', true)
            ->assertJsonPath('report.outstanding_review_level', 'team');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function forwarding_before_the_window_expires_is_refused_for_a_non_campaign_reviewer(): void
    {
        // Window still open — an ordinary (site-scoped) officer may not jump it.
        $report = $this->createTeamReport($this->siteA1, $this->teamT1, [
            'status'       => 'submitted',
            'review_state' => 'pending_team_review',
            'review_due_at' => now()->addHour(),
        ]);

        $this->actingAs($this->secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/forward")
            ->assertStatus(422);

        $this->assertSame('pending_team_review', $report->fresh()->review_state);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function change_request_returns_report_to_draft_for_resubmission(): void
    {
        $report = $this->createTeamReport($this->siteA1, $this->teamT1, [
            'status'       => 'submitted',
            'review_state' => 'pending_team_review',
        ]);

        $this->actingAs($this->teamLeader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/review", [
                'action' => 'return',
            ])
            ->assertOk()
            ->assertJsonPath('report.status', 'draft')
            ->assertJsonPath('report.review_state', 'changes_requested');

        // Resubmission re-enters the chain at the team rung.
        $this->actingAs($this->teamLeader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/submit")
            ->assertOk()
            ->assertJsonPath('report.status', 'submitted')
            ->assertJsonPath('report.review_state', 'pending_team_review');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // D. CONSOLIDATION (behaviours 6 & 7)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function site_consolidation_attaches_submitted_team_reports_and_reports_delayed_ones(): void
    {
        $siteReport     = $this->createSiteReport($this->siteA1);
        $submittedChild = $this->createTeamReport($this->siteA1, $this->teamT1, ['status' => 'submitted']);
        $delayedChild   = $this->createTeamReport($this->siteA1, $this->teamT2, ['status' => 'draft']);

        $body = $this->actingAs($this->secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$siteReport->id}/consolidate")
            ->assertOk()
            ->json();

        $this->assertSame('team', $body['consolidation']['child_level']);
        $this->assertContains($submittedChild->id, $body['consolidation']['attached']);

        // The delayed team report is listed, never invented.
        $delayedIds = collect($body['consolidation']['delayed'])->pluck('id')->all();
        $this->assertContains($delayedChild->id, $delayedIds);

        // The child is LINKED and its chain closed — but not rewritten/approved.
        $this->assertSame($siteReport->id, $submittedChild->fresh()->parent_report_id);
        $this->assertSame('complete', $submittedChild->fresh()->review_state);
        $this->assertNull($delayedChild->fresh()->parent_report_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function campaign_consolidation_attaches_submitted_site_reports(): void
    {
        $campaignReport = $this->createCampaignReport($this->campaignA);
        $childA1        = $this->createSiteReport($this->siteA1, ['status' => 'submitted']);
        $childA2        = $this->createSiteReport($this->siteA2, ['status' => 'submitted']);

        $body = $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$campaignReport->id}/consolidate")
            ->assertOk()
            ->json();

        $this->assertSame('site', $body['consolidation']['child_level']);
        $this->assertContains($childA1->id, $body['consolidation']['attached']);
        $this->assertContains($childA2->id, $body['consolidation']['attached']);

        $this->assertSame($campaignReport->id, $childA1->fresh()->parent_report_id);
        $this->assertSame('complete', $childA1->fresh()->review_state);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // D. RECOVERY (behaviours 8 & 9)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function recovery_classifies_available_information_without_inventing_figures(): void
    {
        $report = $this->createSiteReport($this->siteA1, ['narrative' => null]);
        $this->createActivity($this->siteA1, 'Compiled Activity');
        $this->createRegister($this->siteA1);

        // No outcomes have been recorded at all.
        // A delayed (unsubmitted) team report must be surfaced, never faked.
        $delayed = $this->createTeamReport($this->siteA1, $this->teamT1, ['status' => 'draft']);

        $body = $this->actingAs($this->secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/recover")
            ->assertOk()
            ->json();

        $recovery = $body['recovery'];
        foreach (['verified', 'auto_generated', 'manually_supplemented', 'missing', 'pending_reviews'] as $key) {
            $this->assertArrayHasKey($key, $recovery);
        }

        // Absent data is reported as missing, never as an invented figure.
        $this->assertSame(0, $recovery['auto_generated']['outcomes']);
        $this->assertContains('No outcomes are recorded for this period.', $recovery['missing']);

        $pendingIds = collect($recovery['pending_reviews'])->pluck('id')->all();
        $this->assertContains($delayed->id, $pendingIds);

        // Generating a report is NOT a submission — it stays a draft.
        $this->assertSame('draft', $body['report']['status']);
        $this->assertSame('draft', $report->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function recovery_payload_separates_generated_from_verified(): void
    {
        $report = $this->createSiteReport($this->siteA1);
        $this->createActivity($this->siteA1, 'Verified Activity');
        $verified = $this->createRegister($this->siteA1, ['status' => 'verified']);
        $this->addEntry($verified->id, ['person_id' => $this->createPerson()->id]);

        $recovery = $this->actingAs($this->secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/recover")
            ->assertOk()
            ->json('recovery');

        // Both the generated and the verified view are present and distinct.
        $this->assertArrayHasKey('auto_generated', $recovery);
        $this->assertArrayHasKey('verified', $recovery);

        $this->assertGreaterThanOrEqual(1, $recovery['verified']['verified_registers']);
        $this->assertSame(1, $recovery['auto_generated']['site_attendance_entries']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // E. IMMUTABLE SUBMITTED VERSION (behaviour 10)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function submitted_version_is_preserved_and_not_rewritten_by_later_source_changes(): void
    {
        $report = $this->createSiteReport($this->siteA1);
        $this->createActivity($this->siteA1, 'Original Activity');
        $this->createRegister($this->siteA1);

        $this->actingAs($this->secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/submit")
            ->assertOk();

        $this->assertSame(1, FieldReportVersion::where('field_report_id', $report->id)->count());

        $preserved            = $report->fresh()->versions()->first();
        $preservedActivityIds = $preserved->snapshot['preparation']['sources']['activity_ids'];

        // A late source record appears after the report was submitted.
        $late = $this->createActivity($this->siteA1, 'Late Activity');

        $body = $this->actingAs($this->secretary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/preparation")
            ->assertOk()
            ->json();

        // Reconciliation reports the change...
        $this->assertTrue($body['reconciliation']['has_preserved_version']);
        $this->assertTrue($body['reconciliation']['changed']);
        $this->assertContains($late->id, $body['reconciliation']['added']['activity_ids']);

        // ...while the preserved version is untouched, and no second version was
        // written by reading it.
        $this->assertNotContains($late->id, $preservedActivityIds);
        $this->assertSame(1, FieldReportVersion::where('field_report_id', $report->id)->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // F. ISOLATION (behaviours 12 & 13)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function report_actions_are_isolated_across_sites_and_campaigns(): void
    {
        // A site-A1 officer may not act on a sibling site's report.
        $siblingSiteReport = $this->createSiteReport($this->siteA2);

        $this->actingAs($this->secretary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$siblingSiteReport->id}")
            ->assertStatus(403);

        $this->actingAs($this->secretary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$siblingSiteReport->id}/submit")
            ->assertStatus(403);

        // A campaign-A leader may not act on a campaign-B report.
        $reportB = $this->createSiteReport($this->siteB1);

        $this->actingAs($this->leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignB->id}/reports/{$reportB->id}")
            ->assertStatus(403);

        $this->assertSame('draft', $siblingSiteReport->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function an_expired_assignment_removes_operational_access(): void
    {
        $report  = $this->createSiteReport($this->siteA1);
        $expired = $this->createUserWithExpiredSiteRole($this->roleSiteOfficer, $this->siteA1);

        $this->actingAs($expired, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}")
            ->assertStatus(403);

        $this->actingAs($expired, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/reports/{$report->id}/submit")
            ->assertStatus(403);

        $this->assertSame('draft', $report->fresh()->status);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // F. HISTORICAL PRESERVATION (behaviour 14)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function attendance_and_roster_history_survive_a_soft_deleted_person(): void
    {
        $activity = $this->createActivity($this->siteA1, 'History Activity');
        $register = $this->createRegister($this->siteA1, [
            'activity_id'   => $activity->id,
            'register_name' => 'History Register',
        ]);
        $person = $this->createPerson();

        $this->addEntry($register->id, ['person_id' => $person->id]);
        $this->addEntry($register->id, ['is_anonymous' => true]);

        ActivityParticipant::create([
            'activity_id' => $activity->id,
            'person_id'   => $person->id,
            'joined_date' => now()->toDateString(),
            'status'      => 'active',
        ]);

        // The person becomes transient (soft delete only).
        $person->delete();

        $this->assertSoftDeleted('people', ['id' => $person->id]);

        // Attendance and roster history — including the anonymous head count —
        // are preserved rather than erased.
        $this->assertDatabaseHas('attendance_entries', ['person_id' => $person->id]);
        $this->assertDatabaseHas('activity_participants', ['person_id' => $person->id]);
        $this->assertSame(2, AttendanceEntry::where('attendance_register_id', $register->id)->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // G. ACTIVITY LIFECYCLE (behaviour 16)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function activity_lifecycle_has_exactly_four_statuses(): void
    {
        $this->assertSame(
            ['scheduled', 'in_progress', 'completed', 'cancelled'],
            WorkflowDefinitions::ACTIVITY_STATUSES
        );
        $this->assertNotContains('reported', WorkflowDefinitions::ACTIVITY_STATUSES);

        $activity = $this->createActivity($this->siteA1, 'Status Guard');

        // An unknown (e.g. 'reported') status is refused outright.
        $this->actingAs($this->secretary, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}", [
                'status' => 'reported',
            ])
            ->assertStatus(422);

        // A member of the four-status set is accepted.
        $this->actingAs($this->secretary, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}", [
                'status' => 'completed',
            ])
            ->assertOk()
            ->assertJsonFragment(['status' => 'completed']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // G. ATTENDANCE FORMS & RECONCILIATION (behaviour 17)
    // ═════════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Test]
    public function standalone_and_activity_registers_coexist_and_reconcile_by_person_id_only(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Crusade');
        $date     = now()->toDateString();

        // A standalone (site) register and an activity-linked register for the
        // SAME site and day are both creatable.
        $siteRegisterId = $this->actingAs($this->secretary, 'sanctum')
            ->postJson('/api/v1/attendance-registers', [
                'campaign_id'     => $this->campaignA->id,
                'mission_site_id' => $this->siteA1->id,
                'register_name'   => 'Site Roll Call',
                'register_date'   => $date,
                'register_type'   => 'roll_call',
            ])
            ->assertStatus(201)
            ->json('id');

        $activityRegisterId = $this->actingAs($this->secretary, 'sanctum')
            ->postJson('/api/v1/attendance-registers', [
                'campaign_id'     => $this->campaignA->id,
                'mission_site_id' => $this->siteA1->id,
                'activity_id'     => $activity->id,
                'register_name'   => 'Crusade Attendance',
                'register_date'   => $date,
                'register_type'   => 'meeting',
            ])
            ->assertStatus(201)
            ->json('id');

        $present    = $this->createPerson(); // present at both
        $activityOnly = $this->createPerson(); // present only at the activity

        // Site register: identified + a name + an anonymous head count.
        $this->addEntry($siteRegisterId, ['person_id' => $present->id]);
        $this->addEntry($siteRegisterId, ['visitor_name' => 'Grace']);
        $this->addEntry($siteRegisterId, ['is_anonymous' => true]);

        // Activity register: both people identified, an identically-named row,
        // and an anonymous head count.
        $this->addEntry($activityRegisterId, ['person_id' => $present->id]);
        $this->addEntry($activityRegisterId, ['person_id' => $activityOnly->id]);
        $this->addEntry($activityRegisterId, ['visitor_name' => 'Grace']);
        $this->addEntry($activityRegisterId, ['is_anonymous' => true]);

        $body = $this->actingAs($this->secretary, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$siteRegisterId}/reconciliation")
            ->assertOk()
            ->json();

        $people = collect($body['people'])->keyBy('person_id');

        // Match is by permanent person_id.
        $this->assertSame('site_and_activity', $people[$present->id]['state']);
        $this->assertSame('activity_only', $people[$activityOnly->id]['state']);

        // An activity-only attendee is surfaced as missing from the site register.
        $missingIds = collect($body['missing_from_site_register'])->pluck('person_id')->all();
        $this->assertContains($activityOnly->id, $missingIds);
        $this->assertNotContains($present->id, $missingIds);

        // Names are never used to match, and anonymous rows are never merged:
        // the two identically-named "Grace" rows and the two anonymous rows stay
        // counted separately on each side.
        $this->assertSame(1, $body['unmatched']['site']['named_unidentified']);
        $this->assertSame(1, $body['unmatched']['activity']['named_unidentified']);
        $this->assertSame(1, $body['unmatched']['site']['anonymous']);
        $this->assertSame(1, $body['unmatched']['activity']['anonymous']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // FIXTURE BUILDERS
    // ═════════════════════════════════════════════════════════════════════════

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
        $programme = Programme::factory()->create([
            'programme_type_id' => $pt->id,
            'owning_org_unit_id' => $this->uu->id,
        ]);

        // Campaigns
        $this->campaignA = $this->createCampaign('Campaign Alpha', $programme);
        $this->campaignB = $this->createCampaign('Campaign Beta', $programme);

        // Sites
        $this->siteA1 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A1']);
        $this->siteA2 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A2']);
        $this->siteB1 = MissionSite::factory()->create(['campaign_id' => $this->campaignB->id, 'name' => 'Site B1']);

        // Reference data validated at store time.
        $this->activityType = ActivityType::create([
            'code' => 'evangelistic_meeting',
            'label' => 'Evangelistic Meeting',
            'category' => 'outreach',
            'requires_attendance_register' => true,
        ]);

        OutcomeType::factory()->create(['code' => 'decision', 'is_active' => true]);

        // Teams that sit at site A1.
        $this->teamT1 = Team::factory()->create([
            'campaign_id'     => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'name'            => 'Team One',
        ]);
        $this->teamT2 = Team::factory()->create([
            'campaign_id'     => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'name'            => 'Team Two',
        ]);

        // Roles
        $this->roleCampaignLeader = $this->createRole('campaign_leader_reporting', 'campaign', [
            'campaigns.edit', 'campaigns.view',
            'sites.create', 'sites.edit', 'sites.view',
            'activities.view', 'activities.create', 'activities.update',
            'assignments.manage', 'assignments.view',
            'report.submit', 'report.review', 'report.view',
            'attendance.submit', 'attendance.verify', 'attendance.view',
            'outcomes.record', 'outcomes.view', 'outcomes.manage_followup',
            'people.create', 'people.edit', 'people.view',
        ]);

        $this->roleSiteOfficer = $this->createRole('site_officer_reporting', 'site', [
            'sites.view',
            'activities.view', 'activities.update',
            'assignments.view',
            'report.submit', 'report.review', 'report.view',
            'attendance.submit', 'attendance.verify', 'attendance.view',
            'outcomes.record', 'outcomes.view',
            'people.create', 'people.view',
        ]);

        $this->roleTeamLeader = $this->createRole('team_leader_reporting', 'team', [
            'activities.view',
            'report.submit', 'report.review', 'report.view_team',
            'attendance.submit', 'attendance.view',
            'people.view',
        ]);

        // NARROW: developing-report visibility for the team's own report only.
        $this->roleTeamViewer = $this->createRole('team_viewer_reporting', 'team', [
            'report.view_team',
        ]);

        $this->roleMissionary = $this->createRole('missionary_reporting', 'site', [
            'activities.view',
            'attendance.submit',
            'outcomes.record',
            'people.view',
        ]);

        // Users
        $this->leader     = $this->createUserWithCampaignRole($this->roleCampaignLeader, $this->campaignA);
        $this->secretary  = $this->createUserWithSiteRole($this->roleSiteOfficer, $this->siteA1);
        $this->teamLeader = $this->createUserWithTeamRole($this->roleTeamLeader, $this->teamT1);
        $this->teamViewer = $this->createUserWithTeamRole($this->roleTeamViewer, $this->teamT1);
        $this->missionary = $this->createUserWithSiteRole($this->roleMissionary, $this->siteA1);
    }

    private function createCampaign(string $name, Programme $programme): Campaign
    {
        return Campaign::factory()->create([
            'programme_id'     => $programme->id,
            'lead_org_unit_id' => $this->cuc->id,
            'name'             => $name,
            'status'           => 'active',
        ]);
    }

    private function createRole(string $code, string $scopeType, array $permCodes): SystemRole
    {
        $role = SystemRole::factory()->create([
            'code'       => $code,
            'label'      => ucwords(str_replace('_', ' ', $code)),
            'scope_type' => $scopeType,
        ]);

        $permIds = collect($permCodes)->map(function (string $pc) {
            return Permission::firstOrCreate(['code' => $pc], ['label' => $pc, 'module' => explode('.', $pc)[0]])->id;
        })->toArray();

        $role->permissions()->sync($permIds);

        return $role;
    }

    private function createUserWithCampaignRole(SystemRole $role, Campaign $campaign): User
    {
        $user = User::factory()->create();

        UserRoleAssignment::create([
            'user_id'            => $user->id,
            'system_role_id'     => $role->id,
            'scope_type'         => 'campaign',
            'scoped_campaign_id' => $campaign->id,
        ]);

        return $user;
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
     * A user holding an assignment whose `valid_until` has already passed — the
     * assignment still exists historically, but confers no operational access.
     */
    private function createUserWithExpiredSiteRole(SystemRole $role, MissionSite $site): User
    {
        $user = User::factory()->create();

        UserRoleAssignment::create([
            'user_id'        => $user->id,
            'system_role_id' => $role->id,
            'scope_type'     => 'site',
            'scoped_site_id' => $site->id,
            'valid_from'     => now()->subDays(10)->toDateString(),
            'valid_until'    => now()->subDay()->toDateString(),
        ]);

        return $user;
    }

    private function createUserWithTeamRole(SystemRole $role, Team $team): User
    {
        $user = User::factory()->create();

        UserRoleAssignment::create([
            'user_id'        => $user->id,
            'system_role_id' => $role->id,
            'scope_type'     => 'team',
            'scoped_team_id' => $team->id,
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSiteReport(MissionSite $site, array $overrides = []): FieldReport
    {
        return FieldReport::factory()->create([
            'campaign_id'        => $site->campaign_id,
            'mission_site_id'    => $site->id,
            'report_level'       => 'site',
            'report_period_type' => 'daily',
            'period_start'       => now()->toDateString(),
            'period_end'         => now()->toDateString(),
            'status'             => 'draft',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTeamReport(MissionSite $site, Team $team, array $overrides = []): FieldReport
    {
        return FieldReport::factory()->create([
            'campaign_id'        => $site->campaign_id,
            'mission_site_id'    => $site->id,
            'report_level'       => 'team',
            'team_id'            => $team->id,
            'report_period_type' => 'daily',
            'period_start'       => now()->toDateString(),
            'period_end'         => now()->toDateString(),
            'status'             => 'draft',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCampaignReport(Campaign $campaign, array $overrides = []): FieldReport
    {
        return FieldReport::factory()->create([
            'campaign_id'        => $campaign->id,
            'mission_site_id'    => null,
            'report_level'       => 'campaign',
            'report_period_type' => 'daily',
            'period_start'       => now()->toDateString(),
            'period_end'         => now()->toDateString(),
            'status'             => 'draft',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createActivity(MissionSite $site, string $title, array $overrides = []): Activity
    {
        return Activity::create([
            'campaign_id'      => $site->campaign_id,
            'mission_site_id'  => $site->id,
            'activity_type_id' => $this->activityType->id,
            'title'            => $title,
            'status'           => 'scheduled',
            'scheduled_at'     => now(),
            'is_planned'       => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRegister(MissionSite $site, array $overrides = []): AttendanceRegister
    {
        return AttendanceRegister::create([
            'campaign_id'     => $site->campaign_id,
            'mission_site_id' => $site->id,
            'register_name'   => 'Fieldwork Register',
            'register_date'   => now()->toDateString(),
            'register_type'   => 'roll_call',
            'status'          => 'open',
            ...$overrides,
        ]);
    }

    private function createPerson(): Person
    {
        return Person::factory()->create(['registered_by_user_id' => $this->secretary->id]);
    }

    /**
     * Write one attendance row directly (identity form supplied in $attributes).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function addEntry(int $registerId, array $attributes): AttendanceEntry
    {
        return AttendanceEntry::create([
            'attendance_register_id' => $registerId,
            'entry_uuid'             => (string) Str::uuid(),
            'is_walk_in'             => false,
            'is_anonymous'           => false,
            ...$attributes,
        ]);
    }
}
