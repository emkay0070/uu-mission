<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\Resource;
use App\Models\ResourceDistributionDecision;
use App\Models\ResourceDistributionRelease;
use App\Models\ResourceDistributionRequest;
use App\Models\ResourceVersion;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResourceDistributionService
{
    private const ORG_AUDIENCES = [
        'conference' => 'conference',
        'mission_field' => 'mission_field',
        'zone' => 'zone',
        'church_district' => 'district',
        'local_church' => 'local_church',
    ];

    public function __construct(private AuthorizationService $authorization) {}

    public function contributionAssignment(User $user): ?UserRoleAssignment
    {
        foreach ($this->authorization->getActiveAssignmentsGranting($user, 'resources.create') as $assignment) {
            [$scopeType, $scopeId] = $this->authorization->assignmentScope($assignment);
            if ($this->authorization->hasPermissionAtAssignmentScope($user, 'resources.create', $scopeType, $scopeId)) {
                return $assignment;
            }
        }

        return null;
    }

    public function hasContributionPermission(User $user): bool
    {
        return in_array('resources.create', $this->authorization->getEffectivePermissions($user), true);
    }

    public function canReview(User $user, ResourceDistributionRequest $request): bool
    {
        return $this->authorization->canApproveDistribute($user, $request);
    }

    public function canReviewResource(User $user, Resource $resource): bool
    {
        return $resource->distributionRequests()->get()->contains(fn (ResourceDistributionRequest $request) => $this->canReview($user, $request));
    }

    public function canEdit(User $user, Resource $resource): bool
    {
        if ($resource->isArchived()) {
            return false;
        }
        if ((int) $resource->created_by_user_id === (int) $user->id
            && in_array('resources.create', $this->authorization->getEffectivePermissions($user), true)) {
            return true;
        }

        return $resource->distributionRequests()->whereIn('status', ['pending_review', 'approved'])->get()
            ->contains(fn (ResourceDistributionRequest $request) => $this->canReviewTargetWithPermission($user, 'resources.manage', $request));
    }

    public function hasAnyReaderPermission(User $user): bool
    {
        foreach ($this->authorization->getActiveAssignmentsGranting($user, 'resources.view') as $assignment) {
            [$scopeType, $scopeId] = $this->authorization->assignmentScope($assignment);
            if ($this->authorization->hasPermissionAtAssignmentScope($user, 'resources.view', $scopeType, $scopeId)) {
                return true;
            }
        }

        return false;
    }

    public function canRead(User $user, Resource $resource, ?ResourceVersion $version = null): bool
    {
        if ($resource->status !== 'published' || $resource->archived_at !== null) {
            return false;
        }

        return $this->readableVersion($user, $resource, $version) !== null;
    }

    public function readableVersion(User $user, Resource $resource, ?ResourceVersion $requiredVersion = null): ?ResourceVersion
    {
        if ($resource->status !== 'published' || $resource->archived_at !== null) {
            return null;
        }
        $releases = $this->effectiveReleasesForResource($resource)->with(['request', 'version'])->orderByDesc('released_at')->orderByDesc('id')->get();
        foreach ($releases as $release) {
            if ($requiredVersion && (int) $release->resource_version_id !== (int) $requiredVersion->id) {
                continue;
            }
            $request = $release->request;
            if ($request && $this->userBelongsToAudience($user, $request)) {
                return $release->version;
            }
        }

        return null;
    }

    public function applyLibraryVisibility(Builder $query, User $user): Builder
    {
        $resourceIds = $this->effectiveReleasesQuery()
            ->with('request')
            ->get()
            ->filter(fn (ResourceDistributionRelease $release) => $release->request && $this->userBelongsToAudience($user, $release->request))
            ->pluck('resource_id')
            ->unique()
            ->values()
            ->all();

        if ($resourceIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('resources.id', $resourceIds)->where('resources.status', 'published')->whereNull('resources.archived_at');
    }

    public function visibleRequestsQuery(User $user): Builder
    {
        $query = ResourceDistributionRequest::query()->with(['resource.category', 'version', 'requester', 'decisions.actor']);

        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('requested_by_user_id', $user->id);
            $candidateIds = ResourceDistributionRequest::with(['resource.originAssignment'])
                ->whereIn('status', ['pending_review', 'returned', 'rejected', 'approved', 'revoked'])
                ->get()
                ->filter(fn (ResourceDistributionRequest $request) => $this->canReview($user, $request))
                ->modelKeys();
            if ($candidateIds !== []) {
                $visible->orWhereIn('id', $candidateIds);
            }
        });
    }

    public function submitRequest(Resource $resource, User $requester, array $audience, ?ResourceDistributionRequest $supersedes = null): ResourceDistributionRequest
    {
        $normalized = $this->normalizeAudience($audience);
        $versionId = (int) $resource->current_version_id;
        if (! $versionId || ! $resource->currentVersion) {
            throw ValidationException::withMessages(['resource' => 'Upload a resource file before submitting it for distribution.']);
        }
        $activeAudienceRelease = $this->hasEffectiveReleaseForAudience($resource, $normalized['audience_key']);
        if (($activeAudienceRelease && ! $supersedes) || ($supersedes && (
            (int) $supersedes->resource_id !== (int) $resource->id
            || $supersedes->audience_key !== $normalized['audience_key']
            || $supersedes->status !== 'approved'
            || ! $this->hasEffectiveReleaseForAudience($resource, $normalized['audience_key'])
        ))) {
            throw ValidationException::withMessages(['supersedes_request_id' => 'Select an active approved audience for this resource.']);
        }

        return DB::transaction(function () use ($resource, $requester, $normalized, $versionId, $supersedes): ResourceDistributionRequest {
            $request = ResourceDistributionRequest::create([
                'resource_id' => $resource->id,
                'requested_by_user_id' => $requester->id,
                ...$normalized,
                'resource_version_id' => $versionId,
                'supersedes_request_id' => $supersedes?->id,
                'status' => 'pending_review',
                'submitted_at' => now(),
            ]);
            $this->recordDecision($request, $versionId, $requester->id, $supersedes ? 'resubmitted' : 'submitted', null);
            if ($resource->status !== 'published') {
                $resource->update(['status' => 'pending_review', 'updated_by_user_id' => $requester->id]);
            }

            return $request->fresh(['resource', 'version', 'decisions']);
        });
    }

    public function resubmit(ResourceDistributionRequest $request, User $actor): ResourceDistributionRequest
    {
        if ((int) $request->requested_by_user_id !== (int) $actor->id || $request->status !== 'returned') {
            abort(403);
        }
        $resource = $request->resource;
        if (! $resource || ! $resource->current_version_id) {
            throw ValidationException::withMessages(['resource' => 'Upload a resource version before resubmitting.']);
        }

        return DB::transaction(function () use ($request, $actor, $resource): ResourceDistributionRequest {
            $request->update([
                'resource_version_id' => $resource->current_version_id,
                'status' => 'pending_review',
                'submitted_at' => now(),
                'decided_at' => null,
                'decided_by_user_id' => null,
                'decision_reason' => null,
            ]);
            $this->recordDecision($request, (int) $resource->current_version_id, $actor->id, 'resubmitted', null);
            if ($resource->status !== 'published') {
                $resource->update(['status' => 'pending_review', 'updated_by_user_id' => $actor->id]);
            }

            return $request->fresh(['resource', 'version', 'decisions']);
        });
    }

    public function decide(ResourceDistributionRequest $request, User $actor, string $action, ?string $reason): ResourceDistributionRequest
    {
        if ($request->status !== 'pending_review' || ! in_array($action, ['approved', 'rejected', 'returned'], true)) {
            throw ValidationException::withMessages(['status' => 'This distribution request is no longer awaiting review.']);
        }
        if (! $this->canReview($actor, $request)) {
            abort(403);
        }

        return DB::transaction(function () use ($request, $actor, $action, $reason): ResourceDistributionRequest {
            $request = ResourceDistributionRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending_review') {
                throw ValidationException::withMessages(['status' => 'This distribution request is no longer awaiting review.']);
            }
            $versionId = (int) $request->resource_version_id;
            $request->update([
                'status' => $action,
                'decided_at' => now(),
                'decided_by_user_id' => $actor->id,
                'decision_reason' => $reason,
            ]);
            $this->recordDecision($request, $versionId, $actor->id, $action, $reason);

            if ($action === 'approved') {
                ResourceDistributionRelease::create([
                    'resource_id' => $request->resource_id,
                    'resource_distribution_request_id' => $request->id,
                    'resource_version_id' => $versionId,
                    'audience_type' => $request->audience_type,
                    'audience_key' => $request->audience_key,
                    'released_by_user_id' => $actor->id,
                    'released_at' => now(),
                ]);
                $resource = Resource::whereKey($request->resource_id)->lockForUpdate()->firstOrFail();
                if ($resource->status !== 'archived' && $resource->status !== 'published') {
                    $resource->update([
                        'status' => 'published',
                        'published_at' => now(),
                        'published_by_user_id' => $actor->id,
                        'updated_by_user_id' => $actor->id,
                    ]);
                }
            } else {
                $resource = Resource::find($request->resource_id);
                if ($resource && $resource->status !== 'published' && ! $resource->distributionReleases()->exists()) {
                    $hasOtherPending = $resource->distributionRequests()->where('status', 'pending_review')->exists();
                    $resource->update(['status' => $hasOtherPending ? 'pending_review' : 'draft']);
                }
            }

            return $request->fresh(['resource', 'version', 'requester', 'decisions.actor', 'releases']);
        });
    }

    public function revoke(ResourceDistributionRequest $request, User $actor, string $reason): ResourceDistributionRequest
    {
        if ($request->status !== 'approved' || ! $this->canReview($actor, $request)) {
            abort(403);
        }

        return DB::transaction(function () use ($request, $actor, $reason): ResourceDistributionRequest {
            $request = ResourceDistributionRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'This distribution is not active.']);
            }
            $request->update(['status' => 'revoked', 'decided_at' => now(), 'decided_by_user_id' => $actor->id, 'decision_reason' => $reason]);
            ResourceDistributionRelease::where('resource_id', $request->resource_id)
                ->where('audience_key', $request->audience_key)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id, 'revocation_reason' => $reason, 'updated_at' => now()]);
            $this->recordDecision($request, (int) $request->resource_version_id, $actor->id, 'revoked', $reason);

            return $request->fresh(['resource', 'version', 'decisions.actor', 'releases']);
        });
    }

    public function canArchive(User $user, Resource $resource): bool
    {
        if ($this->authorization->hasGlobalPermission($user, 'resources.archive')) {
            return true;
        }
        $activeRequests = $this->effectiveReleasesForResource($resource)->with('request')->get()->pluck('request')->filter()->unique('audience_key');
        if ($activeRequests->isEmpty()) {
            return $resource->originAssignment !== null
                && $this->authorization->hasPermissionAtAssignmentScope($user, 'resources.archive', ...$this->authorization->assignmentScope($resource->originAssignment));
        }

        foreach ($activeRequests as $request) {
            $canArchive = $request->audience_type === 'private'
                ? $resource->originAssignment && $this->authorization->hasPermissionAtAssignmentScope($user, 'resources.archive', ...$this->authorization->assignmentScope($resource->originAssignment))
                : $this->canReviewTargetWithPermission($user, 'resources.archive', $request);
            if (! $canArchive) {
                return false;
            }
        }

        return true;
    }

    private function recordDecision(ResourceDistributionRequest $request, int $versionId, int $actorId, string $action, ?string $reason): void
    {
        ResourceDistributionDecision::create([
            'resource_distribution_request_id' => $request->id,
            'resource_version_id' => $versionId,
            'actor_user_id' => $actorId,
            'action' => $action,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function effectiveReleasesForResource(Resource $resource): Builder
    {
        return $this->effectiveReleasesQuery()->where('resource_id', $resource->id);
    }

    private function effectiveReleasesQuery(): Builder
    {
        return ResourceDistributionRelease::query()
            ->whereNull('revoked_at')
            ->whereNotExists(function ($newer): void {
                $newer->selectRaw('1')->from('resource_distribution_releases as newer')
                    ->whereColumn('newer.resource_id', 'resource_distribution_releases.resource_id')
                    ->whereColumn('newer.audience_key', 'resource_distribution_releases.audience_key')
                    ->whereNull('newer.revoked_at')
                    ->where(function ($ordered): void {
                        $ordered->whereColumn('newer.released_at', '>', 'resource_distribution_releases.released_at')
                            ->orWhere(function ($sameTime): void {
                                $sameTime->whereColumn('newer.released_at', '=', 'resource_distribution_releases.released_at')
                                    ->whereColumn('newer.id', '>', 'resource_distribution_releases.id');
                            });
                    });
            });
    }

    private function hasEffectiveReleaseForAudience(Resource $resource, string $audienceKey): bool
    {
        return $this->effectiveReleasesForResource($resource)->where('audience_key', $audienceKey)->exists();
    }

    private function userBelongsToAudience(User $user, ResourceDistributionRequest $request): bool
    {
        if ($request->audience_type === 'private') {
            if ((int) $request->recipient_user_id !== (int) $user->id) {
                return false;
            }

            foreach ($this->authorization->getActiveAssignmentsGranting($user, 'resources.view') as $assignment) {
                [$scopeType, $scopeId] = $this->authorization->assignmentScope($assignment);
                if ($this->authorization->hasPermissionAtAssignmentScope($user, 'resources.view', $scopeType, $scopeId)) {
                    return true;
                }
            }

            return false;
        }

        return $this->hasPermissionForAudienceTarget($user, 'resources.view', $request->audience_type, $request->target_org_unit_id, $request->target_campaign_id, $request->target_site_id, $request->target_team_id, true);
    }

    private function hasPermissionForAudienceTarget(
        User $user,
        string $permission,
        string $type,
        ?int $orgUnitId,
        ?int $campaignId,
        ?int $siteId,
        ?int $teamId,
        bool $includeDescendants = false
    ): bool {
        if ($type === 'private') {
            return false;
        }
        if (in_array($type, ['union', ...array_keys(self::ORG_AUDIENCES)], true)) {
            if ($orgUnitId === null) {
                return false;
            }
            $root = OrgUnit::where('short_code', 'UUM')->first();
            if (! $root || ! $this->isUnderUnion(OrgUnit::find($orgUnitId))) {
                return false;
            }
            $orgUnits = $includeDescendants ? $this->orgUnitSubtree($orgUnitId) : collect([OrgUnit::find($orgUnitId)])->filter();
            foreach ($orgUnits as $unit) {
                if ($this->authorization->hasPermissionAtAssignmentScope($user, $permission, 'org_unit', (int) $unit->id)) {
                    return true;
                }
            }
            if (! $includeDescendants) {
                return false;
            }
            $campaigns = Campaign::whereIn('lead_org_unit_id', $orgUnits->modelKeys())->with('sites.teams')->get();
            foreach ($campaigns as $campaign) {
                if ($this->authorization->hasPermissionForCampaign($user, $permission, $campaign)) {
                    return true;
                }
                foreach ($campaign->sites as $site) {
                    if ($this->authorization->hasPermissionForSite($user, $permission, $site)) {
                        return true;
                    }
                    foreach ($site->teams as $team) {
                        if ($this->authorization->hasPermissionForTeam($user, $permission, $team)) {
                            return true;
                        }
                    }
                }
                foreach (Team::where('campaign_id', $campaign->id)->whereNull('mission_site_id')->get() as $team) {
                    if ($this->authorization->hasPermissionForTeam($user, $permission, $team)) {
                        return true;
                    }
                }
            }

            return false;
        }
        if ($type === 'campaign' && $campaignId !== null) {
            $campaign = Campaign::with('sites.teams')->find($campaignId);
            if (! $campaign) {
                return false;
            }
            if ($this->authorization->hasPermissionForCampaign($user, $permission, $campaign)) {
                return true;
            }
            if ($includeDescendants) {
                foreach ($campaign->sites as $site) {
                    if ($this->authorization->hasPermissionForSite($user, $permission, $site)) {
                        return true;
                    }
                    foreach ($site->teams as $team) {
                        if ($this->authorization->hasPermissionForTeam($user, $permission, $team)) {
                            return true;
                        }
                    }
                }
                foreach (Team::where('campaign_id', $campaign->id)->whereNull('mission_site_id')->get() as $team) {
                    if ($this->authorization->hasPermissionForTeam($user, $permission, $team)) {
                        return true;
                    }
                }
            }

            return false;
        }
        if ($type === 'site' && $siteId !== null) {
            $site = MissionSite::find($siteId);

            return $site !== null && $this->authorization->hasPermissionForSite($user, $permission, $site);
        }
        if ($type === 'team' && $teamId !== null) {
            $team = Team::find($teamId);

            return $team !== null && $this->authorization->hasPermissionForTeam($user, $permission, $team);
        }

        return false;
    }

    private function canReviewTargetWithPermission(User $user, string $permission, ResourceDistributionRequest $request): bool
    {
        return $this->hasPermissionForAudienceTarget($user, $permission, $request->audience_type, $request->target_org_unit_id, $request->target_campaign_id, $request->target_site_id, $request->target_team_id);
    }

    private function isUnderUnion(?OrgUnit $unit): bool
    {
        if (! $unit) {
            return false;
        }
        $root = OrgUnit::where('short_code', 'UUM')->first();
        if (! $root) {
            return false;
        }
        $current = $unit;
        while ($current) {
            if ((int) $current->id === (int) $root->id) {
                return true;
            }
            $current = $current->parent;
        }

        return false;
    }

    public function normalizeAudience(array $data): array
    {
        $type = $data['audience_type'] ?? '';
        $expectedField = match ($type) {
            'conference', 'mission_field', 'zone', 'church_district', 'local_church' => 'target_org_unit_id',
            'campaign' => 'target_campaign_id',
            'site' => 'target_site_id',
            'team' => 'target_team_id',
            'private' => 'recipient_user_id',
            default => null,
        };
        foreach (['target_org_unit_id', 'target_campaign_id', 'target_site_id', 'target_team_id', 'recipient_user_id'] as $field) {
            if ($field !== $expectedField && ! empty($data[$field])) {
                throw ValidationException::withMessages([$field => 'This target does not match the selected audience type.']);
            }
        }
        if ($type === 'union' && ! empty($data['target_org_unit_id'])) {
            throw ValidationException::withMessages(['target_org_unit_id' => 'Mission-wide audience uses the Uganda Union Mission root automatically.']);
        }

        return $this->resolveAudienceTarget($data);
    }

    private function resolveAudienceTarget(array $data): array
    {
        $type = $data['audience_type'] ?? '';
        $targets = [
            'target_org_unit_id' => null,
            'target_campaign_id' => null,
            'target_site_id' => null,
            'target_team_id' => null,
            'recipient_user_id' => null,
        ];

        if ($type === 'union') {
            $target = OrgUnit::where('short_code', 'UUM')->first();
            if (! $target || ! $this->isUnderUnion($target)) {
                throw ValidationException::withMessages(['audience_type' => 'The Uganda Union Mission organizational unit is unavailable.']);
            }
            $targets['target_org_unit_id'] = $target->id;
        } elseif (isset(self::ORG_AUDIENCES[$type])) {
            $target = OrgUnit::find($data['target_org_unit_id'] ?? null);
            $expectedCode = self::ORG_AUDIENCES[$type];
            if (! $target || $target->type?->code !== $expectedCode || ! $this->isUnderUnion($target)) {
                throw ValidationException::withMessages(['target_org_unit_id' => 'Select a valid UU organizational unit for this audience.']);
            }
            $targets['target_org_unit_id'] = $target->id;
        } elseif ($type === 'campaign') {
            $target = Campaign::with('leadOrgUnit')->find($data['target_campaign_id'] ?? null);
            if (! $target || ! $target->leadOrgUnit || ! $this->isUnderUnion($target->leadOrgUnit)) {
                throw ValidationException::withMessages(['target_campaign_id' => 'Select a campaign within Uganda Union Mission.']);
            }
            $targets['target_campaign_id'] = $target->id;
        } elseif ($type === 'site') {
            $target = MissionSite::with('campaign.leadOrgUnit')->find($data['target_site_id'] ?? null);
            if (! $target || ! $target->campaign?->leadOrgUnit || ! $this->isUnderUnion($target->campaign->leadOrgUnit)) {
                throw ValidationException::withMessages(['target_site_id' => 'Select a site within Uganda Union Mission.']);
            }
            $targets['target_site_id'] = $target->id;
        } elseif ($type === 'team') {
            $target = Team::with('campaign.leadOrgUnit')->find($data['target_team_id'] ?? null);
            if (! $target || ! $target->campaign?->leadOrgUnit || ! $this->isUnderUnion($target->campaign->leadOrgUnit)) {
                throw ValidationException::withMessages(['target_team_id' => 'Select a team within Uganda Union Mission.']);
            }
            $targets['target_team_id'] = $target->id;
        } elseif ($type === 'private') {
            $recipientId = (int) ($data['recipient_user_id'] ?? 0);
            if ($recipientId < 1 || ! User::whereKey($recipientId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['recipient_user_id' => 'Select an active recipient account.']);
            }
            $targets['recipient_user_id'] = $recipientId;
        } else {
            throw ValidationException::withMessages(['audience_type' => 'Choose a supported distribution audience.']);
        }

        $targetId = collect($targets)->filter(fn ($id) => $id !== null)->first();

        return [
            ...$targets,
            'audience_type' => $type,
            'audience_key' => $type.':'.$targetId,
        ];
    }

    /** @return Collection<int, OrgUnit> */
    private function orgUnitSubtree(int $rootId): Collection
    {
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier !== []) {
            $children = OrgUnit::whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->diff($ids)->all();
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return OrgUnit::whereIn('id', $ids)->get();
    }
}
