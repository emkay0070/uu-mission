<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\Campaign;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ActivityParticipantController — the PLANNED ROSTER for an activity.
 *
 * DOMAIN POSITION
 * ---------------
 * ActivityParticipant answers "who do we expect / intend to take part in this
 * activity?". It is NOT attendance. Attendance is the AttendanceEntry, written
 * against an attendance register occurrence, and the two are deliberately kept
 * apart:
 *
 *   - A person on the roster may never turn up (planned, not present).
 *   - A person who turns up may never have been on the roster (a walk-in).
 *
 * A register's participants view merges both sources and reports
 * `is_on_roster` and `is_present` independently, so neither list can silently
 * overwrite the other.
 *
 * AUTHORISATION
 * -------------
 * Everything is authorised against the ACTIVITY through ActivityPolicy, so the
 * campaign/site/team boundaries are resolved by AuthorizationService exactly as
 * they are for the activity itself:
 *
 *   read  (index)   → activities.view  on the activity
 *   write (store /  → activities.update on the activity, plus the caller must
 *          destroy)    already be able to see the person they are adding
 */
class ActivityParticipantController extends Controller
{
    /**
     * List the planned roster for an activity.
     *
     * GET /api/v1/campaigns/{campaign}/activities/{activity}/participants
     */
    public function index(Campaign $campaign, Activity $activity): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('view', $activity);

        $participants = ActivityParticipant::where('activity_id', $activity->id)
            ->with(['person.outreachContact'])
            ->orderBy('joined_date')
            ->get()
            ->map(fn (ActivityParticipant $p) => [
                'id'          => $p->id,
                'person_id'   => $p->person_id,
                'name'        => $p->person?->full_name,
                'phone'       => $p->person?->phone,
                'village'     => $p->person?->outreachContact?->village,
                'joined_date' => $p->joined_date?->toDateString(),
                'status'      => $p->status,
                'notes'       => $p->notes,
            ]);

        return response()->json($participants);
    }

    /**
     * Add a person to the planned roster.
     *
     * POST /api/v1/campaigns/{campaign}/activities/{activity}/participants
     * body: { person_id: 12, joined_date?: "2026-11-05", status?, notes? }
     */
    public function store(Request $request, Campaign $campaign, Activity $activity): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('update', $activity);

        $validated = $request->validate([
            'person_id'   => ['required', 'integer', 'exists:people,id'],
            'joined_date' => ['sometimes', 'nullable', 'date'],
            'status'      => ['sometimes', 'string', 'in:active,inactive,completed'],
            'notes'       => ['sometimes', 'nullable', 'string'],
        ]);

        // Never trust a client-supplied id on its own: the person must be one
        // the caller is already entitled to see.
        $person = Person::findOrFail($validated['person_id']);
        $this->authorize('view', $person);

        $participant = ActivityParticipant::firstOrCreate(
            [
                'activity_id' => $activity->id,
                'person_id'   => $person->id,
            ],
            [
                'joined_date' => $validated['joined_date'] ?? now()->toDateString(),
                'status'      => $validated['status'] ?? 'active',
                'notes'       => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'id'          => $participant->id,
            'person_id'   => $participant->person_id,
            'name'        => $person->full_name,
            'joined_date' => $participant->joined_date?->toDateString(),
            'status'      => $participant->status,
            'notes'       => $participant->notes,
        ], $participant->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Remove a person from the planned roster.
     *
     * DELETE /api/v1/campaigns/{campaign}/activities/{activity}/participants/{participant}
     *
     * The roster is a PLAN. It may be corrected while the activity is still
     * scheduled or under way, but it is not rewritten after the fact — once the
     * activity has completed or been cancelled the plan is history, and any
     * attendance actually recorded is preserved independently on the register.
     */
    public function destroy(Campaign $campaign, Activity $activity, ActivityParticipant $participant): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('update', $activity);

        if ((int) $participant->activity_id !== (int) $activity->id) {
            abort(404, 'Participant not found within this activity.');
        }

        if (! in_array($activity->status, ['scheduled', 'in_progress'], true)) {
            return response()->json([
                'message' => 'The planned roster cannot be changed once the activity has finished.',
            ], 422);
        }

        $participant->delete();

        return response()->json(['message' => 'Participant removed from the planned roster.']);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function validateActivityScope(Campaign $campaign, Activity $activity): void
    {
        if ((int) $activity->campaign_id !== (int) $campaign->id) {
            abort(404, 'Activity not found within this campaign.');
        }
    }
}
