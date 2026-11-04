<?php

namespace App\Http\Controllers;

use App\Models\BaptismRecord;
use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\Person;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BaptismRecordController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List baptism records for a person.
     *
     * GET /api/v1/people/{person}/baptisms
     */
    public function index(Request $request, Person $person): JsonResponse
    {
        $this->authorize('view', $person);

        $records = $person->baptismRecords()
            ->with(['campaign', 'site', 'officiatedBy', 'verifiedBy'])
            ->orderBy('baptism_date', 'desc')
            ->get();

        return response()->json($records);
    }

    /**
     * Create a new baptism record.
     *
     * POST /api/v1/baptism-records
     * body: { person_id, campaign_id?, mission_site_id?, baptism_date, location?, officiated_by_person_id?, notes? }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'campaign_id' => ['sometimes', 'integer', 'exists:campaigns,id'],
            'mission_site_id' => ['sometimes', 'integer', 'exists:mission_sites,id'],
            'baptism_date' => ['required', 'date'],
            'location' => ['sometimes', 'string'],
            'officiated_by_person_id' => ['sometimes', 'integer', 'exists:people,id'],
            'notes' => ['sometimes', 'string'],
        ]);

        // Verify person is accessible
        $person = Person::findOrFail($validated['person_id']);
        $this->authorize('update', $person);

        // Verify campaign/site authorisation if provided, using the single
        // authorization engine rather than legacy campaign_assignments rows.
        if (isset($validated['campaign_id'])) {
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
                ? $this->auth->hasPermissionForSite($request->user(), 'outcomes.record', $site)
                : $this->auth->hasPermissionForCampaign($request->user(), 'outcomes.record', $campaign);

            if (! $authorised) {
                return response()->json([
                    'message' => 'You are not authorised to record baptisms for this campaign/site.',
                ], 403);
            }
        }

        $record = BaptismRecord::create([
            ...$validated,
            'verification_status' => 'pending',
        ]);

        return response()->json($record->load(['campaign', 'site', 'officiatedBy']), 201);
    }

    /**
     * Verify a baptism record.
     *
     * POST /api/v1/baptism-records/{record}/verify
     * body: { status: 'verified'|'rejected', notes? }
     */
    public function verify(Request $request, BaptismRecord $record): JsonResponse
    {
        $this->authorize('view', $record->person);

        $validated = $request->validate([
            'status' => ['required', 'in:verified,rejected'],
            'notes' => ['sometimes', 'string'],
        ]);

        $record->update([
            'verification_status' => $validated['status'],
            'verified_by_user_id' => $request->user()->id,
            'verified_at' => now(),
            'notes' => $validated['notes'] ?? $record->notes,
        ]);

        return response()->json($record->load(['campaign', 'site', 'officiatedBy', 'verifiedBy']));
    }
}
