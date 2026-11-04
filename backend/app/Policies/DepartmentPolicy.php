<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\Department;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * DepartmentPolicy — authorization for department management.
 *
 * Users can manage departments within campaigns they have assignments.manage permission for.
 */
class DepartmentPolicy
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * View departments for a campaign.
     */
    public function viewAny(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.view', $campaign);
    }

    /**
     * View a specific department.
     */
    public function view(User $user, Department $department): bool
    {
        return $this->authorizeInCampaign($user, 'assignments.view', $department);
    }

    /**
     * Create a department within a campaign.
     */
    public function create(User $user, Campaign $campaign): bool
    {
        return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $campaign);
    }

    /**
     * Update a department.
     */
    public function update(User $user, Department $department): bool
    {
        return $this->authorizeInCampaign($user, 'assignments.manage', $department);
    }

    /**
     * Delete a department.
     */
    public function delete(User $user, Department $department): bool
    {
        return $this->authorizeInCampaign($user, 'assignments.manage', $department);
    }

    /**
     * Resolve the department's campaign and delegate to the campaign-scoped
     * permission check. Fails closed when the campaign relation is missing.
     */
    private function authorizeInCampaign(User $user, string $permission, Department $department): bool
    {
        $campaign = $department->campaign;

        if (! $campaign) {
            return false;
        }

        return $this->auth->hasPermissionForCampaign($user, $permission, $campaign);
    }
}
