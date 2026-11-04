<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * User — the authentication identity for the platform.
 *
 * Intentionally thin: personal details (name, gender, phone) live on the
 * linked Person record. This model only handles authentication concerns.
 *
 * Fillable: email, password, status, timezone.
 * name is NOT a column — avoid including it here.
 */
#[Fillable(['email', 'password', 'status', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasApiTokens, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
        ];
    }

    /**
     * The person profile linked to this user account.
     */
    public function person(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Person::class);
    }

    /**
     * All role assignments for this user across all scopes.
     */
    public function roleAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    /**
     * Campaign assignments for the linked person record.
     */
    public function campaignAssignments(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            CampaignAssignment::class,
            Person::class,
            'user_id',
            'person_id'
        )->select('campaign_assignments.*');
    }
}
