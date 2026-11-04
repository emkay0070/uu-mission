<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Media — central media library for UU Mission platform.
 *
 * Stores photographs, videos, audio recordings and documents.
 * Reusable across profiles, campaigns, sites, teams, activities and future community posts.
 */
class Media extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'original_filename',
        'stored_filename',
        'mime_type',
        'media_type',
        'storage_disk',
        'storage_path',
        'thumbnail_path',
        'file_size',
        'width',
        'height',
        'duration',
        'title',
        'caption',
        'uploaded_by_user_id',
        'capture_date',
        'visibility',
        'processing_status',
        'requires_consent',
        'consent_obtained',
        'consent_notes',
    ];

    protected function casts(): array
    {
        return [
            'capture_date' => 'date',
            'requires_consent' => 'boolean',
            'consent_obtained' => 'boolean',
        ];
    }

    /**
     * User who uploaded this media.
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Collections this media belongs to.
     */
    public function collections()
    {
        return $this->belongsToMany(MediaCollection::class, 'media_collection_items')
                    ->withPivot('sort_order')
                    ->withTimestamps()
                    ->orderBy('media_collection_items.sort_order');
    }

    /**
     * Consent records for this media.
     */
    public function consents()
    {
        return $this->hasMany(MediaConsent::class);
    }

    /**
     * Attachment records linking this media to entities.
     */
    public function attachments()
    {
        return $this->hasMany(MediaAttachment::class);
    }

    /**
     * Get the full URL to the original file.
     * Enforces visibility check - private media returns null.
     */
    public function getUrl(): ?string
    {
        if ($this->visibility === 'private') {
            return null;
        }
        
        return \Storage::disk($this->storage_disk)->url($this->storage_path);
    }

    /**
     * Get the full URL to the thumbnail.
     * Enforces visibility check - private media returns null.
     */
    public function getThumbnailUrl(): ?string
    {
        if (!$this->thumbnail_path || $this->visibility === 'private') {
            return null;
        }
        
        return \Storage::disk($this->storage_disk)->url($this->thumbnail_path);
    }

    /**
     * Check if media is an image.
     */
    public function isImage(): bool
    {
        return $this->media_type === 'image';
    }

    /**
     * Check if media is a video.
     */
    public function isVideo(): bool
    {
        return $this->media_type === 'video';
    }

    /**
     * Check if media is audio.
     */
    public function isAudio(): bool
    {
        return $this->media_type === 'audio';
    }

    /**
     * Check if media is a document.
     */
    public function isDocument(): bool
    {
        return $this->media_type === 'document';
    }

    /**
     * Check if media processing is complete.
     */
    public function isProcessed(): bool
    {
        return $this->processing_status === 'completed';
    }

    /**
     * Check if media can be published (has consent where required).
     */
    public function canBePublished(): bool
    {
        if ($this->requires_consent && !$this->consent_obtained) {
            return false;
        }
        
        return $this->visibility !== 'private';
    }

    /**
     * Scope: media uploaded by a specific user.
     */
    public function scopeUploadedBy($query, $userId)
    {
        return $query->where('uploaded_by_user_id', $userId);
    }

    /**
     * Scope: media of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('media_type', $type);
    }

    /**
     * Scope: media with specific visibility.
     */
    public function scopeWithVisibility($query, $visibility)
    {
        return $query->where('visibility', $visibility);
    }

    /**
     * Scope: media that has been processed.
     */
    public function scopeProcessed($query)
    {
        return $query->where('processing_status', 'completed');
    }
}
