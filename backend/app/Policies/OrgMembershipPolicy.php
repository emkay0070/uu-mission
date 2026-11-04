<?php

namespace App\Policies;

use App\Models\OrgMembership;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrgMembershipPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any org memberships.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('people.view');
    }

    /**
     * Determine whether the user can view the org membership.
     */
    public function view(User $user, OrgMembership $membership): bool
    {
        // Can view if they can view people in the org unit's scope
        return $user->can('people.view');
    }

    /**
     * Determine whether the user can create org memberships.
     */
    public function create(User $user): bool
    {
        return $user->can('people.create');
    }

    /**
     * Determine whether the user can update the org membership.
     */
    public function update(User $user, OrgMembership $membership): bool
    {
        return $user->can('people.update');
    }

    /**
     * Determine whether the user can approve the org membership.
     * Approval requires authority within the relevant organisational scope.
     */
    public function approve(User $user, OrgMembership $membership): bool
    {
        // Must have people.manage permission
        if (!$user->can('people.manage')) {
            return false;
        }

        // Check if user has authority within the org unit's hierarchy
        // This is a simplified check - in production, verify user has role assignment
        // scoped to this org unit or its parent units
        return $this->hasOrgUnitAuthority($user, $membership->org_unit_id);
    }

    /**
     * Determine whether the user can reject the org membership.
     */
    public function reject(User $user, OrgMembership $membership): bool
    {
        return $this->approve($user, $membership);
    }

    /**
     * Determine whether the user can delete the org membership.
     */
    public function delete(User $user, OrgMembership $membership): bool
    {
        return $user->can('people.manage');
    }

    /**
     * Check if user has authority within an org unit's hierarchy.
     */
    private function hasOrgUnitAuthority(User $user, int $orgUnitId): bool
    {
        // Check if user has a role assignment scoped to this org unit or any parent
        $hasScopedRole = $user->roleAssignments()
            ->where(function ($query) use ($orgUnitId) {
                $query->where('scoped_org_unit_id', $orgUnitId)
                      ->orWhereIn('scoped_org_unit_id', $this->getParentOrgUnitIds($orgUnitId));
            })
            ->where('valid_from', '<=', now())
            ->where(function ($query) {
                $query->whereNull('valid_until')
                      ->orWhere('valid_until', '>=', now());
            })
            ->exists();

        return $hasScopedRole || $user->can('people.manage.global');
    }

    /**
     * Get parent org unit IDs for hierarchy check.
     */
    private function getParentOrgUnitIds(int $orgUnitId): array
    {
        $parentIds = [];
        $currentOrgUnit = \App\Models\OrgUnit::find($orgUnitId);

        while ($currentOrgUnit && $currentOrgUnit->parent_id) {
            $parentIds[] = $currentOrgUnit->parent_id;
            $currentOrgUnit = \App\Models\OrgUnit::find($currentOrgUnit->parent_id);
        }

        return $parentIds;
    }
}
