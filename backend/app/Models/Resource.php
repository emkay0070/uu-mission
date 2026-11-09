<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resource — a missionary resource in the central library.
 *
 * Represents approved mission materials with versioned files,
 * scoped visibility, and publishing workflow.
 *
 * File storage is independent of the Media Library - resources
 * have their own storage paths and lifecycle management.
 */
class Resource extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'title',
        'description',
        'category_id',
        'language',
        'intended_audience',
        'publisher',
        'publication_date',
        'version_number',
        'status',
        'current_version_id',
        'thumbnail_storage_disk',
        'thumbnail_storage_path',
        'published_at',
        'archived_at',
        'published_by_user_id',
        'created_by_user_id',
        'updated_by_user_id',
        'origin_assignment_id',
    ];

    protected function casts(): array
    {
        return [
            'publication_date' => 'date',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Category this resource belongs to.
     */
    public function category()
    {
        return $this->belongsTo(ResourceCategory::class, 'category_id');
    }

    /**
     * All versions of this resource (history).
     */
    public function versions()
    {
        return $this->hasMany(ResourceVersion::class);
    }

    public function distributionRequests(): HasMany
    {
        return $this->hasMany(ResourceDistributionRequest::class);
    }

    public function distributionReleases(): HasMany
    {
        return $this->hasMany(ResourceDistributionRelease::class);
    }

    public function originAssignment(): BelongsTo
    {
        return $this->belongsTo(UserRoleAssignment::class, 'origin_assignment_id');
    }

    /**
     * The current/active version of this resource.
     */
    public function currentVersion()
    {
        return $this->belongsTo(ResourceVersion::class, 'current_version_id');
    }

    /**
     * Tags associated with this resource.
     */
    public function tags()
    {
        return $this->hasMany(ResourceTag::class);
    }

    /**
     * Reading progress records for this resource.
     */
    public function readingProgress()
    {
        return $this->hasMany(ResourceReadingProgress::class);
    }

    /**
     * Highlights on this resource.
     */
    public function highlights()
    {
        return $this->hasMany(ResourceHighlight::class);
    }

    /**
     * Bookmarks on this resource.
     */
    public function bookmarks()
    {
        return $this->hasMany(ResourceBookmark::class);
    }

    /**
     * Notes on this resource.
     */
    public function notes()
    {
        return $this->hasMany(ResourceNote::class);
    }

    /**
     * Users who saved this resource.
     */
    public function savedBy()
    {
        return $this->hasMany(ResourceSaved::class);
    }

    /**
     * Users who downloaded this resource.
     */
    public function downloads()
    {
        return $this->hasMany(ResourceDownload::class);
    }

    /**
     * AI conversations about this resource.
     */
    public function conversations()
    {
        return $this->hasMany(ResourceConversation::class);
    }

    /**
     * Saved AI explanations for this resource.
     */
    public function savedExplanations()
    {
        return $this->hasMany(ResourceSavedExplanation::class);
    }

    /**
     * Personal learning notes for this resource.
     */
    public function learningNotes()
    {
        return $this->hasMany(ResourceLearningNote::class);
    }

    /**
     * User who published this resource.
     */
    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * User who created this resource.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * User who last updated this resource.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Scope: only published resources.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope: resources that are not archived.
     */
    public function scopeNotArchived($query)
    {
        return $query->where('status', '!=', 'archived');
    }

    /**
     * Scope: resources available to ordinary missionaries (published, not archived).
     */
    public function scopeAvailable($query)
    {
        return $query->published()->notArchived();
    }

    /**
     * Scope: resources in a specific category.
     */
    public function scopeInCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope: resources with a specific language.
     */
    public function scopeWithLanguage($query, $language)
    {
        return $query->where('language', $language);
    }

    /**
     * Scope: resources of a specific media type (via current version).
     */
    public function scopeWithMediaType($query, $mediaType)
    {
        return $query->whereHas('currentVersion', function ($q) use ($mediaType) {
            $q->where('media_type', $mediaType);
        });
    }

    /**
     * Check if resource has a valid current version.
     */
    public function hasCurrentVersion(): bool
    {
        return $this->current_version_id !== null && $this->currentVersion !== null;
    }

    /**
     * Check if resource is published.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Check if resource is archived.
     */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * Check if resource is in draft status.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if resource is pending review.
     */
    public function isPendingReview(): bool
    {
        return $this->status === 'pending_review';
    }
}
