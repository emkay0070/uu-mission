<?php

namespace App\Policies;

use App\Models\OfficeHeld;
use App\Models\OfficeType;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * OfficePolicy — authorization for organisational office management.
 *
 * Users can only manage office appointments within organisational units
 * they are authorised to administer.
 */
class OfficePolicy
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * View office appointments for an organisational unit.
     */
    public function viewAnyForOrgUnit(User $user, OrgUnit $orgUnit): bool
    {
        // Users with org-level permissions can view offices
        return $this->auth->hasGlobalPermission($user, 'org.view');
    }

    /**
     * View office appointments for a person.
     */
    public function viewAnyForPerson(User $user): bool
    {
        return $this->auth->hasGlobalPermission($user, 'org.view');
    }

    /**
     * View a specific office appointment.
     */
    public function view(User $user, OfficeHeld $office): bool
    {
        return $this->auth->hasGlobalPermission($user, 'org.view');
    }

    /**
     * Create an office appointment.
     * Requires org.manage permission at the target org unit level or global permission.
     * Prevents self-appointment escalation.
     */
    public function create(User $user, array $validated): bool
    {
        // Prevent users from appointing themselves to elevated positions
        $person = \App\Models\Person::find($validated['person_id']);
        if ($person && $person->user_id === $user->id) {
            return false;
        }

        // Check org unit authorization
        $orgUnit = OrgUnit::find($validated['org_unit_id']);
        if (!$orgUnit) {
            return false;
        }

        // Validate office type is appropriate for org unit type
        $officeType = OfficeType::find($validated['office_type_id']);
        if ($officeType && $officeType->org_unit_type_id) {
            if ($officeType->org_unit_type_id !== $orgUnit->org_unit_type_id) {
                return false;
            }
        }

        // Allow global admins or users with org.manage permission at the org unit level
        return $this->auth->hasGlobalPermission($user, 'org.manage') ||
               $this->auth->hasPermissionForOrgUnit($user, 'org.manage', $orgUnit->id);
    }

    /**
     * Update an office appointment (e.g., change end date).
     * Requires org.manage permission at the appointment's org unit.
     */
    public function update(User $user, OfficeHeld $office): bool
    {
        // Prevent users from modifying their own appointments
        if ($office->person && $office->person->user_id === $user->id) {
            return false;
        }

        return $this->auth->hasPermissionForOrgUnit($user, 'org.manage', $office->org_unit_id);
    }

    /**
     * Delete/revoke an office appointment.
     * Requires org.manage permission at the appointment's org unit.
     * Prefer ending appointments via update (setting held_until) over deletion.
     */
    public function delete(User $user, OfficeHeld $office): bool
    {
        // Prevent users from revoking their own appointments
        if ($office->person && $office->person->user_id === $user->id) {
            return false;
        }

        return $this->auth->hasGlobalPermission($user, 'org.manage');
    }
}
