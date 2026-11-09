<?php

namespace App\Policies;

use App\Models\ResourceLearningNote;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * ResourceLearningNotePolicy — authorization for personal learning notes.
 *
 * Learning notes are private to the user who created them.
 * Resource access is verified separately via ResourceVisibilityService.
 */
class ResourceLearningNotePolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view a specific learning note.
     */
    public function view(User $user, ResourceLearningNote $note): bool
    {
        return (int) $note->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can create a learning note.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can update a learning note.
     */
    public function update(User $user, ResourceLearningNote $note): bool
    {
        return (int) $note->user_id === (int) $user->id;
    }

    /**
     * Determine if the user can delete a learning note.
     */
    public function delete(User $user, ResourceLearningNote $note): bool
    {
        return (int) $note->user_id === (int) $user->id;
    }
}
