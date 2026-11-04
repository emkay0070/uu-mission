<?php

namespace App\Http\Controllers;

use App\Models\OutreachEncounter;
use App\Models\OutreachContact;
use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\Person;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutreachEncounterController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List outreach encounters for a person.
     *
     * GET /api/v1/people/{person}/encounters
     */
    public function index(Request $request, Person $person): JsonResponse
    {
        $this->authorize('view', $person);

        $encounters = $person->outreachEncounters()
            ->with(['campaign', 'site', 'method', 'recordedBy', 'person', 'stageBefore', 'stageAfter'])
            ->orderBy('encounter_date', 'desc')
            ->get();

        return response()->json($encounters);
    }

    /**
     * Create a new outreach encounter.
     *
     * POST /api/v1/outreach-encounters
     * body: { person_id, campaign_id, mission_site_id?, encounter_method_id?, encounter_date, notes? }
     */
    public function store(Request $request): JsonResponse
    {
        // Convert empty strings to null before validation
        $fields = ['mission_site_id', 'encounter_method_id', 'notes'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                if ($value === '' || $value === null) {
                    $request->merge([$field => null]);
                }
            }
        }

        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
            'mission_site_id' => ['sometimes', 'nullable', 'integer', 'exists:mission_sites,id'],
            'encounter_method_id' => ['sometimes', 'nullable', 'integer', 'exists:encounter_methods,id'],
            'encounter_date' => ['required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'stage_after_encounter_id' => ['sometimes', 'nullable', 'integer', 'exists:engagement_stages,id'],
            'next_action' => ['sometimes', 'nullable', 'string'],
            'follow_up_date' => ['sometimes', 'nullable', 'date'],
            'assigned_follow_up_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $this->authorize('create', OutreachEncounter::class);

        // Verify the user is authorised for this campaign/site using the
        // single authorization engine.
        $campaign = Campaign::findOrFail($validated['campaign_id']);
        $site     = isset($validated['mission_site_id'])
            ? MissionSite::findOrFail($validated['mission_site_id'])
            : null;

        if ($site && (int) $site->campaign_id !== (int) $campaign->id) {
            return response()->json([
                'message' => 'The selected mission site does not belong to the selected campaign.',
            ], 422);
        }

        $authorised = $site
            ? $this->auth->hasPermissionForSite($request->user(), 'outreach-contacts.create', $site)
            : $this->auth->hasPermissionForCampaign($request->user(), 'outreach-contacts.create', $campaign);

        if (! $authorised) {
            return response()->json([
                'message' => 'You are not authorised to record encounters for this campaign/site.',
            ], 403);
        }

        // Verify the assigned follow-up user is authorised for this campaign/site.
        if (! empty($validated['assigned_follow_up_user_id'])) {
            $assignedUser = User::find($validated['assigned_follow_up_user_id']);

            $assignedAuthorised = $assignedUser && ($site
                ? $this->auth->hasPermissionForSite(
                    $assignedUser,
                    ['outreach-contacts.view', 'outreach-contacts.create', 'outreach-contacts.edit'],
                    $site
                )
                : $this->auth->hasPermissionForCampaign(
                    $assignedUser,
                    ['outreach-contacts.view', 'outreach-contacts.create', 'outreach-contacts.edit'],
                    $campaign
                ));

            if (! $assignedAuthorised) {
                return response()->json([
                    'message' => 'The assigned user is not authorised for this campaign/site.',
                ], 403);
            }
        }

        // Verify person is accessible
        $person = Person::findOrFail($validated['person_id']);
        $this->authorize('view', $person);

        // Find the outreach contact for this person in this campaign
        $outreachContact = OutreachContact::where('person_id', $person->id)
            ->where('campaign_id', $validated['campaign_id'])
            ->first();
        
        if (!$outreachContact) {
            return response()->json([
                'message' => 'This person does not have an outreach contact record for this campaign. Please register them as an outreach contact first.',
            ], 422);
        }

        // Wrap in transaction for atomicity
        return DB::transaction(function () use ($validated, $request, $outreachContact) {
            // Capture the stage before the encounter
            $stageBeforeId = $outreachContact->engagement_stage_id;

            // Create the encounter
            $encounter = OutreachEncounter::create([
                ...$validated,
                'stage_before_encounter_id' => $stageBeforeId,
                'recorded_by_user_id' => $request->user()->id,
            ]);

            // Handle stage transition if stage_after_encounter_id is provided
            // Make it idempotent: only transition if the stage is actually different
            if (isset($validated['stage_after_encounter_id']) && $validated['stage_after_encounter_id']) {
                if ($outreachContact->engagement_stage_id !== $validated['stage_after_encounter_id']) {
                    $outreachContact->transitionEngagementStage(
                        $validated['stage_after_encounter_id'],
                        $request->user()->id,
                        $encounter->id
                    );
                }
            }

            return response()->json($encounter->load(['campaign', 'site', 'method', 'recordedBy', 'stageBefore', 'stageAfter']), 201);
        });
    }

    /**
     * Show a specific outreach encounter.
     *
     * GET /api/v1/outreach-encounters/{encounter}
     */
    public function show(OutreachEncounter $encounter): JsonResponse
    {
        $this->authorize('view', $encounter->person);

        $encounter->load(['campaign', 'site', 'method', 'recordedBy', 'person']);

        return response()->json($encounter);
    }

    /**
     * Mark a follow-up as completed.
     *
     * POST /api/v1/outreach-encounters/{encounter}/complete
     * body: { completion_notes? }
     */
    public function complete(Request $request, OutreachEncounter $encounter): JsonResponse
    {
        $this->authorize('update', $encounter->person);

        if (!$encounter->follow_up_date) {
            return response()->json([
                'message' => 'This encounter does not have a follow-up date.',
            ], 422);
        }

        if ($encounter->completed_at) {
            return response()->json([
                'message' => 'This follow-up has already been completed.',
            ], 422);
        }

        $validated = $request->validate([
            'completion_notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $encounter->update([
            'completed_at' => now(),
            'completed_by_user_id' => $request->user()->id,
            'completion_notes' => $validated['completion_notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Follow-up marked as completed.',
            'encounter' => $encounter->fresh(['completedBy']),
        ]);
    }

    /**
     * Reschedule a follow-up.
     *
     * POST /api/v1/outreach-encounters/{encounter}/reschedule
     * body: { follow_up_date, next_action? }
     */
    public function reschedule(Request $request, OutreachEncounter $encounter): JsonResponse
    {
        $this->authorize('update', $encounter->person);

        if (!$encounter->follow_up_date) {
            return response()->json([
                'message' => 'This encounter does not have a follow-up date.',
            ], 422);
        }

        $validated = $request->validate([
            'follow_up_date' => ['required', 'date'],
            'next_action' => ['sometimes', 'nullable', 'string'],
        ]);

        $encounter->update([
            'follow_up_date' => $validated['follow_up_date'],
            'next_action' => $validated['next_action'] ?? $encounter->next_action,
            // Reset completion if rescheduling
            'completed_at' => null,
            'completed_by_user_id' => null,
            'completion_notes' => null,
        ]);

        return response()->json([
            'message' => 'Follow-up rescheduled.',
            'encounter' => $encounter->fresh(),
        ]);
    }
}
