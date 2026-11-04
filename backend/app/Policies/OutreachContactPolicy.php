<?php

namespace App\Policies;

use App\Models\OutreachContact;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * OutreachContactPolicy — authorization for outreach contact management.
 *
 * An outreach contact is the outreach-journey record attached to a Person.
 * Access is derived from access to the underlying person (PersonPolicy),
 * which itself resolves the user's real scope through AuthorizationService.
 *
 * Listing additionally requires an outreach-contacts permission in at least
 * one scope; the controller scopes the result set to those scopes.
 */
class OutreachContactPolicy
{
    /** Codes that grant read access to outreach contacts. */
    private const VIEW_PERMISSIONS = ['outreach-contacts.view', 'people.view'];

    /** Codes that grant creation of outreach contacts. */
    private const CREATE_PERMISSIONS = ['outreach-contacts.create'];

    /** Codes that grant editing of outreach contacts. */
    private const EDIT_PERMISSIONS = ['outreach-contacts.edit', 'outreach-contacts.create'];

    public function __construct(private AuthorizationService $auth) {}

    public function viewAny(User $user): bool
    {
        return $this->auth->getAuthorisedScopes($user, self::VIEW_PERMISSIONS) !== [];
    }

    public function view(User $user, OutreachContact $contact): bool
    {
        // Access to the contact follows access to the person.
        return $user->can('view', $contact->person);
    }

    public function create(User $user): bool
    {
        return $this->auth->getAuthorisedScopes($user, self::CREATE_PERMISSIONS) !== [];
    }

    public function update(User $user, OutreachContact $contact): bool
    {
        if ($this->auth->getAuthorisedScopes($user, self::EDIT_PERMISSIONS) === []) {
            return false;
        }

        return $this->view($user, $contact);
    }

    public function delete(User $user, OutreachContact $contact): bool
    {
        if ($this->auth->getAuthorisedScopes($user, self::EDIT_PERMISSIONS) === []) {
            return false;
        }

        return $this->view($user, $contact);
    }
}
