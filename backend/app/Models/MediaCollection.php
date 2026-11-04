<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MediaCollection — curated groups of media items.
 *
 * Allows authorised users to organise media into meaningful collections
 * (e.g., "Northwest Uganda Mission 2026", "Youth Mission Highlights").
 */
class MediaCollection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'created_by_user_id',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * User who created this collection.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Media items in this collection.
     */
    public function items()
    {
        return $this->hasMany(MediaCollectionItem::class, 'media_collection_id')
                    ->orderBy('sort_order');
    }

    /**
     * Media in this collection (belongsToMany relationship).
     */
    public function media()
    {
        return $this->belongsToMany(Media::class, 'media_collection_items')
                    ->withPivot('sort_order')
                    ->withTimestamps()
                    ->orderBy('media_collection_items.sort_order');
    }
}
