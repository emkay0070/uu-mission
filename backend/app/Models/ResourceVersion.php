<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ResourceVersion — a specific version of a resource file.
 *
 * Authoritative storage for resource files. When a resource is replaced,
 * a new version is created and the resource's current_version_id is updated.
 * Previous versions are preserved for history.
 */
class ResourceVersion extends Model
{
    use HasFactory;

    protected $hidden = ['storage_disk', 'storage_path'];

    protected $fillable = [
        'resource_id',
        'version_number',
        'storage_disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'media_type',
        'file_size',
        'change_reason',
        'replaced_by_user_id',
    ];

    public $timestamps = false;

    /**
     * The resource this version belongs to.
     */
    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * User who created this version (by replacing the file).
     */
    public function replacedBy()
    {
        return $this->belongsTo(User::class, 'replaced_by_user_id');
    }

    /**
     * Reading progress records for this specific version.
     */
    public function readingProgress()
    {
        return $this->hasMany(ResourceReadingProgress::class);
    }

    /**
     * Highlights on this specific version.
     */
    public function highlights()
    {
        return $this->hasMany(ResourceHighlight::class);
    }

    /**
     * Bookmarks on this specific version.
     */
    public function bookmarks()
    {
        return $this->hasMany(ResourceBookmark::class);
    }

    /**
     * Notes on this specific version.
     */
    public function notes()
    {
        return $this->hasMany(ResourceNote::class);
    }

    /**
     * AI conversations about this specific version.
     */
    public function conversations()
    {
        return $this->hasMany(ResourceConversation::class);
    }

    /**
     * Saved AI explanations for this specific version.
     */
    public function savedExplanations()
    {
        return $this->hasMany(ResourceSavedExplanation::class);
    }

    /**
     * Personal learning notes for this specific version.
     */
    public function learningNotes()
    {
        return $this->hasMany(ResourceLearningNote::class);
    }

    /**
     * Document processing record for this version.
     */
    public function documentProcessing()
    {
        return $this->hasOne(ResourceDocumentProcessing::class);
    }

    /**
     * Document chunks for this version.
     */
    public function documentChunks()
    {
        return $this->hasMany(ResourceDocumentChunk::class);
    }

    /**
     * Check if this version is the current version of its resource.
     */
    public function isCurrentVersion(): bool
    {
        return $this->resource && $this->resource->current_version_id === $this->id;
    }

    /**
     * Check if this is a PDF file.
     */
    public function isPdf(): bool
    {
        return $this->media_type === 'pdf';
    }

    /**
     * Check if this is a document file (Word, PowerPoint, etc.).
     */
    public function isDocument(): bool
    {
        return $this->media_type === 'document';
    }

    /**
     * Check if this is an image file.
     */
    public function isImage(): bool
    {
        return $this->media_type === 'image';
    }

    /**
     * Check if this is an audio file.
     */
    public function isAudio(): bool
    {
        return $this->media_type === 'audio';
    }

    /**
     * Check if this is a video file.
     */
    public function isVideo(): bool
    {
        return $this->media_type === 'video';
    }

    /**
     * Get document processing status for API response.
     * Returns null for non-PDF files or when no processing record exists.
     */
    public function getProcessingStatusAttribute(): ?array
    {
        // Only PDFs have processing status
        if (! $this->isPdf()) {
            return null;
        }

        $processing = $this->documentProcessing;

        if (! $processing) {
            // No processing record yet - job may be queued but not started
            return [
                'status' => 'pending',
                'resource_version_id' => $this->id,
            ];
        }

        return [
            'status' => $processing->status,
            'resource_version_id' => $this->id,
            'started_at' => $processing->started_at?->toIso8601String(),
            'completed_at' => $processing->completed_at?->toIso8601String(),
            'total_pages' => $processing->total_pages,
            'extracted_pages' => $processing->extracted_pages,
            'chunk_count' => $processing->chunk_count,
            'error_message' => $processing->getSafeErrorMessage(),
        ];
    }

    /**
     * Append processing status to model array/json output.
     */
    protected $appends = ['processing_status'];
}
