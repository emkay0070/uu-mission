<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\Communication;
use App\Models\MissionSite;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\CommunicationService;

/**
 * CommunicationPolicy — authorization for communications.
 *
 * Viewing is a receipt concern: any eligible, fully onboarded missionary may
 * list and read communications addressed to their scopes. Publishing and
 * revocation are authority concerns enforced through existing RBAC.
 */
class CommunicationPolicy
{
    public function __construct(
        private AuthorizationService $auth,
        private CommunicationService $communications
    ) {}

    public function viewAny(User $user): bool
    {
        // Receiving communications is an eligibility concern, not an
        // operational permission. Any eligible missionary may list their
        // communications.
        return $this->communications->isEligibleRecipient($user);
    }

    public function view(User $user, Communication $communication): bool
    {
        // The author can always view their own communication (including drafts).
        if ((int) $communication->author_user_id === (int) $user->id) {
            return true;
        }

        // Recipients can only view published communications.
        return $communication->status === Communication::STATUS_PUBLISHED
            && $this->communications->canReceive($user, $communication);
    }

    public function create(User $user): bool
    {
        // The user must hold communications.publish at *some* scope. The
        // controller enforces authority for the specific target scope.
        return in_array('communications.view',
            $this->auth->getEffectivePermissions($user), true)
            || in_array('communications.publish',
                $this->auth->getEffectivePermissions($user), true);
    }

    public function update(User $user, Communication $communication): bool
    {
        // Only the author, or someone with revoke authority at the scope,
        // may update a communication.
        if ((int) $communication->author_user_id === (int) $user->id) {
            return true;
        }

        return $this->canRevoke($user, $communication);
    }

    public function delete(User $user, Communication $communication): bool
    {
        return $this->update($user, $communication);
    }

    /**
     * Determine whether the user may revoke (soft-delete) the communication.
     */
    private function canRevoke(User $user, Communication $communication): bool
    {
        [$type, $id] = $communication->assignmentScope();

        return match ($type) {
            'global' => $this->auth->hasGlobalPermission($user, 'communications.revoke'),
            'org_unit' => $id !== null
                && $this->auth->hasPermissionForOrgUnit($user, 'communications.revoke', (int) $id),
            'campaign' => ($campaign = $id ? Campaign::find($id) : null) !== null
                && $this->auth->hasPermissionForCampaign($user, 'communications.revoke', $campaign),
            'site' => ($site = $id ? MissionSite::find($id) : null) !== null
                && $this->auth->hasPermissionForSite($user, 'communications.revoke', $site),
            'team' => ($team = $id ? Team::find($id) : null) !== null
                && $this->auth->hasPermissionForTeam($user, 'communications.revoke', $team),
            default => false,
        };
    }
}
