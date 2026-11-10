<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;
use App\Services\MediaVisibilityService;

/**
 * MediaPolicy — authorisation for the central media library.
 *
 * Visibility is scope-based (see MediaVisibilityService). Mutations remain
 * with the uploader so one user can never edit, delete or detach another
 * user's media, even when both users share an entity scope.
 */
class MediaPolicy
{
    public function __construct(private MediaVisibilityService $visibility) {}

    /**
     * Any authenticated user may open the library; the controller query is
     * narrowed to the records they are actually allowed to see.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Media $media): bool
    {
        return $this->visibility->canView($user, $media);
    }

    /**
     * Every authenticated user may upload their own media. Uploads start
     * private unless the caller explicitly chooses otherwise.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Media $media): bool
    {
        return $this->owns($user, $media);
    }

    public function delete(User $user, Media $media): bool
    {
        return $this->owns($user, $media);
    }

    public function attach(User $user, Media $media): bool
    {
        return $this->owns($user, $media);
    }

    public function detach(User $user, Media $media): bool
    {
        return $this->owns($user, $media);
    }

    private function owns(User $user, Media $media): bool
    {
        return (int) $media->uploaded_by_user_id === (int) $user->id;
    }
}
