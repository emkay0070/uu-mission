<?php

namespace App\Policies;

use App\Models\OutreachEncounter;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * OutreachEncounterPolicy — authorization for outreach encounters (follow-ups).
 *
 * Reading an encounter follows access to the underlying person, which is
 * resolved through the scoped AuthorizationService checks in PersonPolicy.
 *
 * Recording an encounter requires an outreach-contacts.create permission in
 * at least one scope; the controller then verifies the specific campaign and
 * site the encounter is being recorded against.
 */
class OutreachEncounterPolicy
{
    use HandlesAuthorization;

    /** Codes that grant the recording of outreach encounters. */
    private const CREATE_PERMISSIONS = ['outreach-contacts.create'];

    public function __construct(private AuthorizationService $auth) {}

    public function view(User $user, OutreachEncounter $encounter): bool
    {
        // User can view encounters for people they have access to.
        return $user->can('view', $encounter->person);
    }

    public function create(User $user): bool
    {
        return $this->auth->getAuthorisedScopes($user, self::CREATE_PERMISSIONS) !== [];
    }

    public function update(User $user, OutreachEncounter $encounter): bool
    {
        // Only the recorder can update.
        return $encounter->recorded_by_user_id === $user->id;
    }

    public function delete(User $user, OutreachEncounter $encounter): bool
    {
        // Only the recorder can delete.
        return $encounter->recorded_by_user_id === $user->id;
    }
}
