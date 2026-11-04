<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MediaCollectionItem — pivot table for media in collections.
 *
 * Represents the relationship between a media item and a collection,
 * including sort order for display.
 */
class MediaCollectionItem extends Model
{
    protected $fillable = [
        'media_collection_id',
        'media_id',
        'sort_order',
    ];

    /**
     * The collection this item belongs to.
     */
    public function collection()
    {
        return $this->belongsTo(MediaCollection::class, 'media_collection_id');
    }

    /**
     * The media item in this collection.
     */
    public function media()
    {
        return $this->belongsTo(Media::class);
    }
}
