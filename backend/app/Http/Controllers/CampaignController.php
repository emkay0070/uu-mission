<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\MediaAttachment;
use App\Models\MissionSite;
use App\Models\OutcomeType;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CampaignController — CRUD and lifecycle for campaigns.
 *
 * All responses are scope-filtered: a user only sees campaigns within
 * their authorization scope. Full policy enforcement comes via
 * CampaignPolicy (separate). Here we implement the data layer.
 *
 * Campaigns are NEVER deleted — they are archived (status = 'archived').
 */
class CampaignController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List campaigns with optional filtering.
     *
     * GET /api/v1/campaigns
     * ?status=active|planning|completed|archived
     * ?programme_id=1
     * ?search=lira
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Campaign::class);

        $request->validate([
            'status' => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::CAMPAIGN_STATUSES)],
            'programme_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:5,100'],
        ]);

        $query = Campaign::with(['programme.type', 'leadOrgUnit'])
            ->withCount(['sites', 'teams', 'assignments']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('programme_id')) {
            $query->where('programme_id', $request->programme_id);
        }

        if ($request->filled('search')) {
            $query->where('name', 'ilike', "%{$request->search}%");
        }

        $campaigns = $query->orderByDesc('start_date')
            ->paginate($request->input('per_page', 20));

        return response()->json($campaigns);
    }

    /**
     * Create a new campaign.
     *
     * POST /api/v1/campaigns
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Campaign::class);

        $validated = $request->validate([
            'programme_id' => ['required', 'integer', 'exists:programmes,id'],
            'lead_org_unit_id' => ['required', 'integer', 'exists:org_units,id'],
            'name' => ['required', 'string', 'max:250'],
            'description' => ['sometimes', 'string'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
        ]);

        $campaign = Campaign::create([
            ...$validated,
            'status' => 'planning',
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(
            $campaign->load(['programme.type', 'leadOrgUnit', 'objectives']),
            201
        );
    }

    /**
     * Show a single campaign.
     *
     * GET /api/v1/campaigns/{campaign}
     *
     * Two levels of visibility are distinguished:
     *
     *  - Full: the user holds campaign-wide scope (global, org_unit or
     *    campaign). The complete campaign is returned, exactly as before.
     *
     *  - Context-only: the user's access is confined to a site or team inside
     *    the campaign (e.g. a site-scoped missionary). They receive the minimum
     *    context needed to operate — the campaign's identity plus only the
     *    sites they are authorised to access. Objectives, target areas and
     *    campaign-wide counts describe the whole campaign and are withheld.
     *
     * Authorisation is the viewContext ability; the strict view() ability
     * (campaign-wide only) still governs consumers such as the objectives
     * listing, so this does not broaden access to campaign contents anywhere
     * else.
     */
    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('viewContext', $campaign);

        if (! $this->auth->canViewCampaign($request->user(), $campaign)) {
            return response()->json($this->limitedCampaignContext($request->user(), $campaign));
        }

        $campaign->load([
            'programme.type',
            'leadOrgUnit',
            'objectives',
            'targetAreas',
            'sites' => fn ($q) => $q->withCount(['teams', 'assignments', 'activities']),
        ]);

        $campaign->loadCount(['teams', 'assignments', 'outcomes', 'fieldReports']);

        return response()->json($campaign);
    }

    /**
     * Build the reduced campaign payload for context-only visibility.
     *
     * Only the campaign's identity and the sites the user is authorised to
     * access are exposed. The authorised-site set is resolved on the backend
     * from the user's active assignments (campaign, site and team scopes),
     * honouring validity dates and revocation — the client is never asked to
     * derive it from raw scopes.
     */
    private function limitedCampaignContext(User $user, Campaign $campaign): array
    {
        $campaign->load(['programme.type', 'leadOrgUnit']);

        // Use a query builder (not the HasMany relation) so scopeQuery can
        // apply the user's authorised sites. The campaign_id constraint is
        // equivalent to $campaign->sites() but keeps a Builder type.
        $sitesQuery = MissionSite::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('name');
        $this->auth->scopeQuery(
            $sitesQuery,
            $this->auth->getAuthorisedScopes($user, null),
            'campaign_id',
            'id'
        );

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'description' => $campaign->description,
            'status' => $campaign->status,
            'start_date' => $campaign->start_date?->toIso8601String(),
            'end_date' => $campaign->end_date?->toIso8601String(),
            'access_level' => 'limited',
            'programme' => $campaign->programme ? [
                'id' => $campaign->programme->id,
                'name' => $campaign->programme->name,
                'type' => $campaign->programme->type ? [
                    'code' => $campaign->programme->type->code,
                    'label' => $campaign->programme->type->label,
                ] : null,
            ] : null,
            'lead_org_unit' => $campaign->leadOrgUnit ? [
                'id' => $campaign->leadOrgUnit->id,
                'name' => $campaign->leadOrgUnit->name,
            ] : null,
            'sites' => $sitesQuery->get([
                'id', 'campaign_id', 'name', 'description',
                'status', 'location_description',
            ])->values(),
        ];
    }

    /**
     * Update campaign details.
     *
     * PATCH /api/v1/campaigns/{campaign}
     */
    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:250'],
            'description' => ['sometimes', 'nullable', 'string'],
            'lead_org_unit_id' => ['sometimes', 'integer', 'exists:org_units,id'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $campaign->update($validated);

        return response()->json($campaign->fresh(['programme.type', 'leadOrgUnit']));
    }

    /**
     * Transition campaign status through its lifecycle.
     *
     * POST /api/v1/campaigns/{campaign}/transition
     * body: { "to": "active" }
     *
     * Valid transitions:
     *   planning → active
     *   active   → completed
     *   completed → archived
     */
    public function transition(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('transition', $campaign);

        $request->validate([
            'to' => ['required', 'string', 'in:'.WorkflowDefinitions::validationIn(array_merge(...array_values(WorkflowDefinitions::CAMPAIGN_TRANSITIONS)))],
        ]);

        $allowed = WorkflowDefinitions::CAMPAIGN_TRANSITIONS[$campaign->status] ?? [];

        if (! in_array($request->to, $allowed)) {
            return response()->json([
                'message' => "Cannot transition from '{$campaign->status}' to '{$request->to}'.",
                'allowed' => $allowed,
            ], 422);
        }

        $campaign->update(['status' => $request->to]);

        return response()->json([
            'message' => "Campaign transitioned to '{$request->to}'.",
            'campaign' => $campaign->fresh(),
        ]);
    }

    /**
     * Campaign dashboard statistics.
     *
     * GET /api/v1/campaigns/{campaign}/stats
     */
    public function stats(Campaign $campaign): JsonResponse
    {
        $this->authorize('viewStats', $campaign);

        // Load counts for static assignment statuses
        $campaign->loadCount([
            'sites',
            'teams',
            'assignments',
            'assignments as confirmed_assignments' => fn ($q) => $q->where('status', 'confirmed'),
            'assignments as arrived_assignments' => fn ($q) => $q->where('status', 'arrived'),
            'fieldReports',
            'fieldReports as pending_reports' => fn ($q) => $q->where('status', 'draft'),
            'outcomes',
        ]);

        // Load outcome counts dynamically from the outcome_types reference table
        // so newly added types appear automatically without a code change.
        $activeOutcomeTypes = OutcomeType::where('is_active', true)
            ->where('include_in_stats', true)
            ->orderBy('sort_order')
            ->get(['code', 'label']);

        $outcomeCounts = [];
        foreach ($activeOutcomeTypes as $ot) {
            $outcomeCounts[$ot->code] = $campaign->outcomes()->where('outcome_type', $ot->code)->count();
        }

        // Aggregate report stats across all sites
        $reportTotals = $campaign->fieldReports()
            ->selectRaw('SUM(stat_meetings_held) as total_meetings,
                         SUM(stat_total_attendance) as total_attendance,
                         SUM(stat_decisions) as total_decisions,
                         SUM(stat_baptisms) as total_baptisms,
                         SUM(stat_bible_studies_requested) as total_bible_studies')
            ->first();

        return response()->json([
            'campaign_id' => $campaign->id,
            'status' => $campaign->status,
            'sites_count' => $campaign->sites_count,
            'teams_count' => $campaign->teams_count,
            'missionaries' => [
                'total' => $campaign->assignments_count,
                'confirmed' => $campaign->confirmed_assignments,
                'arrived' => $campaign->arrived_assignments,
            ],
            'reports' => [
                'total' => $campaign->field_reports_count,
                'pending' => $campaign->pending_reports,
            ],
            'outcomes' => [
                'total' => $campaign->outcomes_count,
                'by_type' => $outcomeCounts,
            ],
            'aggregated_from_reports' => [
                'meetings_held' => (int) $reportTotals->total_meetings,
                'total_attendance' => (int) $reportTotals->total_attendance,
                'decisions' => (int) $reportTotals->total_decisions,
                'baptisms' => (int) $reportTotals->total_baptisms,
                'bible_studies' => (int) $reportTotals->total_bible_studies,
            ],
        ]);
    }

    /**
     * Attach media to campaign.
     *
     * POST /api/v1/campaigns/{campaign}/media
     */
    public function attachMedia(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        $request->validate([
            'media_uuid' => ['required', 'string', 'exists:media,uuid'],
            'attachment_type' => ['sometimes', 'string', 'max:50'],
        ]);

        $media = Media::where('uuid', $request->media_uuid)->firstOrFail();

        // A manager may only attach media they are allowed to see; otherwise
        // the entity route could be used to pull an unknown media record into
        // their own scope.
        $this->authorize('view', $media);

        // Consent validation: cannot attach media requiring consent to public contexts if consent not obtained
        if ($media->requires_consent && ! $media->consent_obtained) {
            return response()->json([
                'message' => 'Media requiring consent cannot be attached until consent is obtained.',
            ], 422);
        }

        $attachment = MediaAttachment::firstOrCreate([
            'media_id' => $media->id,
            'attachable_type' => Campaign::class,
            'attachable_id' => $campaign->id,
            'attachment_type' => $request->attachment_type ?? 'general',
        ], [
            'attached_by_user_id' => $request->user()->id,
        ]);

        return response()->json($attachment, 201);
    }

    /**
     * Detach media from campaign.
     *
     * DELETE /api/v1/campaigns/{campaign}/media/{media_uuid}
     */
    public function detachMedia(Request $request, Campaign $campaign, string $media_uuid): JsonResponse
    {
        $this->authorize('update', $campaign);

        $media = Media::where('uuid', $media_uuid)->firstOrFail();

        // Only uploader can detach their media
        if ($media->uploaded_by_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $campaign->mediaAttachments()
            ->where('media_id', $media->id)
            ->delete();

        return response()->json(['message' => 'Media detached']);
    }
}
