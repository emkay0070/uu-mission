<?php

namespace App\Services;

use App\Models\Resource;
use App\Models\ResourceVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Central resource access rules backed exclusively by approved distribution releases. */
class ResourceVisibilityService
{
    public function __construct(private ResourceDistributionService $distribution) {}

    public function canView(User $user, Resource $resource, ?ResourceVersion $version = null): bool
    {
        return $this->distribution->canRead($user, $resource, $version);
    }

    public function apply(Builder $query, User $user, bool $publishedOnly = true): Builder
    {
        if (! $publishedOnly) {
            return $query->whereRaw('1 = 0');
        }

        return $this->distribution->applyLibraryVisibility($query, $user);
    }

    public function canBrowse(User $user): bool
    {
        return $this->distribution->hasAnyReaderPermission($user);
    }

    public function canCreate(User $user): bool
    {
        return $this->distribution->hasContributionPermission($user);
    }

    public function canEdit(User $user, Resource $resource): bool
    {
        return $this->distribution->canEdit($user, $resource);
    }

    public function canReview(User $user, Resource $resource): bool
    {
        return $this->distribution->canReviewResource($user, $resource);
    }

    public function canArchive(User $user, Resource $resource): bool
    {
        return $this->distribution->canArchive($user, $resource);
    }
}
