<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityObjective;
use App\Models\ActivityParticipant;
use App\Models\ActivityType;
use App\Models\AttendanceEntry;
use App\Models\AttendanceRegister;
use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\OutreachContact;
use App\Models\OutreachEncounter;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\PublishesRolePermissions;

/**
 * Fieldwork — the People/Fieldwork separation, end to end.
 *
 * This suite locks in the behaviour that was deliberately designed in:
 *
 *   A. ACTIVITY AUTHORISATION
 *      activities.view/create/update/delete are enforced against the activity's
 *      real (campaign, site) position. A site coordinator cannot reach a sibling
 *      site or another campaign, and a read-only role cannot author.
 *
 *   B. REGISTER UNIQUENESS (occurrence accuracy)
 *      One register per activity/date/name, one per site/date/type/name, and
 *      two different activities on the same day are each allowed their own.
 *
 *   C. ATTENDANCE IDENTITY FORMS
 *      Exactly one of: identified / named-but-unidentified / anonymous. A named
 *      attendance never invents a Person, an anonymous attendance is a positive
 *      statement, and an incomplete row is rejected.
 *
 *   D. PLANNED ROSTER vs ACTUAL ATTENDANCE
 *      The participants view merges both and reports is_on_roster and is_present
 *      independently; walk-in status is a stored snapshot.
 *
 *   E. LATER LINKING
 *      An attendee recorded by name (or anonymously) can afterwards be linked to
 *      a Person without losing what was recorded on the day.
 *
 *   F. HISTORICAL PRESERVATION
 *      People are soft-deletable only; roster and attendance history survive, and
 *      a hard delete of a person is refused by the database.
 *
 *   G. OUTREACH REGRESSION
 *      Attendance never creates an OutreachContact or OutreachEncounter, and
 *      registering a person creates no roster entry.
 *
 *   H. PLANNED ROSTER LIFECYCLE
 *      The roster can be planned, listed and corrected — but only within the
 *      caller's scope, and only while the activity is still open.
 *
 *   I. REGISTER AUTHORISATION
 *      Creating and reading attendance registers is bounded by the caller's
 *      campaign/site/team scope, exactly as activity authorisation is.
 *
 *   J. MULTI-DAY OCCURRENCES
 *      A multi-day activity gets one register for each day it runs.
 *
 *   K. DATABASE-LEVEL UNIQUENESS
 *      The partial unique indexes — not merely the controller's friendly 409 —
 *      make duplicate registers impossible, for activity-linked and standalone
 *      occurrences alike.
 *
 *   L. MERGE INTEGRITY
 *      A person who is both on the roster and present is listed exactly once.
 */
class FieldworkAttendanceAndRosterTest extends TestCase
{
    use PublishesRolePermissions;
    use RefreshDatabase;

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private Campaign $campaignA;

    private Campaign $campaignB;

    private MissionSite $siteA1;

    private MissionSite $siteA2;

    private MissionSite $siteB1;

    private ActivityType $activityType;

    private User $coordinator;   // site-scoped coordinator at siteA1

    private User $missionary;    // site-scoped, activities.view only

