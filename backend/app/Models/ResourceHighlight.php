<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ResourceHighlight — user highlights on a resource.
 *
 * Private to the user who created them. Stored with normalized coordinates
 * to remain correctly positioned across zoom levels.
 */
class ResourceHighlight extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'user_id',
        'resource_version_id',
        'selected_text',
        'page_number',
        'bounding_box',
        'color',
        'zoom_level',
    ];

    protected function casts(): array
    {
        return [
            'bounding_box' => 'array',
            'zoom_level' => 'float',
        ];
    }

    /**
     * The resource this highlight belongs to.
     */
    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The user who created this highlight.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The specific resource version this highlight is on.
     */
    public function resourceVersion()
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /**
     * Scope: highlights for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: highlights on a specific page.
     */
    public function scopeOnPage($query, $pageNumber)
    {
        return $query->where('page_number', $pageNumber);
    }

    /**
     * Scope: highlights with a specific color.
     */
    public function scopeWithColor($query, $color)
    {
        return $query->where('color', $color);
    }
}
