<?php

namespace App\Policies;

use App\Models\ResourceSavedExplanation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * ResourceSavedExplanationPolicy — authorization for saved AI explanations.
 *
 * Saved explanations are private to the user who created them.
 * Resource access is verified separately via ResourceVisibilityService.
 */
class ResourceSavedExplanationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view a specific saved explanation.
     */
    public function view(User $user, ResourceSavedExplanation $explanation): bool
    {
        return (int) $explanation->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can create a saved explanation.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can update a saved explanation.
     */
    public function update(User $user, ResourceSavedExplanation $explanation): bool
    {
        return (int) $explanation->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can delete a saved explanation.
     */
    public function delete(User $user, ResourceSavedExplanation $explanation): bool
    {
        return (int) $explanation->user_id === (int) $user->id;
    }
}
