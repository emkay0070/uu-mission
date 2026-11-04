<?php

namespace App\Policies;

use App\Models\BaptismRecord;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BaptismRecordPolicy
{
    use HandlesAuthorization;

    public function view(User $user, BaptismRecord $record): bool
    {
        // User can view baptism records for people they have access to
        return $user->can('view', $record->person);
    }

    public function create(User $user): bool
    {
        // Any authenticated missionary with people.edit permission can record baptisms
        return $user->can('people.edit');
    }

    public function verify(User $user, BaptismRecord $record): bool
    {
        // Users with people.edit permission can verify baptisms
        return $user->can('people.edit');
    }

    public function update(User $user, BaptismRecord $record): bool
    {
        // Only verifiers can update pending records
        return $record->verification_status === 'pending' && $user->can('people.edit');
    }

    public function delete(User $user, BaptismRecord $record): bool
    {
        // Only verifiers can delete pending records
        return $record->verification_status === 'pending' && $user->can('people.edit');
    }
}