    private User $observer;      // site-scoped, no activities permission at all

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function buildFixtures(): void
    {
        $typeUnion = OrgUnitType::factory()->create(['code' => 'union', 'label' => 'Union']);
        $typeConf = OrgUnitType::factory()->create(['code' => 'conference', 'label' => 'Conference']);

        $uu = OrgUnit::factory()->create(['org_unit_type_id' => $typeUnion->id, 'name' => 'Uganda Union']);
        $cuc = OrgUnit::factory()->create([
            'org_unit_type_id' => $typeConf->id,
            'parent_id' => $uu->id,
            'name' => 'Central Uganda Conference',
        ]);

        $pt = ProgrammeType::factory()->create(['code' => 'global_mission', 'label' => 'Global Mission']);
        $programme = Programme::factory()->create(['programme_type_id' => $pt->id, 'owning_org_unit_id' => $uu->id]);

        $this->campaignA = Campaign::factory()->create([
            'programme_id' => $programme->id, 'lead_org_unit_id' => $cuc->id,
            'name' => 'Campaign Alpha', 'status' => 'active',
        ]);
        $this->campaignB = Campaign::factory()->create([
            'programme_id' => $programme->id, 'lead_org_unit_id' => $cuc->id,
            'name' => 'Campaign Beta', 'status' => 'active',
        ]);

        $this->siteA1 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A1']);
        $this->siteA2 = MissionSite::factory()->create(['campaign_id' => $this->campaignA->id, 'name' => 'Site A2']);
        $this->siteB1 = MissionSite::factory()->create(['campaign_id' => $this->campaignB->id, 'name' => 'Site B1']);

        $this->activityType = ActivityType::create([
            'code' => 'evangelistic_meeting', 'label' => 'Evangelistic Meeting',
            'category' => 'outreach', 'requires_attendance_register' => true,
        ]);

        $coordinatorRole = $this->createRole('site_coordinator', 'site', [
            'sites.view',
            'activities.view', 'activities.create', 'activities.update', 'activities.delete',
            'attendance.submit', 'attendance.verify', 'attendance.view',
            'people.create', 'people.view',
            'outreach-contacts.create', 'outreach-contacts.view',
        ]);
        $missionaryRole = $this->createRole('missionary', 'site', [
            'activities.view', 'attendance.submit',
            'outreach-contacts.create', 'outreach-contacts.view',
        ]);
        $observerRole = $this->createRole('site_observer', 'site', ['sites.view']);

        $this->coordinator = $this->createUserWithSiteRole($coordinatorRole, $this->siteA1);
        $this->missionary = $this->createUserWithSiteRole($missionaryRole, $this->siteA1);
        $this->observer = $this->createUserWithSiteRole($observerRole, $this->siteA1);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // A. ACTIVITY AUTHORISATION
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function site_coordinator_can_list_activities_in_own_campaign(): void
    {
        $this->createActivity($this->siteA1, 'Own Site Activity');

        $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/activities")
            ->assertOk()
            ->assertJsonFragment(['title' => 'Own Site Activity']);
    }

    #[Test]
    public function activity_list_is_narrowed_to_authorised_sites(): void
    {
        // The coordinator only holds scope at siteA1, so a sibling site's
        // activity must not appear even though both are in the same campaign.
        $this->createActivity($this->siteA1, 'Own Site Activity');
        $this->createActivity($this->siteA2, 'Sibling Site Activity');

        $response = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/activities")
            ->assertOk();

        $titles = collect($response->json())->pluck('title')->all();

        $this->assertContains('Own Site Activity', $titles);
        $this->assertNotContains('Sibling Site Activity', $titles);
    }

    #[Test]
    public function role_without_activities_view_cannot_list_activities(): void
    {
        $this->actingAs($this->observer, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/activities")
            ->assertStatus(403);
    }

    #[Test]
    public function site_coordinator_can_create_activity_at_own_site(): void
    {
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities", [
                'mission_site_id' => $this->siteA1->id,
                'activity_type_id' => $this->activityType->id,
                'title' => 'Evening Crusade',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertStatus(201)
            ->assertJsonFragment(['title' => 'Evening Crusade']);
    }

    #[Test]
    public function site_coordinator_cannot_create_activity_at_sibling_site(): void
    {
        // Same campaign, different site — the campaign check alone would allow
        // this, so the site is what must deny it.
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities", [
                'mission_site_id' => $this->siteA2->id,
                'activity_type_id' => $this->activityType->id,
                'title' => 'Not Mine',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function site_coordinator_cannot_create_activity_in_another_campaign(): void
    {
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignB->id}/activities", [
                'mission_site_id' => $this->siteB1->id,
                'activity_type_id' => $this->activityType->id,
                'title' => 'Out Of Scope',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function read_only_role_cannot_create_activity(): void
    {
        // A missionary holds activities.view but not activities.create, so the
        // ability to fill in a register never implies the ability to author.
        $this->actingAs($this->missionary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities", [
                'mission_site_id' => $this->siteA1->id,
                'activity_type_id' => $this->activityType->id,
                'title' => 'Should Be Denied',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function site_coordinator_cannot_update_activity_at_sibling_site(): void
    {
        $siblingActivity = $this->createActivity($this->siteA2, 'Sibling Activity');

        $this->actingAs($this->coordinator, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$siblingActivity->id}", [
                'title' => 'Tampered',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function site_coordinator_can_update_own_activity(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Original Title');

        $this->actingAs($this->coordinator, 'sanctum')
            ->patchJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}", [
                'title' => 'Updated Title',
            ])
            ->assertOk()
            ->assertJsonFragment(['title' => 'Updated Title']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // B. REGISTER UNIQUENESS (occurrence accuracy)
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function duplicate_activity_register_for_same_occurrence_is_rejected(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Morning Service');
        $date = now()->toDateString();

        $payload = [
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'activity_id' => $activity->id,
            'register_name' => 'Morning Service',
            'register_date' => $date,
            'register_type' => 'meeting',
        ];

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/attendance-registers', $payload)
            ->assertStatus(201);

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/attendance-registers', $payload)
            ->assertStatus(409);
    }

    #[Test]
    public function two_different_activities_may_each_have_a_register_on_the_same_day(): void
    {
        $morning = $this->createActivity($this->siteA1, 'Morning Service');
        $evening = $this->createActivity($this->siteA1, 'Evening Service');
        $date = now()->toDateString();

        foreach ([$morning, $evening] as $activity) {
            $this->actingAs($this->coordinator, 'sanctum')
                ->postJson('/api/v1/attendance-registers', [
                    'campaign_id' => $this->campaignA->id,
                    'mission_site_id' => $this->siteA1->id,
                    'activity_id' => $activity->id,
                    'register_name' => 'Service',
                    'register_date' => $date,
                    'register_type' => 'meeting',
                ])
                ->assertStatus(201);
        }

        $this->assertSame(2, AttendanceRegister::count());
    }

    #[Test]
    public function duplicate_standalone_register_for_same_occurrence_is_rejected(): void
    {
        $payload = [
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'register_name' => 'Morning Roll Call',
            'register_date' => now()->toDateString(),
            'register_type' => 'roll_call',
        ];

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/attendance-registers', $payload)
            ->assertStatus(201);

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/attendance-registers', $payload)
            ->assertStatus(409);
    }

    #[Test]
    public function same_named_standalone_register_with_a_different_type_is_allowed(): void
    {
        $base = [
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'register_name' => 'Daily Gathering',
            'register_date' => now()->toDateString(),
        ];

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/attendance-registers', [...$base, 'register_type' => 'roll_call'])
            ->assertStatus(201);

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/attendance-registers', [...$base, 'register_type' => 'service'])
            ->assertStatus(201);

        $this->assertSame(2, AttendanceRegister::count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // C. ATTENDANCE IDENTITY FORMS
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function identified_attendance_is_recorded_against_a_person(): void
    {
        $register = $this->createRegister();
        $person = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'person_id' => $person->id,
                ]],
            ])
            ->assertOk();

        $entry = AttendanceEntry::firstOrFail();
        $this->assertSame($person->id, $entry->person_id);
        $this->assertFalse($entry->is_anonymous);
    }

    #[Test]
    public function named_but_unidentified_attendance_never_creates_a_person(): void
    {
        $register = $this->createRegister();
        $personsBefore = Person::count();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'visitor_name' => 'Grace from the next village',
                ]],
            ])
            ->assertOk();

