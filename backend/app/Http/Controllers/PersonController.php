<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * PersonController — registration and management of people encountered during mission work.
 *
 * ACCESS MODEL
 * ------------
 * A Person carries no campaign/site column of its own (those fields were
 * removed from the `people` table). A person's operational "location" is
 * therefore derived from the records that link them into the mission work:
 *
 *   - attendance entries    → attendance_entries.register (campaign + site)
 *   - activity participation → activity_participants.activity (campaign + site)
 *   - outreach contact      → outreach_contacts (campaign + first site)
 *
 * Every list and duplicate check resolves the caller's ACTUAL scope through
 * AuthorizationService (the single source of truth) and constrains the query
 * to exactly those scopes. No permission is ever treated as globally
 * available: a user without an active, authorised assignment sees only the
 * people they personally registered.
 */
class PersonController extends Controller
{
    public function __construct(
        private AuthorizationService $auth,
        private MediaService $mediaService
    ) {}

    /**
     * List people with optional filtering and search.
     *
     * GET /api/v1/people
     * ?search=john
     * ?campaign_id=1
     * ?mission_site_id=2
     * ?per_page=20
     *
     * The result set is always constrained to the caller's authorised scopes
     * (plus the people they personally registered). A user with no operational
     * assignment therefore sees only their own registrations.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Person::class);

        $request->validate([
            'search'          => ['sometimes', 'string', 'max:100'],
            'campaign_id'     => ['sometimes', 'integer', 'exists:campaigns,id'],
            'mission_site_id' => ['sometimes', 'integer', 'exists:mission_sites,id'],
            'per_page'        => ['sometimes', 'integer', 'between:5,100'],
        ]);

        // Resolve the caller's real scope for the People module. This is a
        // union of (campaign, site) pairs — never a global grant. The
        // outreach-contacts.* codes also satisfy the People module because the
        // People & Follow-up workspace is outreach-driven and the seeded
        // missionary role holds no people.* codes.
        $scopes = $this->auth->getAuthorisedScopes(
            $request->user(),
            ['people.view', 'outreach-contacts.view']
        );

        $query = Person::with([
            'activityParticipations.activity',
            'attendanceEntries.register',
            'outreachContact',
        ]);

        // Only people the caller registered, or who are linked to one of the
        // caller's authorised scopes, are visible.
        $this->applyVisiblePeopleScope($query, $request->user(), $scopes);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function (Builder $q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        // Optional narrowing to a specific campaign and/or site. The location
        // filter uses the same operational linkages as the visibility scope so
        // it never hides a person the caller is otherwise allowed to see.
        if ($request->filled('campaign_id') || $request->filled('mission_site_id')) {
            $this->applyLocationFilter(
                $query,
                $request->filled('campaign_id') ? (int) $request->input('campaign_id') : null,
                $request->filled('mission_site_id') ? (int) $request->input('mission_site_id') : null,
            );
        }

        $people = $query->orderBy('created_at', 'desc')
                       ->paginate($request->input('per_page', 20));

        return response()->json($people);
    }

    /**
     * Register a new person.
     *
     * POST /api/v1/people
     * body: { first_name, last_name, gender?, phone?, ... , campaign_id?, mission_site_id? }
     *
     * Registering a person is an IDENTITY action only. It deliberately does NOT
     * create an activity roster entry, an attendance record, an outreach
     * contact or an outreach encounter. Attendance is recorded against a
     * register, the planned roster is managed through the activity participant
     * endpoints, and outreach is a separate deliberate workflow.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Person::class);

        // Convert empty strings to null before validation
        $fields = ['first_name', 'last_name', 'gender', 'phone', 'date_of_birth', 'address', 'occupation', 'national_id_type', 'national_id_number'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                if ($value === '' || $value === null) {
                    $request->merge([$field => null]);
                }
            }
        }

        $validated = $request->validate([
            'first_name'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender'           => ['sometimes', 'nullable', 'string', 'in:male,female,unspecified'],
            'date_of_birth'    => ['sometimes', 'nullable', 'date', 'before:today'],
            'phone'            => ['sometimes', 'nullable', 'string', 'max:30'],
            'address'         => ['sometimes', 'nullable', 'string'],
            'occupation'      => ['sometimes', 'nullable', 'string'],
            'national_id_type'=> ['sometimes', 'nullable', 'string'],
            'national_id_number'=>['sometimes', 'nullable', 'string'],
            'campaign_id'      => ['sometimes', 'integer', 'exists:campaigns,id'],
            'mission_site_id'  => ['sometimes', 'integer', 'exists:mission_sites,id'],
        ]);

        // Ensure at least one name is provided
        if (empty($validated['first_name']) && empty($validated['last_name'])) {
            return response()->json(['message' => 'At least one name (first or last) is required'], 422);
        }


        // If campaign_id or mission_site_id provided, verify the caller holds a
        // create permission IN THAT ACTUAL SCOPE.
        if ($request->filled('campaign_id') || $request->filled('mission_site_id')) {
            $site = $request->filled('mission_site_id')
                ? MissionSite::find($request->input('mission_site_id'))
                : null;

            // When only a site is supplied, derive the campaign from the site
            // so the caller is not forced to send both identifiers.
            $campaign = $request->filled('campaign_id')
                ? Campaign::find($request->input('campaign_id'))
                : $site?->campaign;

            if (! $campaign) {
                return response()->json([
                    'message' => 'Campaign not found.',
                ], 404);
            }

            // The site must belong to the campaign it is being registered under.
            if ($site && (int) $site->campaign_id !== (int) $campaign->id) {
                return response()->json([
                    'message' => 'The selected mission site does not belong to the selected campaign.',
                ], 422);
            }

            // people.create and outreach-contacts.create both authorise the
            // People & Follow-up workspace, which is outreach driven.
            $permissions = ['people.create', 'outreach-contacts.create'];

            $authorised = $site
                ? $this->auth->hasPermissionForSite($request->user(), $permissions, $site)
                : $this->auth->hasPermissionForCampaign($request->user(), $permissions, $campaign);

            if (! $authorised) {
                return response()->json([
                    'message' => 'You are not authorised to register people for this campaign/site.',
                ], 403);
            }
        }

        // campaign_id / mission_site_id are contextual inputs used only for the
        // scope check above — they are not Person columns.
        $person = Person::create([
            ...Arr::except($validated, ['campaign_id', 'mission_site_id']),
            'registered_by_user_id' => $request->user()->id,
        ]);

        return response()->json($person, 201);
    }

    /**
     * Check for duplicate persons based on name and phone.
     *
     * GET /api/v1/people/check-duplicates
     * ?first_name=John
     * ?last_name=Doe
     * ?phone=1234567890
     *
     * Requires the same view permission as the people list AND is constrained
     * to the caller's authorised scopes, so it can never be used to probe for
     * people outside the caller's mission scope.
     */
    public function checkDuplicates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Person::class);

        $validated = $request->validate([
            'first_name' => ['required', 'string'],
            'last_name' => ['required', 'string'],
            'phone' => ['sometimes', 'string'],
        ]);

        $scopes = $this->auth->getAuthorisedScopes(
            $request->user(),
            ['people.view', 'outreach-contacts.view']
        );

        $query = Person::query();
        $this->applyVisiblePeopleScope($query, $request->user(), $scopes);

        $query->where(function (Builder $q) use ($validated) {
            // Name match (both names must match)
            $q->where(function (Builder $name) use ($validated) {
                $name->where('first_name', 'ilike', $validated['first_name'])
                     ->where('last_name', 'ilike', $validated['last_name']);
            });

            // ... OR an exact phone match, when a phone was supplied. Kept
            // inside the group so it cannot escape the scope constraint above.
            if (! empty($validated['phone'])) {
                $q->orWhere('phone', $validated['phone']);
            }
        });

        $duplicates = $query->with(['outreachEncounters' => function ($q) {
            $q->orderBy('encounter_date', 'desc')->limit(1);
        }])->limit(10)->get();

        return response()->json([
            'duplicates' => $duplicates,
            'count' => $duplicates->count(),
        ]);
    }

    /**
     * Show a person with their participation and attendance history.
     *
     * GET /api/v1/people/{person}
     */
    public function show(Person $person): JsonResponse
    {
        $this->authorize('view', $person);

        $person->load([
            'activityParticipations.activity',
            'activityParticipations.activity.campaign',
            'activityParticipations.activity.site',
            'attendanceEntries.register',
            'attendanceEntries.register.activity',
            'outreachContact',
            'outreachContact.engagementStage',
            'outreachContact.engagementStageHistory' => function ($q) {
                $q->orderBy('changed_at', 'desc')->with(['fromStage', 'toStage', 'changedBy', 'outreachEncounter']);
            },
        ]);

        return response()->json($person->toArray());
    }

    /**
     * Update person details.
     *
     * PATCH /api/v1/people/{person}
     */
    public function update(Request $request, Person $person): JsonResponse
    {
        $this->authorize('update', $person);

        $validated = $request->validate([
            'first_name'       => ['sometimes', 'string', 'max:100'],
            'last_name'        => ['sometimes', 'string', 'max:100'],
            'gender'           => ['sometimes', 'string', 'in:male,female,unspecified'],
            'date_of_birth'    => ['sometimes', 'date', 'before:today'],
            'phone'           => ['sometimes', 'string', 'max:30'],
            'address'         => ['sometimes', 'string'],
            'occupation'      => ['sometimes', 'string'],
            'national_id_type'=> ['sometimes', 'string'],
            'national_id_number'=>['sometimes', 'string'],
        ]);


        $person->update($validated);

        return response()->json($person->fresh());
    }

    /**
     * Upload profile photo for a person.
     *
     * POST /api/v1/people/{person}/photo
     */
    public function uploadPhoto(Request $request, Person $person): JsonResponse
    {
        $this->authorize('update', $person);

        $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:10240'], // Max 10MB
        ]);

        try {
            $media = $this->mediaService->upload($request->file('file'), [
                'title' => "{$person->full_name} Profile Photo",
                'visibility' => 'private',
                'uploaded_by_user_id' => $request->user()->id,
            ]);

            // Attach media to person as profile photo
            $person->update(['media_id' => $media->id]);

            return response()->json($media, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove profile photo from a person.
     *
     * DELETE /api/v1/people/{person}/photo
     */
    public function removePhoto(Request $request, Person $person): JsonResponse
    {
        $this->authorize('update', $person);

        $person->update(['media_id' => null]);

        return response()->json(['message' => 'Profile photo removed']);
    }

    /**
     * Upload cover photo for a person.
     *
     * POST /api/v1/people/{person}/cover-photo
     */
    public function uploadCoverPhoto(Request $request, Person $person): JsonResponse
    {
        $this->authorize('update', $person);

        $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:10240'], // Max 10MB
        ]);

        try {
            $media = $this->mediaService->upload($request->file('file'), [
                'title' => "{$person->full_name} Cover Photo",
                'visibility' => 'private',
                'uploaded_by_user_id' => $request->user()->id,
            ]);

            // Attach media to person as cover photo
            $person->update(['cover_photo_media_id' => $media->id]);

            return response()->json($media, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove cover photo from a person.
     *
     * DELETE /api/v1/people/{person}/cover-photo
     */
    public function removeCoverPhoto(Request $request, Person $person): JsonResponse
    {
        $this->authorize('update', $person);

        $person->update(['cover_photo_media_id' => null]);

        return response()->json(['message' => 'Cover photo removed']);
    }

    // ── Scope helpers ─────────────────────────────────────────────────────────

    /**
     * Constrain a Person query to the records the caller may actually see:
     * the people they personally registered, PLUS people linked operationally
     * to one of the caller's authorised (campaign, site) scopes.
     *
     * A global scope pair (null/null) means "no constraint" and returns early.
     * An empty scope list fails closed: only the caller's own registrations
     * remain visible.
     *
     * @param  array<int, array{campaign_id: int|null, site_id: int|null}>  $scopes
     */
    private function applyVisiblePeopleScope(Builder $query, User $user, array $scopes): void
    {
        if ($this->hasUnrestrictedScope($scopes)) {
            return;
        }

        $query->where(function (Builder $q) use ($user, $scopes) {
            $q->where('registered_by_user_id', $user->id);

            if ($scopes === []) {
                return; // fail closed — no operational scope
            }

            $q->orWhereHas('attendanceEntries.register', function (Builder $r) use ($scopes) {
                $this->auth->scopeQuery($r, $scopes, 'campaign_id', 'mission_site_id');
            })->orWhereHas('activityParticipations.activity', function (Builder $r) use ($scopes) {
                $this->auth->scopeQuery($r, $scopes, 'campaign_id', 'mission_site_id');
            })->orWhereHas('outreachContact', function (Builder $r) use ($scopes) {
                $this->auth->scopeQuery($r, $scopes, 'campaign_id', 'first_encountered_site_id');
            });
        });
    }

    /**
     * Narrow a Person query to those linked to a specific campaign and/or site.
     *
     * Uses every operational linkage so a person found only through their
     * outreach contact is not hidden when the caller filters by campaign.
     */
    private function applyLocationFilter(Builder $query, ?int $campaignId, ?int $siteId): void
    {
        // relation, campaign column, site column for each operational linkage.
        $linkages = [
            ['attendanceEntries.register', 'campaign_id', 'mission_site_id'],
            ['activityParticipations.activity', 'campaign_id', 'mission_site_id'],
            ['outreachContact', 'campaign_id', 'first_encountered_site_id'],
        ];

        $query->where(function (Builder $q) use ($linkages, $campaignId, $siteId) {
            foreach ($linkages as [$relation, $campaignColumn, $siteColumn]) {
                $q->orWhereHas($relation, function (Builder $r) use ($campaignId, $siteId, $campaignColumn, $siteColumn) {
                    if ($campaignId !== null) {
                        $r->where($campaignColumn, $campaignId);
                    }
                    if ($siteId !== null) {
                        $r->where($siteColumn, $siteId);
                    }
                });
            }
        });
    }

    /**
     * Whether the scope list contains a global (unrestricted) entry.
     *
     * @param  array<int, array{campaign_id: int|null, site_id: int|null}>  $scopes
     */
    private function hasUnrestrictedScope(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($scope['campaign_id'] === null && $scope['site_id'] === null) {
                return true;
            }
        }

        return false;
    }
}
