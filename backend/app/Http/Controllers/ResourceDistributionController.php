<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\Resource;
use App\Models\ResourceDistributionRequest;
use App\Models\Team;
use App\Services\AuthorizationService;
use App\Services\ResourceDistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourceDistributionController extends Controller
{
    public function __construct(
        private AuthorizationService $auth,
        private ResourceDistributionService $distribution
    ) {}

    public function targets(): JsonResponse
    {
        $root = OrgUnit::where('short_code', 'UUM')->first();
        if (! $root) {
            return response()->json(['organizational_units' => [], 'campaigns' => [], 'sites' => [], 'teams' => []]);
        }
        $unitIds = $this->orgUnitSubtreeIds((int) $root->id);
        $campaigns = Campaign::with('leadOrgUnit')->whereIn('lead_org_unit_id', $unitIds)->orderBy('name')->get();
        $sites = MissionSite::with('campaign')->whereIn('campaign_id', $campaigns->modelKeys())->orderBy('name')->get();
        $teams = Team::with('campaign', 'site')->whereIn('campaign_id', $campaigns->modelKeys())->orderBy('name')->get();

        return response()->json([
            'organizational_units' => OrgUnit::with('type')->whereIn('id', $unitIds)->orderBy('name')->get(['id', 'parent_id', 'org_unit_type_id', 'name', 'short_code']),
            'campaigns' => $campaigns->map(fn (Campaign $campaign) => ['id' => $campaign->id, 'name' => $campaign->name, 'org_unit' => $campaign->leadOrgUnit?->name]),
            'sites' => $sites->map(fn (MissionSite $site) => ['id' => $site->id, 'name' => $site->name, 'campaign_id' => $site->campaign_id]),
            'teams' => $teams->map(fn (Team $team) => ['id' => $team->id, 'name' => $team->name, 'campaign_id' => $team->campaign_id, 'site_id' => $team->mission_site_id]),
            'audience_types' => ['union', 'conference', 'mission_field', 'zone', 'church_district', 'local_church', 'campaign', 'site', 'team', 'private'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->distribution->visibleRequestsQuery($request->user());
        if ($request->filled('status')) {
            $request->validate(['status' => ['string', Rule::in(['pending_review', 'returned', 'rejected', 'approved', 'revoked', 'superseded'])]]);
            $query->where('status', $request->string('status')->toString());
        }
        $page = $request->validate(['per_page' => ['nullable', 'integer', 'between:5,100']]);

        return response()->json($query->orderByDesc('submitted_at')->paginate($page['per_page'] ?? 20));
    }

    public function store(Request $request, Resource $resource): JsonResponse
    {
        abort_unless((int) $resource->created_by_user_id === (int) $request->user()->id, 403);
        $this->authorize('distribute', $resource);
        $data = $request->validate([
            'audience_type' => ['required', 'string', Rule::in(['union', 'conference', 'mission_field', 'zone', 'church_district', 'local_church', 'campaign', 'site', 'team', 'private'])],
            'target_org_unit_id' => ['nullable', 'integer', 'exists:org_units,id'],
            'target_campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'target_site_id' => ['nullable', 'integer', 'exists:mission_sites,id'],
            'target_team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'recipient_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'supersedes_request_id' => ['nullable', 'integer', 'exists:resource_distribution_requests,id'],
        ]);
        $supersedes = isset($data['supersedes_request_id']) ? ResourceDistributionRequest::findOrFail($data['supersedes_request_id']) : null;

        return response()->json($this->distribution->submitRequest($resource, $request->user(), $data, $supersedes), 201);
    }

    public function resubmit(Request $request, ResourceDistributionRequest $distributionRequest): JsonResponse
    {
        return response()->json($this->distribution->resubmit($distributionRequest, $request->user()));
    }

    public function decide(Request $request, ResourceDistributionRequest $distributionRequest): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approved', 'rejected', 'returned'])],
            'reason' => ['required_unless:action,approved', 'nullable', 'string', 'max:4000'],
        ]);

        // Approving, rejecting, or returning a distribution request requires
        // approve_distribute authority at the request's audience scope.
        if (! $this->auth->canApproveDistribute($request->user(), $distributionRequest)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json($this->distribution->decide($distributionRequest, $request->user(), $data['action'], $data['reason'] ?? null));
    }

    public function revoke(Request $request, ResourceDistributionRequest $distributionRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:4000']]);

        return response()->json($this->distribution->revoke($distributionRequest, $request->user(), $data['reason']));
    }

    /** @return array<int, int> */
    private function orgUnitSubtreeIds(int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier !== []) {
            $children = OrgUnit::whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->diff($ids)->all();
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return $ids;
    }
}
