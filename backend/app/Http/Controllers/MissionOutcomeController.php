<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Campaign;
use App\Models\MissionOutcome;
use App\Models\MissionSite;
use App\Models\OutcomeType;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MissionOutcomeController — records and manages spiritual outcomes.
 *
 * Outcomes are individually traceable spiritual events (decisions, baptisms,
 * bible study requests). Each has its own follow-up workflow.
 *
 * Design principles:
 * - A person_id OR visitor_name must always be present (DB CHECK enforces this).
 * - Outcomes can reference the activity or attendance register they came from.
 * - follow_up_status drives the follow-up assignment workflow.
 * - Recording is authorised against the specific site (see store()), so a
 *   site-scoped missionary may record at their own site while the campaign
 *   scope remains intact for campaign-level users.
 */
class MissionOutcomeController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List outcomes for a campaign with filters.
     *
     * GET /api/v1/campaigns/{campaign}/outcomes
     * ?outcome_type=decision|baptism|bible_study_req|church_joined|follow_up_needed
     * ?site_id=5
     * ?follow_up_status=pending|assigned|completed|lost_contact
     *
     * The result set is narrowed to the user's authorised outcome-visibility
     * scopes: a campaign-scoped user keeps the whole campaign, while a site- or
     * team-scoped user sees only outcomes belonging to their own site. A
     * sibling site's outcomes are never returned.
     */
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('viewAny', [MissionOutcome::class, $campaign]);

        $request->validate([
            'outcome_type'    => ['sometimes', 'string'],
            'site_id'         => ['sometimes', 'integer'],
            'follow_up_status'=> ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::FOLLOW_UP_STATUSES)],
        ]);

        // Use a query builder (not the HasMany relation) so scopeQuery can
        // apply the user's authorised outcome-visibility scopes. The
        // campaign_id constraint is equivalent to $campaign->outcomes() but
        // keeps a Builder type.
        $query = MissionOutcome::query()
            ->where('campaign_id', $campaign->id)
            ->with(['site', 'person', 'activity', 'recordedBy.person', 'followUpAssignedTo']);

        // outcomes.record counts as read access here so a missionary can list
        // the outcomes recorded at their own site (they hold outcomes.record
        // but not outcomes.view). This mirrors AttendanceController, which
        // admits attendance.submit alongside attendance.view. Row visibility is
        // still narrowed to the user's authorised (campaign, site) pairs below.
        $this->auth->scopeQuery(
            $query,
            $this->auth->getAuthorisedScopes($request->user(), ['outcomes.view', 'outcomes.record']),
            'campaign_id',
            'mission_site_id'
        );

        if ($request->filled('outcome_type')) {
            $query->where('outcome_type', $request->outcome_type);
        }

        if ($request->filled('site_id')) {
            $query->where('mission_site_id', $request->site_id);
        }

        if ($request->filled('follow_up_status')) {
            $query->where('follow_up_status', $request->follow_up_status);
        }

        return response()->json($query->orderByDesc('outcome_date')->get());
    }

    /**
     * Record a new outcome.
     *
     * POST /api/v1/campaigns/{campaign}/outcomes
     *
     * Authorisation is site-aware: the coarse create gate confirms the user
     * holds outcomes.record somewhere, then the supplied site is validated to
     * belong to the campaign and authorised directly. This lets a site-scoped
     * missionary record at their own site, while campaign/org_unit/global
     * scopes keep working exactly as before.
     */
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('create', MissionOutcome::class);

        $validated = $request->validate([
            'mission_site_id'     => ['required', 'integer'],
            'outcome_type'        => ['required', 'string', 'in:'.WorkflowDefinitions::validationIn(OutcomeType::activeCodes())],
            'outcome_date'        => ['required', 'date'],
            // Person identification (at least one required — validated below)
            'person_id'           => ['sometimes', 'nullable', 'integer', 'exists:people,id'],
            'visitor_name'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'visitor_phone'       => ['sometimes', 'nullable', 'string', 'max:30'],
            'visitor_gender'      => ['sometimes', 'nullable', 'string', 'in:male,female,unspecified'],
            'visitor_age'         => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            // Source traceability (optional)
            'activity_id'              => ['sometimes', 'nullable', 'integer', 'exists:activities,id'],
            'attendance_register_id'   => ['sometimes', 'nullable', 'integer', 'exists:attendance_registers,id'],
            'notes'               => ['sometimes', 'nullable', 'string'],
        ]);

        // Application-layer enforcement of the DB CHECK constraint
        if (empty($validated['person_id']) && empty($validated['visitor_name'])) {
            return response()->json([
                'message' => 'At least one of person_id or visitor_name must be provided.',
            ], 422);
        }

        // Resolve the target site and confirm it belongs to this campaign (422),
        // then authorise recording against that specific site (403). A site-scoped
        // missionary can only record at their own site; campaign-level access is
        // unchanged. This mirrors AttendanceController::store().
        $site = $this->resolveSiteInCampaign($campaign, $validated['mission_site_id']);

        if (! $this->auth->hasPermissionForSite($request->user(), 'outcomes.record', $site)) {
            return response()->json([
                'message' => 'You are not authorised to record outcomes for this site.',
            ], 403);
        }

        $outcome = $campaign->outcomes()->create([
            ...$validated,
            'follow_up_status'   => 'pending',
            'recorded_by_user_id'=> $request->user()->id,
        ]);

        return response()->json(
            $outcome->load(['person', 'site', 'activity']),
            201
        );
    }

    /**
     * Show a single outcome.
     *
     * GET /api/v1/campaigns/{campaign}/outcomes/{outcome}
     */
    public function show(Campaign $campaign, MissionOutcome $outcome): JsonResponse
    {
        $this->validateOutcomeScope($campaign, $outcome);
        $this->authorize('view', $outcome);

        return response()->json(
            $outcome->load(['person', 'site', 'activity', 'attendanceRegister',
                            'followUpAssignedTo', 'recordedBy.person'])
        );
    }

    /**
     * Assign follow-up to a person.
     *
     * POST /api/v1/campaigns/{campaign}/outcomes/{outcome}/follow-up
     */
    public function assignFollowUp(Request $request, Campaign $campaign, MissionOutcome $outcome): JsonResponse
    {
        $this->validateOutcomeScope($campaign, $outcome);
        $this->authorize('assignFollowUp', $outcome);

        $validated = $request->validate([
            'follow_up_assigned_to_person_id' => ['required', 'integer', 'exists:people,id'],
            'follow_up_due_date'              => ['sometimes', 'nullable', 'date'],
            'follow_up_notes'                 => ['sometimes', 'nullable', 'string'],
        ]);

        $outcome->update([
            ...$validated,
            'follow_up_status' => 'assigned',
        ]);

        return response()->json([
            'message' => 'Follow-up assigned.',
            'outcome' => $outcome->fresh(['followUpAssignedTo']),
        ]);
    }

    /**
     * Mark follow-up as complete.
     *
     * POST /api/v1/campaigns/{campaign}/outcomes/{outcome}/complete-follow-up
     */
    public function completeFollowUp(Request $request, Campaign $campaign, MissionOutcome $outcome): JsonResponse
    {
        $this->validateOutcomeScope($campaign, $outcome);
        $this->authorize('completeFollowUp', $outcome);

        $request->validate([
            'result' => ['required', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::FOLLOW_UP_RESULTS)],
            'notes'  => ['sometimes', 'nullable', 'string'],
        ]);

        $outcome->update([
            'follow_up_status'         => $request->result,
            'follow_up_completed_date' => now()->toDateString(),
            'follow_up_notes'          => $request->input('notes', $outcome->follow_up_notes),
        ]);

        return response()->json([
            'message' => 'Follow-up status updated.',
            'outcome' => $outcome->fresh(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validateOutcomeScope(Campaign $campaign, MissionOutcome $outcome): void
    {
        if ($outcome->campaign_id !== $campaign->id) {
            abort(404, 'Outcome not found within this campaign.');
        }
    }

    /**
     * Resolve a site id within a campaign, aborting with 422 when the site does
     * not belong to that campaign. Returns the resolved site so the caller can
     * authorise against its concrete scope without a second lookup.
     */
    private function resolveSiteInCampaign(Campaign $campaign, int $siteId): MissionSite
    {
        $site = MissionSite::findOrFail($siteId);

        if ((int) $site->campaign_id !== (int) $campaign->id) {
            abort(422, "Site {$siteId} does not belong to campaign {$campaign->id}.");
        }

        return $site;
    }
}
