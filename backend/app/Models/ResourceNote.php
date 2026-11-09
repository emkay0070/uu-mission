<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ResourceNote — personal notes on a resource.
 *
 * Private to the user who created them. Linked to specific pages/passages
 * with normalized coordinates for positioning.
 */
class ResourceNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'user_id',
        'resource_version_id',
        'page_number',
        'position_data',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'position_data' => 'array',
        ];
    }

    /**
     * The resource this note belongs to.
     */
    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The user who created this note.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The specific resource version this note is on.
     */
    public function resourceVersion()
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /**
     * Scope: notes for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: notes on a specific page.
     */
    public function scopeOnPage($query, $pageNumber)
    {
        return $query->where('page_number', $pageNumber);
    }
}
