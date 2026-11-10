<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\MediaAttachment;
use App\Models\MissionSite;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * MediaVisibilityService — resolves which media a user may see.
 *
 * Media is a central library: a file becomes part of a mission scope through
 * its polymorphic media_attachments rows. This service keeps that rule in one
 * place so list queries and single-record checks cannot drift apart.
 *
 * Visibility rules:
 *   private        → the uploader only
 *   public         → every authenticated user
 *   organisational → users whose active assignment scope covers at least one
 *                    entity the media is attached to. Unattached
 *                    organisational media stays with its uploader.
 *
 * Scope resolution is deliberately permission-agnostic, matching
 * canViewCampaign()/canViewSite(): the appointment defines the area a person
 * may work in, while each module's policy decides what they may do there.
 */
class MediaVisibilityService
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * Constrain a media query to the records the user may see.
     */
    public function visibleTo(User $user): Builder
    {
        $scopes = $this->auth->getAuthorisedScopes($user);

        return Media::query()->where(function (Builder $query) use ($user, $scopes): void {
            $query->where('uploaded_by_user_id', $user->id)
                ->orWhere('visibility', 'public');

            if ($scopes === []) {
                return;
            }

            $query->orWhere(function (Builder $query) use ($scopes): void {
                $query->where('visibility', 'organisational');

                $this->constrainToScopes($query, $scopes);
            });
        });
    }

    /**
     * Determine whether one media record is visible to the user.
     */
    public function canView(User $user, Media $media): bool
    {
        if ((int) $media->uploaded_by_user_id === (int) $user->id) {
            return true;
        }

        if ($media->visibility === 'public') {
            return true;
        }

        if ($media->visibility !== 'organisational') {
            return false;
        }

        $scopes = $this->auth->getAuthorisedScopes($user);

        if ($scopes === []) {
            return false;
        }

        $scopeIds = $this->resolveScopeIds($scopes);

        if ($scopeIds['global']) {
            return $media->attachments()->exists();
        }

        return $media->attachments()
            ->get()
            ->contains(fn (MediaAttachment $attachment): bool => $this->attachmentWithinScopeIds($attachment, $scopeIds));
    }

    /**
     * Add the "attached to an entity inside these scopes" constraint.
     *
     * @param  array<int, array{campaign_id: int|null, site_id: int|null, except_site_ids?: array<int, int>}>  $scopes
     */
    private function constrainToScopes(Builder $query, array $scopes): void
    {
        $scopeIds = $this->resolveScopeIds($scopes);

        if ($scopeIds['global']) {
            $query->whereHas('attachments');

            return;
        }

        if ($scopeIds['campaign_ids'] === []
            && $scopeIds['site_ids'] === []
            && $scopeIds['team_ids'] === []
            && $scopeIds['activity_ids'] === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('attachments', function (Builder $attachment) use ($scopeIds): void {
            $attachment->where(function (Builder $attachment) use ($scopeIds): void {
                $this->orWhereAttachableIn($attachment, Campaign::class, $scopeIds['campaign_ids']);
                $this->orWhereAttachableIn($attachment, MissionSite::class, $scopeIds['site_ids']);
                $this->orWhereAttachableIn($attachment, Team::class, $scopeIds['team_ids']);
                $this->orWhereAttachableIn($attachment, Activity::class, $scopeIds['activity_ids']);
            });
        });
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function orWhereAttachableIn(Builder $query, string $class, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $query->orWhere(function (Builder $query) use ($class, $ids): void {
            $query->whereIn('attachable_type', $this->morphTypes($class))
                ->whereIn('attachable_id', $ids);
        });
    }

    /**
     * The morph values that may identify a class. New writes use the FQCN;
     * the basename keeps older rows readable.
     *
     * @return array<int, string>
     */
    private function morphTypes(string $class): array
    {
        return array_values(array_unique([$class, class_basename($class)]));
    }

    /**
     * @param  array{campaign_ids: array<int, int>, site_ids: array<int, int>, team_ids: array<int, int>, activity_ids: array<int, int>, global: bool}  $scopeIds
     */
    private function attachmentWithinScopeIds(MediaAttachment $attachment, array $scopeIds): bool
    {
        if ($scopeIds['global']) {
            return true;
        }

        $type = (string) $attachment->attachable_type;
        $id = (int) $attachment->attachable_id;

        return match (true) {
            in_array($type, $this->morphTypes(Campaign::class), true) => in_array($id, $scopeIds['campaign_ids'], true),
            in_array($type, $this->morphTypes(MissionSite::class), true) => in_array($id, $scopeIds['site_ids'], true),
            in_array($type, $this->morphTypes(Team::class), true) => in_array($id, $scopeIds['team_ids'], true),
            in_array($type, $this->morphTypes(Activity::class), true) => in_array($id, $scopeIds['activity_ids'], true),
            default => false,
        };
    }

    /**
     * Expand the authorisation service's (campaign, site) pairs into the
     * concrete attachable ids they cover.
     *
     * Campaign-wide pairs cover the campaign itself and every site, team and
     * activity inside it. Site pairs cover only that site and its children.
     * Explicit site denials are removed from the expanded set.
     *
     * @param  array<int, array{campaign_id: int|null, site_id: int|null, except_site_ids?: array<int, int>}>  $scopes
     * @return array{campaign_ids: array<int, int>, site_ids: array<int, int>, team_ids: array<int, int>, activity_ids: array<int, int>, global: bool}
     */
    private function resolveScopeIds(array $scopes): array
    {
        $campaignIds = [];
        $siteIds = [];
        $exceptSiteIds = [];
        $global = false;

        foreach ($scopes as $scope) {
            if ($scope['campaign_id'] === null && $scope['site_id'] === null) {
                $global = true;

                continue;
            }

            if ($scope['campaign_id'] !== null && $scope['site_id'] === null) {
                $campaignIds[] = (int) $scope['campaign_id'];
            }

            if ($scope['site_id'] !== null) {
                $siteIds[] = (int) $scope['site_id'];
            }

            foreach ($scope['except_site_ids'] ?? [] as $exceptSiteId) {
                $exceptSiteIds[] = (int) $exceptSiteId;
            }
        }

        $campaignIds = $this->uniqueInts($campaignIds);

        if ($campaignIds !== []) {
            $siteIds = array_merge(
                $siteIds,
                MissionSite::whereIn('campaign_id', $campaignIds)->pluck('id')->all()
            );
        }

        $siteIds = array_values(array_diff($this->uniqueInts($siteIds), $this->uniqueInts($exceptSiteIds)));

        $teamQuery = Team::query()->where(function (Builder $query) use ($campaignIds, $siteIds): void {
            if ($siteIds !== []) {
                $query->orWhereIn('mission_site_id', $siteIds);
            }

            if ($campaignIds !== []) {
                $query->orWhere(function (Builder $query) use ($campaignIds): void {
                    $query->whereNull('mission_site_id')->whereIn('campaign_id', $campaignIds);
                });
            }
        });

        $activityQuery = Activity::query()->where(function (Builder $query) use ($campaignIds, $siteIds): void {
            if ($siteIds !== []) {
                $query->orWhereIn('mission_site_id', $siteIds);
            }

            if ($campaignIds !== []) {
                $query->orWhere(function (Builder $query) use ($campaignIds): void {
                    $query->whereNull('mission_site_id')->whereIn('campaign_id', $campaignIds);
                });
            }
        });

        return [
            'campaign_ids' => $campaignIds,
            'site_ids' => $siteIds,
            'team_ids' => $this->uniqueInts($teamQuery->pluck('id')->all()),
            'activity_ids' => $this->uniqueInts($activityQuery->pluck('id')->all()),
            'global' => $global,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function uniqueInts(array $values): array
    {
        return array_values(array_unique(array_map(fn ($value): int => (int) $value, $values)));
    }
}