        $entry = AttendanceEntry::firstOrFail();
        $this->assertNull($entry->person_id);
        $this->assertSame('Grace from the next village', $entry->visitor_name);
        $this->assertFalse($entry->is_anonymous);

        // No placeholder Person was invented to fill the gap.
        $this->assertSame($personsBefore, Person::count());
    }

    #[Test]
    public function anonymous_attendance_is_recorded_without_any_identity(): void
    {
        $register = $this->createRegister();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'is_anonymous' => true,
                ]],
            ])
            ->assertOk();

        $entry = AttendanceEntry::firstOrFail();
        $this->assertNull($entry->person_id);
        $this->assertNull($entry->visitor_name);
        $this->assertTrue($entry->is_anonymous);
    }

    #[Test]
    public function attendance_row_with_no_identity_at_all_is_rejected(): void
    {
        $register = $this->createRegister();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame(0, AttendanceEntry::count());
    }

    #[Test]
    public function anonymous_row_carrying_a_person_is_rejected(): void
    {
        $register = $this->createRegister();
        $person = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'is_anonymous' => true,
                    'person_id' => $person->id,
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame(0, AttendanceEntry::count());
    }

    #[Test]
    public function row_supplying_both_person_and_name_is_rejected(): void
    {
        $register = $this->createRegister();
        $person = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'person_id' => $person->id,
                    'visitor_name' => 'Ambiguous',
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame(0, AttendanceEntry::count());
    }

    #[Test]
    public function attendance_entry_is_idempotent_across_repeated_saves(): void
    {
        $register = $this->createRegister();
        $person = $this->createPerson();
        $uuid = (string) Str::uuid();

        foreach ([1, 2, 3] as $attempt) {
            $this->actingAs($this->coordinator, 'sanctum')
                ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                    'entries' => [[
                        'entry_uuid' => $uuid,
                        'is_present' => true,
                        'person_id' => $person->id,
                    ]],
                ])
                ->assertOk();
        }

        // Three saves, one row.
        $this->assertSame(1, AttendanceEntry::count());
    }

    #[Test]
    public function entry_uuid_from_another_register_is_refused(): void
    {
        $registerA = $this->createRegister();
        $registerB = $this->createRegister(['register_name' => 'Second Register']);
        $uuid = (string) Str::uuid();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$registerA->id}/entries", [
                'entries' => [[
                    'entry_uuid' => $uuid,
                    'is_present' => true,
                    'visitor_name' => 'First Register Attendee',
                ]],
            ])
            ->assertOk();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$registerB->id}/entries", [
                'entries' => [[
                    'entry_uuid' => $uuid,
                    'is_present' => true,
                    'visitor_name' => 'Reused Key',
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame(1, AttendanceEntry::count());
    }

    #[Test]
    public function unchecking_a_participant_removes_the_attendance_row(): void
    {
        $register = $this->createRegister();
        $person = $this->createPerson();
        $uuid = (string) Str::uuid();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => $uuid,
                    'is_present' => true,
                    'person_id' => $person->id,
                ]],
            ])
            ->assertOk();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => $uuid,
                    'is_present' => false,
                ]],
            ])
            ->assertOk();

        $this->assertSame(0, AttendanceEntry::count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // D. PLANNED ROSTER vs ACTUAL ATTENDANCE
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function participants_view_separates_planned_roster_from_actual_attendance(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Crusade');
        $rosterMan = $this->createPerson();

        // Add one person to the planned roster.
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}/participants", [
                'person_id' => $rosterMan->id,
            ])
            ->assertStatus(201);

        $register = $this->createRegister(['activity_id' => $activity->id, 'register_name' => 'Crusade']);

        // Planned but not present.
        $response = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$register->id}/participants")
            ->assertOk();

        $row = collect($response->json())->firstWhere('person_id', $rosterMan->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['is_on_roster']);
        $this->assertFalse($row['is_present']);

        // Mark the roster member present.
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'person_id' => $rosterMan->id,
                ]],
            ])
            ->assertOk();

        $response = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$register->id}/participants")
            ->assertOk();

        $row = collect($response->json())->firstWhere('person_id', $rosterMan->id);
        $this->assertTrue($row['is_on_roster']);
        $this->assertTrue($row['is_present']);
        $this->assertFalse($row['is_walk_in'], 'A roster member is not a walk-in.');

        // A registered person who was never on the roster is a walk-in.
        $walkIn = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'person_id' => $walkIn->id,
                ]],
            ])
            ->assertOk();

        $response = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$register->id}/participants")
            ->assertOk();

        $row = collect($response->json())->firstWhere('person_id', $walkIn->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_on_roster']);
        $this->assertTrue($row['is_present']);
        $this->assertTrue($row['is_walk_in']);
    }

    #[Test]
    public function unregistered_attendees_appear_in_the_participants_view(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Open Air Meeting');
        $register = $this->createRegister(['activity_id' => $activity->id, 'register_name' => 'Open Air Meeting']);

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [
                    ['entry_uuid' => (string) Str::uuid(), 'is_present' => true, 'visitor_name' => 'Name Only'],
                    ['entry_uuid' => (string) Str::uuid(), 'is_present' => true, 'is_anonymous' => true],
                ],
            ])
            ->assertOk();

        $rows = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$register->id}/participants")
            ->assertOk()
            ->json();

        $names = collect($rows)->pluck('name')->all();

        $this->assertContains('Name Only', $names);
        $this->assertContains('Anonymous attendee', $names);

        // No placeholder name is ever invented for an anonymous attendance, and
        // neither row was disguised as a roster member.
        $anonymousRow = collect($rows)->firstWhere('is_anonymous', true);
        $this->assertSame('Anonymous attendee', $anonymousRow['name']);
        $this->assertFalse($anonymousRow['is_on_roster']);
        $this->assertTrue($anonymousRow['is_walk_in']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // E. LATER LINKING
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_named_entry_can_later_be_linked_to_a_person_without_losing_the_recorded_name(): void
    {
        $register = $this->createRegister();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'visitor_name' => 'Grace',
                ]],
            ])
            ->assertOk();

        $entry = AttendanceEntry::firstOrFail();
        $person = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries/{$entry->id}/link", [
                'person_id' => $person->id,
            ])
            ->assertOk();

        $entry->refresh();

        $this->assertSame($person->id, $entry->person_id);
        $this->assertFalse($entry->is_anonymous);
        // The name recorded on the day is the historical record and is kept.
        $this->assertSame('Grace', $entry->visitor_name);
    }

    #[Test]
    public function an_anonymous_entry_can_later_be_linked_to_a_person(): void
    {
        $register = $this->createRegister();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'is_anonymous' => true,
                ]],
            ])
            ->assertOk();

        $entry = AttendanceEntry::firstOrFail();
        $person = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries/{$entry->id}/link", [
                'person_id' => $person->id,
            ])
            ->assertOk();

        $entry->refresh();

        $this->assertSame($person->id, $entry->person_id);
        $this->assertFalse($entry->is_anonymous);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // F. HISTORICAL PRESERVATION
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function soft_deleting_a_person_preserves_their_attendance_history(): void
    {
        $register = $this->createRegister();
        $person = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'person_id' => $person->id,
                ]],
            ])
            ->assertOk();

        $person->delete(); // soft delete only

        $this->assertSoftDeleted('people', ['id' => $person->id]);

        // The attendance record remains, still pointing at the permanent identity.
        $entry = AttendanceEntry::firstOrFail();
        $this->assertSame($person->id, $entry->person_id);
    }

    #[Test]
    public function hard_deleting_a_person_is_refused_when_they_have_roster_history(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Historical Activity');
        $person = $this->createPerson();

        ActivityParticipant::create([
            'activity_id' => $activity->id,
            'person_id' => $person->id,
            'joined_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        // The database refuses the removal instead of quietly discarding mission
        // history (people.person_id → activity_participants is ON DELETE RESTRICT).
        $person->forceDelete();
    }

    #[Test]
    public function walk_in_status_is_a_snapshot_and_is_not_recomputed_after_a_roster_change(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Snapshot Activity');
        $person = $this->createPerson();

        $participant = ActivityParticipant::create([
            'activity_id' => $activity->id,
            'person_id' => $person->id,
            'joined_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $register = $this->createRegister(['activity_id' => $activity->id, 'register_name' => 'Snapshot Activity']);
        $uuid = (string) Str::uuid();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => $uuid,
                    'is_present' => true,
                    'person_id' => $person->id,
                ]],
            ])
            ->assertOk();

        $this->assertFalse(AttendanceEntry::firstOrFail()->is_walk_in);

        // The roster is corrected afterwards (the person is removed from the plan).
        $participant->delete();

        // Re-saving the SAME row keeps what was true on the day.
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => $uuid,
                    'is_present' => true,
                    'person_id' => $person->id,
                ]],
            ])
            ->assertOk();

        $this->assertFalse(
            AttendanceEntry::firstOrFail()->is_walk_in,
            'is_walk_in must not be recomputed from current roster membership.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // G. OUTREACH REGRESSION
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function recording_attendance_never_creates_an_outreach_contact_or_encounter(): void
    {
        $register = $this->createRegister();
        $person = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [
                    ['entry_uuid' => (string) Str::uuid(), 'is_present' => true, 'person_id' => $person->id],
                    ['entry_uuid' => (string) Str::uuid(), 'is_present' => true, 'visitor_name' => 'Name Only'],
                    ['entry_uuid' => (string) Str::uuid(), 'is_present' => true, 'is_anonymous' => true],
                ],
            ])
            ->assertOk();

        $this->assertSame(0, OutreachContact::count());
        $this->assertSame(0, OutreachEncounter::count());
        $this->assertNull($person->fresh()->outreachContact);
    }

    #[Test]
    public function registering_a_person_creates_no_roster_entry(): void
    {
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/people', [
                'first_name' => 'New',
                'last_name' => 'Person',
                'campaign_id' => $this->campaignA->id,
                'mission_site_id' => $this->siteA1->id,
            ])
            ->assertStatus(201);

        // Registration is an identity action only: no ring-fencing into a
        // roster, an attendance register, or the outreach pipeline.
        $this->assertSame(0, ActivityParticipant::count());
        $this->assertSame(0, AttendanceEntry::count());
        $this->assertSame(0, OutreachContact::count());
        $this->assertSame(0, OutreachEncounter::count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // H. PLANNED ROSTER LIFECYCLE
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function roster_participants_can_be_created_listed_and_removed(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Roster Lifecycle');
        $first = $this->createPerson();
        $second = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}/participants", [
                'person_id' => $first->id,
            ])
            ->assertStatus(201);

        $secondParticipant = $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}/participants", [
                'person_id' => $second->id,
            ])
            ->assertStatus(201);

        // The roster can be listed by name, and listing it writes no attendance.
        $rows = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}/participants")
            ->assertOk()
            ->json();

        $this->assertCount(2, $rows);
        $this->assertContains($first->full_name, collect($rows)->pluck('name')->all());
        $this->assertSame(0, AttendanceEntry::count(), 'The roster is a plan, not attendance.');

        // The plan can be corrected while the activity is still scheduled.
        $this->actingAs($this->coordinator, 'sanctum')
            ->deleteJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}/participants/{$secondParticipant->json('id')}")
            ->assertOk();

        $this->assertSame(1, ActivityParticipant::where('activity_id', $activity->id)->count());
    }

    #[Test]
    public function roster_cannot_be_changed_once_the_activity_has_finished(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Finished Activity', 'completed');
        $person = $this->createPerson();

        $participant = ActivityParticipant::create([
            'activity_id' => $activity->id,
            'person_id' => $person->id,
            'joined_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        // The plan is history once the activity is over; the attendance that was
        // actually recorded on the day stands on its own.
        $this->actingAs($this->coordinator, 'sanctum')
            ->deleteJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}/participants/{$participant->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('activity_participants', ['id' => $participant->id]);
    }

    #[Test]
    public function roster_operations_are_refused_outside_the_callers_scope(): void
    {
        // A real activity in the same campaign, at a site the coordinator does
        // not hold.
        $sibling = $this->createActivity($this->siteA2, 'Sibling Roster');
        $person = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$sibling->id}/participants")
            ->assertStatus(403);

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$sibling->id}/participants", [
                'person_id' => $person->id,
            ])
            ->assertStatus(403);

        $participant = ActivityParticipant::create([
            'activity_id' => $sibling->id,
            'person_id' => $person->id,
            'joined_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($this->coordinator, 'sanctum')
            ->deleteJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$sibling->id}/participants/{$participant->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('activity_participants', ['id' => $participant->id]);
    }

    #[Test]
    public function an_activity_from_another_campaign_is_not_found_under_this_campaign(): void
    {
        $foreignActivity = $this->createActivity($this->siteB1, 'Foreign Activity');

        // The route names a campaign the activity does not belong to, so the
        // nested resource simply does not exist there.
        $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$foreignActivity->id}/participants")
            ->assertStatus(404);
    }

    #[Test]
    public function read_only_role_can_see_the_roster_but_not_change_it(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Read Only Roster');
        $person = $this->createPerson();

        $this->actingAs($this->missionary, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}/participants")
            ->assertOk();

        // activities.view is not activities.update: filling a register never
        // implies the authority to change the plan.
        $this->actingAs($this->missionary, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}/participants", [
                'person_id' => $person->id,
            ])
            ->assertStatus(403);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // I. REGISTER AUTHORISATION
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function role_without_attendance_permission_cannot_create_a_register(): void
    {
        $this->actingAs($this->observer, 'sanctum')
            ->postJson('/api/v1/attendance-registers', [
                'campaign_id' => $this->campaignA->id,
                'mission_site_id' => $this->siteA1->id,
                'register_name' => 'Not Allowed',
                'register_date' => now()->toDateString(),
            ])
            ->assertStatus(403);

        $this->assertSame(0, AttendanceRegister::count());
    }

    #[Test]
    public function register_creation_is_refused_for_a_sibling_site(): void
    {
        // Correct campaign, but the site belongs to someone else.
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/attendance-registers', [
                'campaign_id' => $this->campaignA->id,
                'mission_site_id' => $this->siteA2->id,
                'register_name' => 'Sibling Register',
                'register_date' => now()->toDateString(),
            ])
            ->assertStatus(403);

        $this->assertSame(0, AttendanceRegister::count());
    }

    #[Test]
    public function register_creation_is_refused_for_another_campaign(): void
    {
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/attendance-registers', [
                'campaign_id' => $this->campaignB->id,
                'mission_site_id' => $this->siteB1->id,
                'register_name' => 'Foreign Register',
                'register_date' => now()->toDateString(),
            ])
            ->assertStatus(403);

        $this->assertSame(0, AttendanceRegister::count());
    }

    #[Test]
    public function a_register_at_a_sibling_site_cannot_be_read(): void
    {
        $foreignRegister = AttendanceRegister::create([
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA2->id,
            'register_name' => 'Sibling Register',
            'register_date' => now()->toDateString(),
            'register_type' => 'meeting',
            'status' => 'open',
        ]);

        $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$foreignRegister->id}")
            ->assertStatus(403);

        $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$foreignRegister->id}/participants")
            ->assertStatus(403);

        // Nor may a record be written against it.
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$foreignRegister->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'visitor_name' => 'Intruder',
                ]],
            ])
            ->assertStatus(403);

        $this->assertSame(0, AttendanceEntry::count());
    }

    #[Test]
    public function a_team_scoped_leader_is_admitted_at_the_team_site_only(): void
    {
        // A team that sits at siteA1. AuthorizationService expands a team
        // assignment into the site that team belongs to, so the boundary a
        // team-scoped leader gets must be exactly the boundary a site-scoped
        // leader gets — no wider, no narrower.
        $team = Team::factory()->create([
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
        ]);

        $teamRole = $this->createRole('team_leader', 'team', [
            'activities.view', 'activities.update',
            'attendance.submit', 'attendance.view',
            'people.view',
        ]);

        $leader = User::factory()->create();
        UserRoleAssignment::create([
            'user_id' => $leader->id,
            'system_role_id' => $teamRole->id,
            'scope_type' => 'team',
            'scoped_team_id' => $team->id,
        ]);

        // Admitted at the team's own site.
        $this->actingAs($leader, 'sanctum')
            ->postJson('/api/v1/attendance-registers', [
                'campaign_id' => $this->campaignA->id,
                'mission_site_id' => $this->siteA1->id,
                'register_name' => 'Team Register',
                'register_date' => now()->toDateString(),
            ])
            ->assertStatus(201);

        // Refused at the sibling site, although it sits in the same campaign.
        $this->actingAs($leader, 'sanctum')
            ->postJson('/api/v1/attendance-registers', [
                'campaign_id' => $this->campaignA->id,
                'mission_site_id' => $this->siteA2->id,
                'register_name' => 'Sibling Register',
                'register_date' => now()->toDateString(),
            ])
            ->assertStatus(403);

        // And the planned roster of a sibling-site activity is out of reach.
        $siblingActivity = $this->createActivity($this->siteA2, 'Sibling Activity');

        $this->actingAs($leader, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/activities/{$siblingActivity->id}/participants")
            ->assertStatus(403);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // J. MULTI-DAY OCCURRENCES
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_multi_day_activity_may_have_a_register_on_each_day_it_runs(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Five Night Crusade');

        $dates = [
            now()->toDateString(),
            now()->addDay()->toDateString(),
            now()->addDays(2)->toDateString(),
        ];

        foreach ($dates as $date) {
            $this->actingAs($this->coordinator, 'sanctum')
                ->postJson('/api/v1/attendance-registers', [
                    'campaign_id' => $this->campaignA->id,
                    'mission_site_id' => $this->siteA1->id,
                    'activity_id' => $activity->id,
                    'register_name' => 'Crusade Night',
                    'register_date' => $date,
                    'register_type' => 'meeting',
                ])
                ->assertStatus(201);
        }

        $this->assertSame(3, AttendanceRegister::where('activity_id', $activity->id)->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // K. DATABASE-LEVEL UNIQUENESS
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_database_refuses_a_duplicate_activity_register(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Index Guard');

        $payload = [
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'activity_id' => $activity->id,
            'register_name' => 'Index Guard',
            'register_date' => now()->toDateString(),
            'register_type' => 'meeting',
            'status' => 'open',
        ];

        AttendanceRegister::create($payload);

        $this->expectException(QueryException::class);

        // The partial unique index ar_unique_activity_register — not the
        // controller's friendly 409 — is what makes this impossible, so no other
        // code path can bypass it.
        AttendanceRegister::create($payload);
    }

    #[Test]
    public function the_database_refuses_a_duplicate_standalone_register(): void
    {
        $payload = [
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'register_name' => 'Standalone Guard',
            'register_date' => now()->toDateString(),
            'register_type' => 'roll_call',
            'status' => 'open',
        ];

        AttendanceRegister::create($payload);

        $this->expectException(QueryException::class);

        AttendanceRegister::create($payload);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // L. MERGE INTEGRITY
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_person_who_is_on_the_roster_and_present_is_listed_exactly_once(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Merge Integrity');
        $person = $this->createPerson();

        ActivityParticipant::create([
            'activity_id' => $activity->id,
            'person_id' => $person->id,
            'joined_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $register = $this->createRegister(['activity_id' => $activity->id, 'register_name' => 'Merge Integrity']);

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'person_id' => $person->id,
                ]],
            ])
            ->assertOk();

        $rows = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$register->id}/participants")
            ->assertOk()
            ->json();

        $matching = collect($rows)->where('person_id', $person->id);

        $this->assertCount(1, $matching, 'A person on the roster AND present must not be listed twice.');
        $this->assertTrue($matching->first()['is_on_roster']);
        $this->assertTrue($matching->first()['is_present']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // M. NEW IDENTIFIABLE PERSON
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_newly_registered_person_can_then_be_marked_present(): void
    {
        $register = $this->createRegister();

        $newPersonId = $this->actingAs($this->coordinator, 'sanctum')
            ->postJson('/api/v1/people', [
                'first_name' => 'Just',
                'last_name' => 'Registered',
                'campaign_id' => $this->campaignA->id,
                'mission_site_id' => $this->siteA1->id,
            ])
            ->assertStatus(201)
            ->json('id');

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'person_id' => $newPersonId,
                ]],
            ])
            ->assertOk();

        $entry = AttendanceEntry::firstOrFail();

        $this->assertSame($newPersonId, $entry->person_id);
        $this->assertFalse($entry->is_anonymous);
        // Registering the identity did not ring-fence them into the plan.
        $this->assertSame(0, ActivityParticipant::count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // N. OUTREACH REGRESSION ON LINKING
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function linking_an_entry_to_a_person_creates_no_outreach_record(): void
    {
        $register = $this->createRegister();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => (string) Str::uuid(),
                    'is_present' => true,
                    'visitor_name' => 'Unidentified',
                ]],
            ])
            ->assertOk();

        $entry = AttendanceEntry::firstOrFail();
        $person = $this->createPerson();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries/{$entry->id}/link", [
                'person_id' => $person->id,
            ])
            ->assertOk();

        // Linking recognises an identity. It is not an encounter, and it must not
        // quietly enrol the person into the outreach pipeline.
        $this->assertSame(0, OutreachContact::count());
        $this->assertSame(0, OutreachEncounter::count());
        $this->assertNull($person->fresh()->outreachContact);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // M. ACTIVITY OBJECTIVES (Decision 1 — multiple objectives per activity)
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function activity_can_carry_multiple_objectives_ordered_for_display(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Evening Crusade');

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson($this->objectivesPath($activity), ['title' => 'Reach the surrounding villages'])
            ->assertStatus(201);

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson($this->objectivesPath($activity), ['title' => 'Distribute 200 tracts', 'sort_order' => 5])
            ->assertStatus(201);

        $response = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson($this->objectivesPath($activity))
            ->assertOk();

        // Objectives are a plain list owned by the activity — several per
        // activity, returned in the author's display order.
        $this->assertSame(
            ['Reach the surrounding villages', 'Distribute 200 tracts'],
            collect($response->json())->pluck('title')->all(),
        );
        $this->assertSame(2, ActivityObjective::where('activity_id', $activity->id)->count());
    }

    #[Test]
    public function objectives_are_not_a_workflow_object_and_carry_no_status(): void
    {
        $activity = $this->createActivity($this->siteA1, 'Health Outreach');

        $response = $this->actingAs($this->coordinator, 'sanctum')
            ->postJson($this->objectivesPath($activity), [
                'title' => 'Screen 100 people',
                'description' => 'Basic vitals and referral',
            ])
            ->assertStatus(201);

        // An objective expresses intent; achievement is a Mission Outcome. It
        // must not smuggle in a status/approval column of its own.
        $this->assertArrayNotHasKey('status', $response->json());
        $this->assertArrayNotHasKey('approved_at', $response->json());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // N. PLANNED vs UNPLANNED ACTIVITIES (matches the attendance flow)
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function selecting_an_existing_planned_activity_loads_its_objectives(): void
    {
        // The secretary does not recreate an activity that leadership already
        // planned: they pick it, and its details and objectives come with it.
        $planned = $this->createActivity($this->siteA1, 'Planned Bible Study');

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson($this->objectivesPath($planned), ['title' => 'Grow a study group'])
            ->assertStatus(201);

        $listed = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/campaigns/{$this->campaignA->id}/activities?site_id={$this->siteA1->id}")
            ->assertOk();

        $this->assertContains('Planned Bible Study', collect($listed->json())->pluck('title')->all());

        $loaded = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson($this->objectivesPath($planned))
            ->assertOk();

        $this->assertSame(['Grow a study group'], collect($loaded->json())->pluck('title')->all());
    }

    #[Test]
    public function duplicate_planned_activity_is_refused_and_the_existing_one_is_returned(): void
    {
        $payload = [
            'mission_site_id' => $this->siteA1->id,
            'activity_type_id' => $this->activityType->id,
            'title' => 'Morning Devotion',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ];

        $first = $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities", $payload)
            ->assertStatus(201);

        // Re-authoring the same occurrence (same site, type, title and day) must
        // hand back the existing activity rather than silently cloning it.
        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities", $payload)
            ->assertStatus(409)
            ->assertJsonFragment(['id' => $first->json('id')]);

        $this->assertSame(
            1,
            Activity::where('mission_site_id', $this->siteA1->id)
                ->where('title', 'Morning Devotion')
                ->count(),
        );
    }

    #[Test]
    public function a_genuinely_unplanned_activity_is_recorded_and_distinguishable(): void
    {
        $response = $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities", [
                'mission_site_id' => $this->siteA1->id,
                'activity_type_id' => $this->activityType->id,
                'title' => 'Unplanned prayer gathering',
                'scheduled_at' => now()->toDateTimeString(),
                'is_planned' => false,
            ])
            ->assertStatus(201);

        // The two origins must stay distinguishable — a glance at the record
        // shows whether this was planned by leadership or arose on the day.
        $this->assertFalse($response->json('is_planned'));

        $planned = $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/campaigns/{$this->campaignA->id}/activities", [
                'mission_site_id' => $this->siteA1->id,
                'activity_type_id' => $this->activityType->id,
                'title' => 'Planned teaching',
                'scheduled_at' => now()->toDateTimeString(),
            ])
            ->assertStatus(201);

        $this->assertTrue($planned->json('is_planned'));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // O. ATTENDANCE METADATA AND SUMMARIES (Phase 3)
    // ═════════════════════════════════════════════════════════════════════════

    #[Test]
    public function attendance_visitor_metadata_survives_the_save_and_reload_cycle(): void
    {
        $register = $this->createRegister();
        $uuid = (string) Str::uuid();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$register->id}/entries", [
                'entries' => [[
                    'entry_uuid' => $uuid,
                    'is_present' => true,
                    'visitor_name' => 'Grace',
                    'visitor_gender' => 'female',
                ]],
            ])
            ->assertOk();

        // Reloading the register must return the optional metadata captured on
        // the day — otherwise the field would silently appear blank.
        $reloaded = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/{$register->id}")
            ->assertOk();

        $entry = collect($reloaded->json('entries'))->firstWhere('entry_uuid', $uuid);
        $this->assertNotNull($entry);
        $this->assertSame('female', $entry['visitor_gender']);
    }

    #[Test]
    public function attendance_summary_reports_site_and_activity_totals_separately(): void
    {
        // One standalone site register with two attendances …
        $siteRegister = $this->createRegister();
        $activity = $this->createActivity($this->siteA1, 'Summary Activity');
        $activityRegister = $this->createRegister([
            'activity_id' => $activity->id,
            'register_name' => 'Activity Register',
            'register_type' => 'meeting',
        ]);

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$siteRegister->id}/entries", [
                'entries' => [
                    ['entry_uuid' => (string) Str::uuid(), 'is_present' => true, 'person_id' => $this->createPerson()->id],
                    ['entry_uuid' => (string) Str::uuid(), 'is_present' => true, 'is_anonymous' => true],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->coordinator, 'sanctum')
            ->postJson("/api/v1/attendance-registers/{$activityRegister->id}/entries", [
                'entries' => [
                    ['entry_uuid' => (string) Str::uuid(), 'is_present' => true, 'person_id' => $this->createPerson()->id],
                ],
            ])
            ->assertOk();

        $summary = $this->actingAs($this->coordinator, 'sanctum')
            ->getJson("/api/v1/attendance-registers/summary?campaign_id={$this->campaignA->id}")
            ->assertOk();

        // Site presence and activity participation are independent records and
        // are reported as separate totals that must never be added together.
        $this->assertSame(2, $summary->json('site_attendance.entries'));
        $this->assertSame(1, $summary->json('activity_attendance.entries'));
        $this->assertSame(1, $summary->json('site_attendance.anonymous'));
        $this->assertCount(1, $summary->json('by_activity'));
        $this->assertSame($activity->id, $summary->json('by_activity.0.activity_id'));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // FIXTURE BUILDERS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * The objectives collection for one activity, rooted at the campaign so the
     * campaign scope check runs before the activity is touched.
     */
    private function objectivesPath(Activity $activity): string
    {
        return "/api/v1/campaigns/{$this->campaignA->id}/activities/{$activity->id}/objectives";
    }

    private function createRole(string $code, string $scopeType, array $permCodes): SystemRole
    {
        $role = SystemRole::factory()->create([
            'code' => $code,
            'label' => ucwords(str_replace('_', ' ', $code)),
            'scope_type' => $scopeType,
        ]);

        $permIds = collect($permCodes)->map(function (string $pc) {
            return Permission::firstOrCreate(['code' => $pc], ['label' => $pc, 'module' => explode('.', $pc)[0]])->id;
        })->toArray();

        $this->publishRolePermissions($role, $permIds);

        return $role;
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

    private function createActivity(MissionSite $site, string $title, string $status = 'scheduled'): Activity
    {
        return Activity::create([
            'campaign_id' => $site->campaign_id,
            'mission_site_id' => $site->id,
            'activity_type_id' => $this->activityType->id,
            'title' => $title,
            'status' => $status,
            'scheduled_at' => now()->addDay(),
        ]);
    }

    /**
     * A person the coordinator is entitled to see. `registered_by_user_id` is
     * set because PersonPolicy grants access to a person's own registrar.
     */
    private function createPerson(): Person
    {
        return Person::factory()->create(['registered_by_user_id' => $this->coordinator->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRegister(array $overrides = []): AttendanceRegister
    {
        return AttendanceRegister::create([
            'campaign_id' => $this->campaignA->id,
            'mission_site_id' => $this->siteA1->id,
            'register_name' => 'Fieldwork Register',
            'register_date' => now()->toDateString(),
            'register_type' => 'meeting',
            'status' => 'open',
            ...$overrides,
        ]);
    }
}
