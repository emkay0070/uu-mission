<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\AttendanceEntry;
use App\Models\AttendanceRegister;
use App\Models\MissionSite;
use App\Models\Person;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AttendanceController — daily attendance management for mission activities.
 *
 * A missionary may only manage attendance for activities within their
 * authorised scope. Scope is resolved through AuthorizationService so the
 * checks here behave identically to the AttendanceRegisterPolicy checks.
 */
class AttendanceController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List attendance registers with optional filtering.
     *
     * GET /api/v1/attendance-registers
     * ?campaign_id=1
     * ?mission_site_id=2
     * ?activity_id=3
     * ?date=2026-11-05
     * ?status=open|submitted|verified
     *
     * The result set is constrained to the user's authorised (campaign, site)
     * scopes for the attendance module.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRegister::class);

        $request->validate([
            'campaign_id' => ['sometimes', 'integer', 'exists:campaigns,id'],
            'mission_site_id' => ['sometimes', 'integer', 'exists:mission_sites,id'],
            'activity_id' => ['sometimes', 'integer', 'exists:activities,id'],
            'date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:open,submitted,verified'],
            'per_page' => ['sometimes', 'integer', 'between:5,100'],
        ]);

        // Constrain to the user's authorised scopes. attendance.submit is
        // included because the seeded missionary role holds submit but not view.
        $scopes = $this->auth->getAuthorisedScopes(
            $request->user(),
            ['attendance.view', 'attendance.submit']
        );

        $query = AttendanceRegister::with(['activity', 'site', 'entries.person']);
        $this->auth->scopeQuery($query, $scopes, 'campaign_id', 'mission_site_id');

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->campaign_id);
        }

        if ($request->filled('mission_site_id')) {
            $query->where('mission_site_id', $request->mission_site_id);
        }

        if ($request->filled('activity_id')) {
            $query->where('activity_id', $request->activity_id);
        }

        if ($request->filled('date')) {
            $query->where('register_date', $request->date);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $registers = $query->orderBy('register_date', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($registers);
    }

    /**
     * Create a new attendance register.
     *
     * POST /api/v1/attendance-registers
     * body: { campaign_id, mission_site_id, activity_id?, register_name, register_date, register_type }
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AttendanceRegister::class);

        $validated = $request->validate([
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
            'mission_site_id' => ['required', 'integer', 'exists:mission_sites,id'],
            'activity_id' => ['sometimes', 'integer', 'exists:activities,id'],
            'register_name' => ['required', 'string', 'max:250'],
            'register_date' => ['required', 'date'],
            'register_type' => ['sometimes', 'string', 'in:meeting,roll_call,service,training,community_event'],
        ]);

        // Verify the user is authorised for this campaign/site.
        $site = MissionSite::findOrFail($validated['mission_site_id']);

        if ((int) $site->campaign_id !== (int) $validated['campaign_id']) {
            return response()->json([
                'message' => 'The selected mission site does not belong to the selected campaign.',
            ], 422);
        }

        if (! $this->auth->hasPermissionForSite($request->user(), 'attendance.submit', $site)) {
            return response()->json([
                'message' => 'You are not authorised to create attendance registers for this campaign/site.',
            ], 403);
        }

        // A register that claims an activity must point at an activity in the
        // same campaign and site as the register itself.
        if (! empty($validated['activity_id'])) {
            $activity = Activity::find($validated['activity_id']);

            if (! $activity
                || (int) $activity->campaign_id !== (int) $validated['campaign_id']
                || (int) $activity->mission_site_id !== (int) $validated['mission_site_id']) {
                return response()->json([
                    'message' => 'The selected activity does not belong to the selected campaign/site.',
                ], 422);
            }
        }

        // Duplicate check mirrors the PARTIAL unique indexes exactly, so the
        // controller answer and the database answer can never disagree:
        //   activity-linked → one register per activity/date/name
        //   standalone      → one register per site/date/type/name
        $existing = $this->findDuplicateRegister($validated);

        if ($existing) {
            return response()->json([
                'message' => 'An attendance register with these details already exists.',
                'register' => $existing,
            ], 409);
        }

        $register = AttendanceRegister::create([
            ...$validated,
            'register_type' => $validated['register_type'] ?? 'meeting',
            'status' => 'open',
        ]);

        return response()->json($register->load(['activity', 'site']), 201);
    }

    /**
     * Show an attendance register with its entries.
     *
     * GET /api/v1/attendance-registers/{register}
     */
    public function show(AttendanceRegister $register): JsonResponse
    {
        $this->authorize('view', $register);

        $register->load(['activity', 'site', 'entries.person']);

        return response()->json($register);
    }

    /**
     * Get the participants view for an attendance register.
     *
     * GET /api/v1/attendance-registers/{register}/participants
     *
     * This is a MERGE of two distinct sources, deliberately:
     *
     *   1. The PLANNED ROSTER (activity_participants) when the register belongs
     *      to an activity, or the people already known at this campaign/site
     *      when it is a standalone roll call.
     *   2. The ACTUAL ATTENDANCE (attendance_entries) already recorded for this
     *      register, including rows that are not on the roster at all
     *      (walk-ins, named-but-unidentified and anonymous attendances).
     *
     * Neither source is authoritative about the other: a person can be on the
     * roster and absent, and a person can be present without ever having been
     * on the roster. The response therefore reports `is_on_roster` and
     * `is_present` independently so the UI never has to guess.
     *
     * Rows are keyed by `entry_uuid` where an entry exists; the client mints a
     * UUID for rows that have none yet, so a mark/unmark round trip stays
     * idempotent.
     */
    public function participants(AttendanceRegister $register): JsonResponse
    {
        $this->authorize('view', $register);

        $entries = AttendanceEntry::where('attendance_register_id', $register->id)
            ->with('person.outreachContact')
            ->get();

        // Index actual attendance by person so roster rows can be merged with
        // whatever was recorded for them.
        $entriesByPerson = $entries->whereNotNull('person_id')->keyBy('person_id');

        $rows = [];
        $coveredPersonIds = [];

        if ($register->activity_id !== null) {
            // ── Planned roster for the activity ───────────────────────────────
            $roster = ActivityParticipant::where('activity_id', $register->activity_id)
                ->with(['person.outreachContact'])
                ->get();

            foreach ($roster as $participant) {
                if ($participant->person === null) {
                    continue;
                }

                $coveredPersonIds[] = $participant->person_id;

                $rows[] = $this->participantRow(
                    $participant->person,
                    $entriesByPerson->get($participant->person_id),
                    isOnRoster: true,
                    joinedDate: $participant->joined_date?->toDateString(),
                );
            }
        } else {
            // ── Standalone roll call: candidates are people already known at
            //    this campaign/site from previous attendance ───────────────────
            $candidates = Person::with('outreachContact')
                ->whereHas('attendanceEntries.register', function ($q) use ($register) {
                    $q->where('campaign_id', $register->campaign_id)
                        ->where('mission_site_id', $register->mission_site_id);
                })
                ->get(['id', 'first_name', 'last_name', 'phone']);

            foreach ($candidates as $person) {
                $coveredPersonIds[] = $person->id;

                $rows[] = $this->participantRow(
                    $person,
                    $entriesByPerson->get($person->id),
                    isOnRoster: false,
                    joinedDate: null,
                );
            }
        }

        // ── Everything actually recorded that the two sources above missed ────
        // Walk-in registered people, named-but-unidentified attendees and
        // anonymous counts are appended so the register reports the truth even
        // when the planned roster does not account for it.
        foreach ($entries as $entry) {
            if ($entry->person_id !== null && in_array($entry->person_id, $coveredPersonIds, true)) {
                continue;
            }

            $rows[] = $this->participantRow(
                $entry->person,
                $entry,
                isOnRoster: false,
                joinedDate: null,
            );
        }

        return response()->json($rows);
    }

    /**
     * Build one participants-view row from a person and/or an attendance entry.
     *
     * `name` resolves through the same priority order the register uses when it
     * is printed: permanent identity first, then the name recorded on the day,
     * then an explicit anonymous label. No placeholder name is ever invented
     * for an anonymous attendance.
     */
    private function participantRow(
        ?Person $person,
        ?AttendanceEntry $entry,
        bool $isOnRoster,
        ?string $joinedDate,
    ): array {
        return [
            'entry_id' => $entry?->id,
            'entry_uuid' => $entry?->entry_uuid,
            'person_id' => $person?->id,
            'name' => $person?->full_name
                                ?? $entry?->visitor_name
                                ?? 'Anonymous attendee',
            'village' => $person?->outreachContact?->village,
            'phone' => $person?->phone ?? $entry?->visitor_phone,
            // Optional metadata captured on the day must survive the
            // save → response → reload cycle. Without this the UI would show
            // a blank gender field even though the row was recorded with one.
            'visitor_gender' => $entry?->visitor_gender,
            'joined_date' => $joinedDate,
            'is_on_roster' => $isOnRoster,
            'is_present' => $entry !== null,
            'is_walk_in' => $entry?->is_walk_in ?? false,
            'is_anonymous' => $entry?->is_anonymous ?? false,
            'attendee_type' => $entry?->attendee_type,
            'is_first_time' => $entry?->is_first_time ?? false,
            'decision_made' => $entry?->decision_made,
            'notes' => $entry?->notes,
        ];
    }

    /**
     * Record attendance entries for a register.
     *
     * POST /api/v1/attendance-registers/{register}/entries
     *
     * body: {
     *   entries: [{
     *     entry_uuid:   "uuid",              // idempotency key (required)
     *     is_present:   true,
     *     person_id:    12,                  // identity form A
     *     visitor_name: "Grace",             // identity form B (no Person created)
     *     visitor_phone: "077...",           // optional, form B
     *     visitor_gender: "female",          // optional, form B
     *     is_anonymous: false,               // identity form C: neither A nor B
     *     is_first_time: false,              // optional
     *     attendee_type: "visitor",          // optional classification
     *     decision_made: null,               // optional
     *     notes: null                        // optional
     *   }]
     * }
     *
     * THREE EXPLICIT IDENTITY FORMS — exactly one per row:
     *
     *   A. identified              person_id set
     *   B. named but unidentified  visitor_name set, no Person record created
     *   C. anonymous               is_anonymous = true, no name at all
     *
     * A row that supplies none of the three, or that supplies an identity while
     * also claiming is_anonymous, is rejected with 422. This is what separates a
     * deliberate anonymous count from an incomplete row — the same distinction
     * the chk_ae_identifier / chk_ae_anonymous database constraints enforce, so
     * nothing can reach the table through another path either.
     *
     * is_present = false removes the row identified by entry_uuid (unchecking a
     * participant). Attendance NEVER creates an OutreachContact or an
     * OutreachEncounter; those remain deliberate, separate workflows.
     */
    public function updateEntries(Request $request, AttendanceRegister $register): JsonResponse
    {
        $this->authorize('update', $register);

        if ($register->status !== 'open') {
            return response()->json([
                'message' => 'Cannot update a submitted or verified register.',
            ], 403);
        }

        $validated = $request->validate([
            'entries' => ['required', 'array'],
            'entries.*.entry_uuid' => ['required', 'uuid', 'distinct'],
            'entries.*.is_present' => ['required', 'boolean'],
            'entries.*.person_id' => ['sometimes', 'nullable', 'integer', 'exists:people,id'],
            'entries.*.visitor_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'entries.*.visitor_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'entries.*.visitor_gender' => ['sometimes', 'nullable', 'string', 'in:male,female,unspecified'],
            'entries.*.is_anonymous' => ['sometimes', 'boolean'],
            'entries.*.is_first_time' => ['sometimes', 'boolean'],
            'entries.*.attendee_type' => ['sometimes', 'nullable', 'string', 'in:missionary,visitor,local_member'],
            'entries.*.decision_made' => ['sometimes', 'nullable', 'string', 'in:accepted_christ,requested_bible_study,joined_church,none'],
            'entries.*.notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // ── Identity validation (per row) ─────────────────────────────────────
        $identityErrors = [];

        foreach ($validated['entries'] as $index => $row) {
            if (! $row['is_present']) {
                continue; // removal — no identity required
            }

            $error = $this->validateIdentity($row);

            if ($error !== null) {
                $identityErrors["entries.{$index}.person_id"] = [$error];
            }
        }

        if ($identityErrors !== []) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $identityErrors,
            ], 422);
        }

        // ── Idempotency-key guard ─────────────────────────────────────────────
        // entry_uuid is globally unique. A client reusing a key that belongs to
        // a different register is a bug, not a merge: refuse it explicitly
        // rather than letting the unique index raise a 500.
        $uuids = array_column($validated['entries'], 'entry_uuid');

        $foreignUuids = AttendanceEntry::whereIn('entry_uuid', $uuids)
            ->where('attendance_register_id', '!=', $register->id)
            ->pluck('entry_uuid');

        if ($foreignUuids->isNotEmpty()) {
            return response()->json([
                'message' => 'One or more entry identifiers belong to another register.',
                'entry_uuids' => $foreignUuids->values(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($validated['entries'] as $entryData) {
                $existing = AttendanceEntry::where('attendance_register_id', $register->id)
                    ->where('entry_uuid', $entryData['entry_uuid'])
                    ->first();

                if (! $entryData['is_present']) {
                    $existing?->delete();

                    continue;
                }

                $personId = $entryData['person_id'] ?? null;
                $visitorName = $entryData['visitor_name'] ?? null;
                $isAnonymous = (bool) ($entryData['is_anonymous'] ?? false);

                // Historical snapshot: taken ONCE when the row is first written
                // and then preserved untouched. It is never recomputed from the
                // row's current person or from today's roster membership, so it
                // keeps reporting what was true on the day of the event.
                $isWalkIn = $existing !== null
                    ? (bool) $existing->is_walk_in
                    : $this->resolveIsWalkIn($register, $personId);

                $attributes = [
                    'attendee_type' => $entryData['attendee_type'] ?? null,
                    'person_id' => $personId,
                    'visitor_name' => $isAnonymous ? null : $visitorName,
                    'visitor_phone' => $isAnonymous ? null : ($entryData['visitor_phone'] ?? null),
                    'visitor_gender' => $isAnonymous ? null : ($entryData['visitor_gender'] ?? null),
                    'is_anonymous' => $isAnonymous,
                    'is_walk_in' => $isWalkIn,
                    'is_first_time' => $entryData['is_first_time'] ?? false,
                    'decision_made' => $entryData['decision_made'] ?? null,
                    'notes' => $entryData['notes'] ?? null,
                ];

                if ($existing !== null) {
                    $existing->update($attributes);
                } else {
                    AttendanceEntry::create([
                        'attendance_register_id' => $register->id,
                        'entry_uuid' => $entryData['entry_uuid'],
                        ...$attributes,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Attendance updated successfully.',
                'register' => $register->fresh(['entries.person']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update attendance.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Link an unregistered attendance row to a permanent Person.
     *
     * POST /api/v1/attendance-registers/{register}/entries/{entry}/link
     * body: { person_id: 12 }
     *
     * This is the "later linking" path: an attendee who was counted as a name
     * only, or anonymously, is afterwards recognised as (or registered as) a
     * Person. The originally recorded name is PRESERVED — it is what was true on
     * the day — while person_id becomes the permanent identity.
     */
    public function linkEntry(Request $request, AttendanceRegister $register, AttendanceEntry $entry): JsonResponse
    {
        $this->authorize('update', $register);

        if ((int) $entry->attendance_register_id !== (int) $register->id) {
            abort(404, 'Attendance entry not found within this register.');
        }

        if ($register->status !== 'open') {
            return response()->json([
                'message' => 'Cannot modify a submitted or verified register.',
            ], 403);
        }

        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
        ]);

        $entry->update([
            'person_id' => $validated['person_id'],
            'is_anonymous' => false,
            'linked_by_user_id' => $request->user()->id,
            'linked_at' => now(),
        ]);

        return response()->json($entry->fresh('person'));
    }

    /**
     * Find an existing register that occupies the same occurrence slot.
     *
     * Mirrors the two partial unique indexes on attendance_registers:
     *   - activity-linked: (activity_id, register_date, register_name)
     *   - standalone:      (mission_site_id, register_date, register_type, register_name)
     *
     * @param  array<string, mixed>  $validated
     */
    private function findDuplicateRegister(array $validated): ?AttendanceRegister
    {
        $query = AttendanceRegister::where('register_date', $validated['register_date'])
            ->where('register_name', $validated['register_name']);

        if (! empty($validated['activity_id'])) {
            return $query->where('activity_id', $validated['activity_id'])->first();
        }

        return $query
            ->whereNull('activity_id')
            ->where('mission_site_id', $validated['mission_site_id'])
            ->where('register_type', $validated['register_type'] ?? 'meeting')
            ->first();
    }

    /**
     * Validate the identity form of a present attendance row.
     *
     * Returns null when the row is acceptable, or a human-readable rejection
     * message. Exactly one of the three identity forms is allowed:
     * identified, named-but-unidentified, or explicitly anonymous.
     *
     * @param  array<string, mixed>  $row
     */
    private function validateIdentity(array $row): ?string
    {
        $hasPerson = ! empty($row['person_id']);
        $hasName = ! empty($row['visitor_name']);
        $anonymous = (bool) ($row['is_anonymous'] ?? false);

        if ($anonymous && ($hasPerson || $hasName)) {
            return 'An entry cannot be marked anonymous while also carrying a person or a name.';
        }

        if ($anonymous) {
            return null;
        }

        if ($hasPerson && $hasName) {
            return 'Provide either person_id or visitor_name, not both.';
        }

        if ($hasPerson || $hasName) {
            return null;
        }

        return 'Each attendee must be identified by person_id, recorded by name, or explicitly marked anonymous.';
    }

    /**
     * Determine the walk-in snapshot for an attendance row AT WRITE TIME.
     *
     * "Walk-in" means the attendee had no prior registration for this
     * occurrence. It is deliberately computed once, when the row is written,
     * and stored: rosters change after an event, and the register must keep
     * reporting what was true on the day rather than what is true now.
     *
     *   - Activity-linked register: not on the activity's planned roster.
     *     Anyone with no Person record cannot have been on the roster.
     *   - Standalone roll call: there is no planned roster, so only an attendee
     *     with no Person record at all is a walk-in.
     */
    private function resolveIsWalkIn(AttendanceRegister $register, ?int $personId): bool
    {
        if ($register->activity_id === null) {
            return $personId === null;
        }

        if ($personId === null) {
            return true;
        }

        return ! ActivityParticipant::where('activity_id', $register->activity_id)
            ->where('person_id', $personId)
            ->exists();
    }

    /**
     * Submit an attendance register.
     *
     * POST /api/v1/attendance-registers/{register}/submit
     */
    public function submit(Request $request, AttendanceRegister $register): JsonResponse
    {
        $this->authorize('update', $register);

        if ($register->status !== 'open') {
            return response()->json([
                'message' => 'Only open registers can be submitted.',
            ], 403);
        }

        $register->update([
            'status' => 'submitted',
            'submitted_by_user_id' => $request->user()->id,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Attendance register submitted successfully.',
            'register' => $register->fresh(),
        ]);
    }

    /**
     * Reopen a submitted register for correction.
     *
     * POST /api/v1/attendance-registers/{register}/reopen
     */
    public function reopen(Request $request, AttendanceRegister $register): JsonResponse
    {
        $this->authorize('update', $register);

        if ($register->status === 'verified') {
            return response()->json([
                'message' => 'Verified registers cannot be reopened.',
            ], 403);
        }

        $register->update([
            'status' => 'open',
            'submitted_by_user_id' => null,
            'submitted_at' => null,
        ]);

        return response()->json([
            'message' => 'Attendance register reopened successfully.',
            'register' => $register->fresh(),
        ]);
    }

    /**
     * Verify an attendance register.
     *
     * POST /api/v1/attendance-registers/{register}/verify
     *
     * Verification is the supervisory sign-off that closes a register: once
     * verified it can no longer be reopened through this workflow, so the
     * figures that flow into reports stay stable. It is gated on
     * attendance.verify rather than attendance.submit — recording attendance
     * and certifying it are different responsibilities, held by different roles.
     */
    public function verify(Request $request, AttendanceRegister $register): JsonResponse
    {
        $this->authorize('verify', $register);

        if (! in_array($register->status, ['open', 'submitted'], true)) {
            return response()->json([
                'message' => 'Only an open or submitted register can be verified.',
            ], 403);
        }

        $register->update([
            'status' => 'verified',
            'verified_by_user_id' => $request->user()->id,
            'verified_at' => now(),
        ]);

        return response()->json([
            'message' => 'Attendance register verified successfully.',
            'register' => $register->fresh(),
        ]);
    }

    /**
     * Reconcile site attendance against activity attendance for one register's
     * site and day.
     *
     * GET /api/v1/attendance-registers/{register}/reconciliation
     *
     * WHAT THIS ANSWERS
     *   "Everyone who came to the site is accounted for, and everyone recorded
     *    against an activity is visible to the Site Secretary."
     *
     * HOW IT MATCHES — AND WHAT IT REFUSES TO DO
     *   People are matched ONLY by permanent person_id. Names are never used:
     *   two people called "Grace" are two people, and one person recorded as
     *   "Grace N." at the site and "Grace Nakato" at an activity is one person
     *   only if both rows carry the same person_id.
     *
     *   Anonymous rows and named-but-unidentified rows are never matched and
     *   never merged. They are reported as separate counts, because the register
     *   deliberately does not know who they are.
     *
     * WHAT IT NEVER DOES
     *   - It never writes. Reconciliation is a pure read of source records, so
     *     it is idempotent, cannot create duplicate reconciliation rows, and
     *     cannot mutate the registers it compares.
     *   - It never inserts a missing person into the site register. Adding the
     *     person is a deliberate act, taken by the Site Secretary through the
     *     ordinary entries endpoint.
     *   - It never infers absence. A person recorded at the site with no
     *     activity entry is reported as `not_recorded`, not `absent` — they may
     *     simply have joined no activity, or the activity register may not have
     *     been filled in yet.
     *
     * UNRECONCILED CHANGES
     *   If a site register has already been submitted or verified and an
     *   activity register for the same day holds identifiable people who are not
     *   on it, those people are surfaced as UNRECONCILED rather than silently
     *   written into the submitted register. The Site Secretary decides whether
     *   to reopen and add them.
     */
    public function reconciliation(AttendanceRegister $register): JsonResponse
    {
        $this->authorize('view', $register);

        $date = $register->register_date->toDateString();

        // Every register for this site on this day — the site-level registers
        // (activity_id null) and the activity registers.
        $siblings = AttendanceRegister::with(['activity', 'entries'])
            ->where('campaign_id', $register->campaign_id)
            ->where('mission_site_id', $register->mission_site_id)
            ->whereDate('register_date', $date)
            ->get();

        $siteRegisters = $siblings->whereNull('activity_id');
        $activityRegisters = $siblings->whereNotNull('activity_id');

        // ── Index identifiable attendance on both sides by permanent person id ─
        $sitePersonIds = [];
        foreach ($siteRegisters as $siteRegister) {
            foreach ($siteRegister->entries as $entry) {
                if ($entry->person_id !== null) {
                    $sitePersonIds[$entry->person_id] = true;
                }
            }
        }

        /** @var array<int, array<int, array<string, mixed>>> $activityByPerson */
        $activityByPerson = [];
        foreach ($activityRegisters as $activityRegister) {
            foreach ($activityRegister->entries as $entry) {
                if ($entry->person_id === null) {
                    continue;
                }
                $activityByPerson[$entry->person_id][] = [
                    'register_id' => $activityRegister->id,
                    'activity_id' => $activityRegister->activity_id,
                    'activity_title' => $activityRegister->activity?->title,
                    'register_status' => $activityRegister->status,
                ];
            }
        }

        // ── Build one row per identifiable person seen anywhere that day ───────
        $personIds = array_unique(array_merge(
            array_keys($sitePersonIds),
            array_keys($activityByPerson),
        ));

        $peopleById = Person::whereIn('id', $personIds)->get()->keyBy('id');

        $people = [];
        foreach ($personIds as $personId) {
            $inSite = isset($sitePersonIds[$personId]);
            $inActivities = $activityByPerson[$personId] ?? [];

            $people[] = [
                'person_id' => (int) $personId,
                'name' => $peopleById->get($personId)?->full_name,
                'in_site_register' => $inSite,
                'in_activity_registers' => $inActivities,
                // 'not_recorded' is deliberate: a site attendee with no activity
                // entry is not "absent" — nobody has recorded them either way.
                'activity_attendance' => $inActivities === [] ? 'not_recorded' : 'recorded',
                'state' => $inSite
                    ? ($inActivities === [] ? 'site_only' : 'site_and_activity')
                    : 'activity_only',
            ];
        }

        // Identifiable activity attendees missing from the site register.
        $missingFromSite = array_values(array_filter(
            $people,
            fn (array $row) => $row['state'] === 'activity_only',
        ));

        // ── Unmatchable rows: counted, never merged ────────────────────────────
        $siteUnmatched = $this->countUnmatchedEntries($siteRegisters->flatMap->entries);
        $activityUnmatched = $this->countUnmatchedEntries($activityRegisters->flatMap->entries);

        // Unreconciled: a completed site register is affected by later activity
        // entries that it does not contain. History is never rewritten to hide this.
        $siteClosed = $siteRegisters->contains(
            fn (AttendanceRegister $r) => in_array($r->status, ['submitted', 'verified'], true)
        );
        $isUnreconciled = $siteClosed && $missingFromSite !== [];

        return response()->json([
            'register' => [
                'id' => $register->id,
                'activity_id' => $register->activity_id,
                'status' => $register->status,
                'register_date' => $date,
                'mission_site_id' => (int) $register->mission_site_id,
            ],
            'register_date' => $date,
            'site_registers' => $siteRegisters->map(fn (AttendanceRegister $r) => [
                'id' => $r->id,
                'name' => $r->register_name,
                'status' => $r->status,
            ])->values(),
            'activity_registers' => $activityRegisters->map(fn (AttendanceRegister $r) => [
                'id' => $r->id,
                'activity_id' => $r->activity_id,
                'activity_title' => $r->activity?->title,
                'status' => $r->status,
            ])->values(),
            'people' => $people,
            'missing_from_site_register' => $missingFromSite,
            'unmatched' => [
                'site' => $siteUnmatched,
                'activity' => $activityUnmatched,
            ],
            'unreconciled' => [
                'is_unreconciled' => $isUnreconciled,
                'pending_count' => count($missingFromSite),
                'message' => $isUnreconciled
                    ? 'The site register was already submitted, but later activity records include identifiable people who are not on it. Reopen the register if they should be added.'
                    : null,
            ],
        ]);
    }

    /**
     * Read-only attendance summaries, in the caller's authorised scopes.
     *
     * GET /api/v1/attendance-registers/summary
     * ?campaign_id=&mission_site_id=&activity_id=&date_from=&date_to=&status=
     *
     * NO DOUBLE COUNTING
     *   Two independent register types exist — site attendance and activity
     *   attendance — and the same person legitimately appears in both. They are
     *   therefore reported as SEPARATE totals that are never added together.
     *   Summing them would count a person twice and misstate the mission.
     *
     * Counts are also broken down by identity form (identified, named but
     * unidentified, anonymous), and by walk-in arrival, so a reader can see how
     * much of the figure rests on named records.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRegister::class);

        $request->validate([
            'campaign_id' => ['sometimes', 'integer', 'exists:campaigns,id'],
            'mission_site_id' => ['sometimes', 'integer', 'exists:mission_sites,id'],
            'activity_id' => ['sometimes', 'integer', 'exists:activities,id'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:open,submitted,verified'],
        ]);

        // attendance.submit is included because the seeded missionary can record
        // attendance but holds no view code; they may still read aggregated
        // figures for the scopes they can reach.
        $scopes = $this->auth->getAuthorisedScopes(
            $request->user(),
            ['attendance.view', 'attendance.submit']
        );

        $query = AttendanceRegister::with(['entries', 'activity']);
        $this->auth->scopeQuery($query, $scopes, 'campaign_id', 'mission_site_id');

        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->campaign_id);
        }
        if ($request->filled('mission_site_id')) {
            $query->where('mission_site_id', $request->mission_site_id);
        }
        if ($request->filled('activity_id')) {
            $query->where('activity_id', $request->activity_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('register_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('register_date', '<=', $request->date_to);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $registers = $query->get();

        $siteTotals = $this->zeroSummary();
        $activityTotals = $this->zeroSummary();
        $byActivity = [];

        foreach ($registers as $register) {
            $counts = $this->countEntryForms($register->entries);

            if ($register->activity_id === null) {
                $siteTotals['registers']++;
                foreach ($counts as $key => $value) {
                    $siteTotals[$key] += $value;
                }

                continue;
            }

            $activityTotals['registers']++;
            foreach ($counts as $key => $value) {
                $activityTotals[$key] += $value;
            }

            $activityId = (int) $register->activity_id;
            if (! isset($byActivity[$activityId])) {
                $byActivity[$activityId] = [
                    'activity_id' => $activityId,
                    'title' => $register->activity?->title,
                    ...$this->zeroSummary(),
                ];
            }
            $byActivity[$activityId]['registers']++;
            foreach ($counts as $key => $value) {
                $byActivity[$activityId][$key] += $value;
            }
        }

        return response()->json([
            'filters' => [
                'campaign_id' => $request->input('campaign_id'),
                'mission_site_id' => $request->input('mission_site_id'),
                'activity_id' => $request->input('activity_id'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'status' => $request->input('status'),
            ],
            'site_attendance' => $siteTotals,
            'activity_attendance' => $activityTotals,
            'by_activity' => array_values($byActivity),
            // Stated in the payload itself so no consumer invents a combined
            // "total attendance" by adding two numbers that overlap.
            'note' => 'Site attendance and activity attendance are independent records and are never added together.',
        ]);
    }

    // ── Summary / reconciliation helpers ─────────────────────────────────────

    /**
     * An all-zero summary accumulator. Keys mirror what the endpoint returns so
     * aggregation and response shape cannot drift apart.
     *
     * @return array<string, int>
     */
    private function zeroSummary(): array
    {
        return [
            'registers' => 0,
            'entries' => 0,
            'identified' => 0,
            'named_unidentified' => 0,
            'anonymous' => 0,
            'walk_in' => 0,
            'first_time' => 0,
            'decisions_recorded' => 0,
        ];
    }

    /**
     * Count one register's entries by identity form and arrival snapshot.
     *
     * @param  Collection<int, AttendanceEntry>  $entries
     * @return array<string, int>
     */
    private function countEntryForms($entries): array
    {
        $counts = $this->zeroSummary();
        unset($counts['registers']);

        foreach ($entries as $entry) {
            $counts['entries']++;

            if ($entry->person_id !== null) {
                $counts['identified']++;
            } elseif ($entry->is_anonymous) {
                $counts['anonymous']++;
            } else {
                $counts['named_unidentified']++;
            }

            if ($entry->is_walk_in) {
                $counts['walk_in']++;
            }
            if ($entry->is_first_time) {
                $counts['first_time']++;
            }
            if ($entry->decision_made !== null) {
                $counts['decisions_recorded']++;
            }
        }

        return $counts;
    }

    /**
     * Count the rows that reconciliation cannot match by identity — anonymous
     * and named-but-unidentified attendances — on one side of the comparison.
     *
     * @param  Collection<int, AttendanceEntry>  $entries
     * @return array<string, int>
     */
    private function countUnmatchedEntries($entries): array
    {
        $anonymous = 0;
        $named = 0;

        foreach ($entries as $entry) {
            if ($entry->person_id !== null) {
                continue;
            }
            if ($entry->is_anonymous) {
                $anonymous++;
            } else {
                $named++;
            }
        }

        return [
            'anonymous' => $anonymous,
            'named_unidentified' => $named,
        ];
    }
}
