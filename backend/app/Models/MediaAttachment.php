<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * MediaAttachment — reusable attachment of media to entities.
 *
 * Polymorphic relationship allowing one media file to be attached
 * to multiple entities (Person, Campaign, MissionSite, Team, Activity)
 * without duplicating the physical file.
 */
class MediaAttachment extends Model
{
    protected $fillable = [
        'media_id',
        'attachable_type',
        'attachable_id',
        'attached_by_user_id',
        'attachment_type',
    ];

    /**
     * The media being attached.
     */
    public function media()
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * User who created this attachment.
     */
    public function attachedBy()
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
    }

    /**
     * The entity this media is attached to (polymorphic).
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: attachments of a specific type (profile, cover, documentation, etc.).
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('attachment_type', $type);
    }

    /**
     * Scope: attachments to a specific entity.
     */
    public function scopeForEntity($query, $type, $id)
    {
        return $query->where('attachable_type', $type)->where('attachable_id', $id);
    }
}
