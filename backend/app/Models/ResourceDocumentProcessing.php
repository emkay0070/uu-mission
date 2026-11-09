<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ResourceDocumentProcessing — document text extraction tracking.
 *
 * One record per resource version. Tracks extraction status and metadata.
 */
class ResourceDocumentProcessing extends Model
{
    use HasFactory;

    protected $table = 'resource_document_processings';

    protected $fillable = [
        'resource_version_id',
        'status',
        'total_pages',
        'extracted_pages',
        'processing_metadata',
        'started_at',
        'completed_at',
        'retry_count',
        'last_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'total_pages' => 'integer',
            'extracted_pages' => 'integer',
            'processing_metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'retry_count' => 'integer',
            'last_retry_at' => 'datetime',
        ];
    }

    /**
     * The resource version being processed.
     */
    public function resourceVersion(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /**
     * Chunks extracted from this processing job.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(ResourceDocumentChunk::class, 'processing_id')
            ->orderBy('page_number')
            ->orderBy('chunk_order');
    }

    /**
     * Scope: processing records with a specific status.
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: completed processing.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: failed processing.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Check if processing is complete.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if processing failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if processing is in progress.
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Get completion percentage.
     */
    public function completionPercentage(): float
    {
        if ($this->total_pages === null || $this->total_pages === 0) {
            return 0.0;
        }

        return ($this->extracted_pages / $this->total_pages) * 100;
    }

    /**
     * Get chunk count from metadata or count relationship.
     */
    public function getChunkCountAttribute(): int
    {
        // Use metadata if available (from completed processing)
        if (isset($this->processing_metadata['total_chunks'])) {
            return (int) $this->processing_metadata['total_chunks'];
        }

        // Otherwise count from relationship (expensive, use with eager loading)
        return $this->chunks()->count();
    }

    /**
     * Get safe error message for API response.
     */
    public function getSafeErrorMessage(): ?string
    {
        if (! $this->isFailed()) {
            return null;
        }

        $metadata = $this->processing_metadata ?? [];

        // Return a user-friendly error message
        if (isset($metadata['error_message'])) {
            return $metadata['error_message'];
        }

        if (isset($metadata['error'])) {
            // Map common errors to user-friendly messages
            $error = $metadata['error'];
            if (str_contains($error, 'PDF file not found')) {
                return 'PDF file could not be found';
            }
            if (str_contains($error, 'no extractable text')) {
                return 'PDF contains no extractable text (OCR required)';
            }
            if (str_contains($error, 'PDF has no pages')) {
                return 'PDF appears to be empty';
            }

            // Generic safe message
            return 'Processing failed due to an error';
        }

        return 'Processing failed';
    }

    /**
     * Append chunk count to model array/json output.
     */
    protected $appends = ['chunk_count'];
}
