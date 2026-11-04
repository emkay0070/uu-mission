<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * PersonPolicy — authorization for person registration and management.
 *
 * ACCESS MODEL
 * ------------
 * A Person is a permanent identity record. It carries NO campaign or site
 * column of its own (those fields were removed from the `people` table), so
 * a person's "location in the mission world" is derived from how they are
 * linked operationally:
 *
 *   - attendance entries   → attendance_entries.register (campaign + site)
 *   - activity participation → activity_participants.activity (campaign + site)
 *   - outreach contact      → outreach_contacts (campaign + first site)
 *
 * Authorization therefore resolves the user's ACTUAL scope through
 * AuthorizationService and then verifies the person is linked to one of
 * those scopes. It never treats a permission as globally available.
 *
 * Because the People & Follow-up workspace is driven by outreach contacts,
 * the outreach-contacts.* codes also satisfy the People module checks — a
 * missionary with only outreach permissions must still be able to open the
 * person behind an outreach contact they are responsible for.
 */
class PersonPolicy
{
    /** Codes that grant read access to the People module. */
    private const VIEW_PERMISSIONS = ['people.view', 'outreach-contacts.view'];

    /** Codes that grant creation of person records. */
    private const CREATE_PERMISSIONS = ['people.create', 'outreach-contacts.create'];

    /** Codes that grant editing of person records. */
    private const EDIT_PERMISSIONS = ['people.edit', 'outreach-contacts.edit'];

    public function __construct(private AuthorizationService $auth) {}

    /**
     * Listing people requires a view permission in at least one scope.
     * The controller additionally constrains the result set to those scopes,
     * so a user never sees people outside their authorised scope.
     */
    public function viewAny(User $user): bool
    {
        return $this->auth->getAuthorisedScopes($user, self::VIEW_PERMISSIONS) !== [];
    }

    /**
     * Viewing a person requires either having registered that person, or the
     * person being linked to a scope the user actually holds.
     */
    public function view(User $user, Person $person): bool
    {
        // The user who registered the person keeps access to their own record.
        if ($person->registered_by_user_id === $user->id) {
            return true;
        }

        $scopes = $this->auth->getAuthorisedScopes($user, self::VIEW_PERMISSIONS);

        if ($scopes === []) {
            return false;
        }

        return $this->personLinkedToScopes($person, $scopes);
    }

    /**
     * Creating a person requires a create permission in at least one scope.
     * The controller performs the precise campaign/site check when those are
     * supplied, so a user with no operational role cannot create people.
     */
    public function create(User $user): bool
    {
        return $this->auth->getAuthorisedScopes($user, self::CREATE_PERMISSIONS) !== [];
    }

    /**
     * Updating a person requires an edit permission in some scope AND the
     * person being visible to the user.
     */
    public function update(User $user, Person $person): bool
    {
        if ($this->auth->getAuthorisedScopes($user, self::EDIT_PERMISSIONS) === []) {
            return false;
        }

        return $this->view($user, $person);
    }

    /**
     * People are never hard-deleted through the API.
     */
    public function delete(User $user, Person $person): bool
    {
        return false;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Determine whether a person is operationally linked to any of the given
     * (campaign, site) scope pairs.
     *
     * @param  array<int, array{campaign_id: int|null, site_id: int|null}>  $scopes
     */
    private function personLinkedToScopes(Person $person, array $scopes): bool
    {
        // Attendance registers the person appears in.
        $hasAttendance = $person->attendanceEntries()
            ->whereHas('register', function ($q) use ($scopes) {
                $this->auth->scopeQuery($q, $scopes, 'campaign_id', 'mission_site_id');
            })
            ->exists();

        if ($hasAttendance) {
            return true;
        }

        // Activities the person participates in.
        $hasParticipation = $person->activityParticipations()
            ->whereHas('activity', function ($q) use ($scopes) {
                $this->auth->scopeQuery($q, $scopes, 'campaign_id', 'mission_site_id');
            })
            ->exists();

        if ($hasParticipation) {
            return true;
        }

        // The person's outreach contact record (campaign + first encountered site).
        return $person->outreachContact()
            ->where(function ($q) use ($scopes) {
                $this->auth->scopeQuery($q, $scopes, 'campaign_id', 'first_encountered_site_id');
            })
            ->exists();
    }
}
