<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ResourceBookmark — user bookmarks on a resource.
 *
 * Private to the user who created them. Stored with normalized coordinates
 * for precise positioning.
 */
class ResourceBookmark extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'user_id',
        'resource_version_id',
        'page_number',
        'position_data',
        'title',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'position_data' => 'array',
        ];
    }

    /**
     * The resource this bookmark belongs to.
     */
    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The user who created this bookmark.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The specific resource version this bookmark is on.
     */
    public function resourceVersion()
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /**
     * Scope: bookmarks for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: bookmarks on a specific page.
     */
    public function scopeOnPage($query, $pageNumber)
    {
        return $query->where('page_number', $pageNumber);
    }
}
