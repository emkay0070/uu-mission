<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ResourceReadingProgress — per-user reading progress for a specific resource version.
 *
 * Progress is version-aware: each user has separate progress for each version.
 * When a resource is replaced, old progress is preserved and new progress starts fresh.
 */
class ResourceReadingProgress extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'user_id',
        'resource_version_id',
        'last_page',
        'total_pages',
        'completion_percentage',
        'last_read_at',
        'reading_position',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'reading_position' => 'array',
            'completion_percentage' => 'decimal:2',
        ];
    }

    /**
     * The resource this progress belongs to.
     */
    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The user who owns this progress.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The specific resource version this progress is for.
     */
    public function resourceVersion()
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /**
     * Check if this progress is for the current version of the resource.
     */
    public function isCurrentVersion(): bool
    {
        if (! $this->resource || ! $this->resource->current_version_id) {
            return false;
        }

        return $this->resource_version_id === $this->resource->current_version_id;
    }

    /**
     * Check if the user has completed reading this version.
     */
    public function isCompleted(): bool
    {
        return $this->completion_percentage >= 100.00;
    }

    /**
     * Scope: progress for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: progress for the current version of each resource.
     */
    public function scopeForCurrentVersion($query)
    {
        return $query->whereColumn('resource_version_id', 'resources.current_version_id')
            ->join('resources', 'resources.id', '=', 'resource_reading_progress.resource_id');
    }

    /**
     * Scope: recently read progress.
     */
    public function scopeRecentlyRead($query, $days = 30)
    {
        return $query->where('last_read_at', '>=', now()->subDays($days));
    }
}
