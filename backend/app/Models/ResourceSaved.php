<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ResourceSaved — a resource saved to a user's personal library.
 *
 * Saving is separate from downloading. A saved resource is in the user's
 * library but may not be downloaded for offline access.
 */
class ResourceSaved extends Model
{
    use HasFactory;

    /**
     * The table name is singular ("resource_saved"), so it cannot be inferred
     * by Eloquent's pluralization.
     */
    protected $table = 'resource_saved';

    protected $fillable = [
        'user_id',
        'resource_id',
        'resource_version_id',
    ];

    protected function casts(): array
    {
        return [
            'saved_at' => 'datetime',
        ];
    }

    /**
     * The user who saved this resource.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The resource that was saved.
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The specific version of the resource that was saved.
     */
    public function resourceVersion(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class, 'resource_version_id');
    }

    /**
     * Scope: saved resources for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
