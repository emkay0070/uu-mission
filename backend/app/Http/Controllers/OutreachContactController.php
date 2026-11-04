<?php

namespace App\Http\Controllers;

use App\Models\OutreachContact;
use App\Models\OutreachEncounter;
use App\Models\Person;
use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\EngagementStage;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutreachContactController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List outreach contacts with optional filtering.
     *
     * GET /api/v1/outreach-contacts
     *
     * The result set is constrained to the user's authorised (campaign, site)
     * scopes for the outreach-contacts module — a user never sees contacts
     * that fall outside the assignments they actually hold.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OutreachContact::class);

        $request->validate([
            'engagement_stage' => ['sometimes', 'string', 'exists:engagement_stages,code'],
            'campaign_id' => ['sometimes', 'integer', 'exists:campaigns,id'],
            'mission_site_id' => ['sometimes', 'integer', 'exists:mission_sites,id'],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:5,100'],
        ]);

        $query = OutreachContact::with(['person', 'engagementStage']);

        // Filter by engagement stage code
        if ($request->filled('engagement_stage')) {
            $query->whereHas('engagementStage', function ($q) use ($request) {
                $q->where('code', $request->engagement_stage);
            });
        }

        // Filter by campaign
        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->campaign_id);
        }

        // Filter by active status
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // Search by person name
        if ($request->filled('search')) {
            $query->whereHas('person', function ($q) use ($request) {
                $q->where('first_name', 'ilike', "%{$request->search}%")
                  ->orWhere('last_name', 'ilike', "%{$request->search}%");
            });
        }

        // Scope to the user's authorised campaigns/sites.
        // A contact is scoped by its campaign, and — for site-level assignments
        // — by the site where the person was first encountered.
        $scopes = $this->auth->getAuthorisedScopes(
            $request->user(),
            ['outreach-contacts.view', 'people.view']
        );
        $this->auth->scopeQuery($query, $scopes, 'campaign_id', 'first_encountered_site_id');

        $perPage = $request->input('per_page', 20);
        $contacts = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($contacts);
    }

    /**
     * Create a new outreach contact.
     *
     * POST /api/v1/outreach-contacts
     * body: { person_id?, first_name, last_name, engagement_stage, village?, date_registered?, notes?, campaign_id?, mission_site_id? }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'person_id' => ['sometimes', 'nullable', 'integer', 'exists:people,id'],
            'first_name' => ['required_without:person_id', 'string', 'max:100'],
            'last_name' => ['required_without:person_id', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'preferred_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,unspecified'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email'],
            'engagement_stage' => ['required', 'string', 'exists:engagement_stages,code'],
            'village' => ['sometimes', 'nullable', 'string', 'max:200'],
            'date_registered' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
            'mission_site_id' => ['required', 'integer', 'exists:mission_sites,id'],
            // Initial encounter data (optional)
            'encounter_date' => ['sometimes', 'nullable', 'date'],
            'encounter_method_id' => ['sometimes', 'nullable', 'integer', 'exists:encounter_methods,id'],
            'encounter_notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // Verify the user is authorised to register outreach contacts for
        // this campaign/site, using the single authorization engine.
        $campaign = Campaign::findOrFail($validated['campaign_id']);
        $site     = MissionSite::findOrFail($validated['mission_site_id']);

        if ((int) $site->campaign_id !== (int) $campaign->id) {
            return response()->json([
                'message' => 'The selected mission site does not belong to the selected campaign.',
            ], 422);
        }

        if (! $this->auth->hasPermissionForSite($request->user(), 'outreach-contacts.create', $site)) {
            return response()->json([
                'message' => 'You are not authorised to register outreach contacts for this campaign/site.',
            ], 403);
        }

        return DB::transaction(function () use ($validated, $request) {
            // Get or create person
            if (isset($validated['person_id'])) {
                $person = Person::findOrFail($validated['person_id']);
                
                // Check if outreach contact already exists for this person in this campaign
                $existingContact = OutreachContact::where('person_id', $person->id)
                    ->where('campaign_id', $validated['campaign_id'])
                    ->first();
                
                if ($existingContact) {
                    return response()->json([
                        'message' => 'This person already has an outreach contact record for this campaign.',
                    ], 422);
                }
            } else {
                $person = Person::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'middle_name' => $validated['middle_name'] ?? null,
                    'preferred_name' => $validated['preferred_name'] ?? null,
                    'gender' => $validated['gender'] ?? null,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'nationality' => $validated['nationality'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'registered_by_user_id' => $request->user()->id,
                ]);
            }

            // Get engagement stage ID
            $stage = EngagementStage::where('code', $validated['engagement_stage'])->first();
            if (!$stage) {
                return response()->json([
                    'message' => 'Engagement stage not found.',
                ], 404);
            }

            // Create outreach contact
            $contact = OutreachContact::create([
                'person_id' => $person->id,
                'engagement_stage_id' => $stage->id,
                'village' => $validated['village'] ?? null,
                'date_registered' => $validated['date_registered'] ?? now()->toDateString(),
                'notes' => $validated['notes'] ?? null,
                'campaign_id' => $validated['campaign_id'] ?? null,
                'first_encountered_campaign_id' => $validated['campaign_id'] ?? null,
                'first_encountered_site_id' => $validated['mission_site_id'] ?? null,
                'is_active' => true,
            ]);

            // Create initial engagement stage history
            $contact->engagementStageHistory()->create([
                'outreach_contact_id' => $contact->id,
                'person_id' => $person->id,
                'from_stage_id' => null,
                'to_stage_id' => $stage->id,
                'changed_by_user_id' => $request->user()->id,
                'changed_at' => now(),
                'is_correction' => false,
            ]);

            // Create initial encounter if data is provided
            if (isset($validated['encounter_date'])) {
                $encounter = OutreachEncounter::create([
                    'person_id' => $person->id,
                    'campaign_id' => $validated['campaign_id'],
                    'mission_site_id' => $validated['mission_site_id'],
                    'encounter_method_id' => $validated['encounter_method_id'] ?? null,
                    'encounter_date' => $validated['encounter_date'],
                    'notes' => $validated['encounter_notes'] ?? null,
                    'stage_before_encounter_id' => null, // First encounter, no previous stage
                    'stage_after_encounter_id' => $stage->id, // Initial stage
                    'recorded_by_user_id' => $request->user()->id,
                ]);

                // Link the engagement stage history to this encounter
                $contact->engagementStageHistory()->update([
                    'outreach_encounter_id' => $encounter->id,
                ]);
            }

            return response()->json($contact->load(['person', 'engagementStage']), 201);
        });
    }

    /**
     * Show a specific outreach contact.
     *
     * GET /api/v1/outreach-contacts/{id}
     */
    public function show(OutreachContact $contact): JsonResponse
    {
        $this->authorize('view', $contact);

        $contact->load([
            'person',
            'engagementStage',
            'engagementStageHistory' => function ($q) {
                $q->orderBy('changed_at', 'desc')->with(['fromStage', 'toStage', 'changedBy', 'outreachEncounter']);
            },
            'outreachEncounters' => function ($q) {
                $q->orderBy('encounter_date', 'desc')->with(['campaign', 'site', 'method', 'recordedBy']);
            },
            'baptismRecords' => function ($q) {
                $q->orderBy('baptism_date', 'desc')->with(['campaign', 'site', 'officiatedBy', 'verifiedBy']);
            },
        ]);

        return response()->json($contact);
    }

    /**
     * Update an outreach contact.
     *
     * PATCH /api/v1/outreach-contacts/{id}
     * body: { engagement_stage?, village?, notes?, is_active? }
     */
    public function update(Request $request, OutreachContact $contact): JsonResponse
    {
        $this->authorize('update', $contact);

        $validated = $request->validate([
            'engagement_stage' => ['sometimes', 'nullable', 'string', 'exists:engagement_stages,code'],
            'village' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Handle engagement stage transition
        if (isset($validated['engagement_stage'])) {
            if ($validated['engagement_stage'] === null) {
                // Don't allow nulling the engagement stage
                unset($validated['engagement_stage']);
            } else {
                $stage = EngagementStage::where('code', $validated['engagement_stage'])->first();
                if ($stage && $contact->engagement_stage_id !== $stage->id) {
                    $contact->transitionEngagementStage($stage->id, $request->user()->id);
                }
                unset($validated['engagement_stage']);
            }
        }

        $contact->update($validated);

        return response()->json($contact->load(['person', 'engagementStage']));
    }

    /**
     * Delete an outreach contact.
     *
     * DELETE /api/v1/outreach-contacts/{id}
     */
    public function destroy(OutreachContact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);

        $contact->delete();

        return response()->json(['message' => 'Outreach contact deleted.']);
    }
}
