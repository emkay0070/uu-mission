<?php

namespace App\Policies;

use App\Models\ResourceConversation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * ResourceConversationPolicy — authorization for AI conversations.
 *
 * Conversations are private to the user who created them.
 * Resource access is verified separately via ResourceVisibilityService.
 */
class ResourceConversationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view a specific conversation.
     */
    public function view(User $user, ResourceConversation $conversation): bool
    {
        return (int) $conversation->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can create a conversation.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can update a conversation.
     */
    public function update(User $user, ResourceConversation $conversation): bool
    {
        return (int) $conversation->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can delete a conversation.
     */
    public function delete(User $user, ResourceConversation $conversation): bool
    {
        return (int) $conversation->user_id === (int) $user->id;
    }
}
