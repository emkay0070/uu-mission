<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ResourceDownload — tracks offline downloads with version awareness.
 *
 * Downloads are separate from saved resources. A download means the actual
 * file bytes are stored locally and can be opened without internet.
 */
class ResourceDownload extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'resource_id',
        'resource_version_id',
        'file_size',
        'download_status',
        'downloaded_at',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    /**
     * The user who downloaded this resource.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The resource that was downloaded.
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The specific version of the resource that was downloaded.
     */
    public function resourceVersion(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class, 'resource_version_id');
    }

    /**
     * Scope: downloads for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: successfully completed downloads.
     */
    public function scopeCompleted($query)
    {
        return $query->where('download_status', 'completed');
    }

    /**
     * Scope: downloads that may be outdated (version doesn't match current).
     */
    public function scopePotentiallyOutdated($query)
    {
        return $query->whereColumn('resource_version_id', '!=', 'resources.current_version_id');
    }

    /**
     * Check if this download is outdated compared to the current resource version.
     */
    public function isOutdated(): bool
    {
        if (! $this->resource) {
            return true;
        }

        return $this->resource_version_id !== $this->resource->current_version_id;
    }
}
