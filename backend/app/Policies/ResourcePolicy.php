<?php

namespace App\Policies;

use App\Models\Resource;
use App\Models\ResourceDistributionRequest;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\ResourceVisibilityService;

/**
 * ResourcePolicy — authorization rules for resource operations.
 *
 * Uses AuthorizationService for scoped permission checks.
 * Resources follow the existing UU Mission RBAC and scope architecture.
 */
class ResourcePolicy
{
    protected AuthorizationService $authService;

    protected ResourceVisibilityService $visibility;

    public function __construct(AuthorizationService $authService, ResourceVisibilityService $visibility)
    {
        $this->authService = $authService;
        $this->visibility = $visibility;
    }

    /**
     * Determine if the user can view any resources.
     * Controller will filter results by scope and permissions.
     */
    public function viewAny(User $user): bool
    {
        return $this->visibility->canBrowse($user);
    }

    /**
     * Determine if the user can view a specific resource.
     */
    public function view(User $user, Resource $resource): bool
    {
        if ($resource->isArchived()) {
            return false;
        }
        if ($this->visibility->canView($user, $resource)) {
            return true;
        }
        // Creator can view their own draft resources (not published)
        if ($resource->status === 'draft' && (int) $resource->created_by_user_id === (int) $user->id && $this->visibility->canEdit($user, $resource)) {
            return true;
        }

        return $this->visibility->canReview($user, $resource);
    }

    /**
     * Determine if the user can create resources.
     *
     * Any authenticated user with the resources.create permission can create
     * private resource drafts. No active campaign/site/team appointment is
     * required for draft creation.
     */
    public function create(User $user): bool
    {
        return in_array('resources.create', $this->authService->getEffectivePermissions($user), true);
    }

    public function createInScope(User $user, Resource $resource): bool
    {
        return $this->create($user);
    }

    /**
     * Determine if the user can submit a resource for distribution.
     *
     * Only the resource creator can submit their own draft for distribution,
     * and they must hold the resources.distribute permission.
     */
    public function distribute(User $user, Resource $resource): bool
    {
        if ($resource->isArchived()) {
            return false;
        }

        // Only the creator can submit their own draft for distribution,
        // and they must hold the resources.distribute permission.
        if ((int) $resource->created_by_user_id !== (int) $user->id) {
            return false;
        }

        return in_array('resources.distribute', $this->authService->getEffectivePermissions($user), true);
    }

    /**
     * Determine if the user can approve or reject a distribution request.
     *
     * Requires resources.approve_distribute at the appropriate scope for the
     * distribution request's audience.
     */
    public function approveDistribute(User $user, ResourceDistributionRequest $request): bool
    {
        return $this->authService->canApproveDistribute($user, $request);
    }

    /**
     * Determine if the user can update a resource.
     */
    public function update(User $user, Resource $resource): bool
    {
        return $this->visibility->canEdit($user, $resource);
    }

    public function updateInScope(User $user, Resource $resource): bool
    {
        return $this->visibility->canEdit($user, $resource);
    }

    /**
     * Determine if the user can publish a resource.
     */
    public function publish(User $user, Resource $resource): bool
    {
        return false;
    }

    /**
     * Determine if the user can archive a resource.
     */
    public function archive(User $user, Resource $resource): bool
    {
        return $this->visibility->canArchive($user, $resource);
    }

    /**
     * Determine if the user can replace the file for a resource.
     */
    public function replaceFile(User $user, Resource $resource): bool
    {
        return $this->update($user, $resource);
    }

    /**
     * Determine if the user can delete a resource.
     * Resources are never deleted - they are archived instead.
     */
    public function delete(User $user, Resource $resource): bool
    {
        return false;
    }

    /**
     * Determine if the user can manage a resource (edit, publish, archive).
     * Management requires the user to have permission within the resource's scope.
     */
}
