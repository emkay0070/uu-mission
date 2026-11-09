<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ResourceDocumentChunk — extracted text chunk from a resource.
 *
 * Stores page-level text with chapter/heading context for retrieval.
 * Used for book-grounded AI responses and offline search.
 */
class ResourceDocumentChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_version_id',
        'processing_id',
        'page_number',
        'chapter',
        'heading',
        'chunk_order',
        'extracted_text',
        'position_data',
        'embedding',
        'embedding_model',
        'embedding_dimensions',
        'embedding_version',
        'source_text_hash',
    ];

    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'chunk_order' => 'integer',
            'position_data' => 'array',
            'embedding' => 'array',
        ];
    }

    /**
     * The resource version this chunk belongs to.
     */
    public function resourceVersion(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /**
     * Set the embedding vector, converting PHP arrays to pgvector format.
     *
     * pgvector expects input in the format {0.1,0.2,0.3,...}.
     */
    public function setEmbeddingAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['embedding'] = '['.implode(',', array_map(fn ($v) => (string) $v, $value)).']';
        } else {
            $this->attributes['embedding'] = $value;
        }
    }

    /**
     * The processing job that created this chunk.
     */
    public function processing(): BelongsTo
    {
        return $this->belongsTo(ResourceDocumentProcessing::class, 'processing_id');
    }

    /**
     * Scope: chunks for a specific resource version.
     */
    public function scopeForResourceVersion($query, $resourceVersionId)
    {
        return $query->where('resource_version_id', $resourceVersionId);
    }

    /**
     * Scope: chunks on a specific page.
     */
    public function scopeOnPage($query, $pageNumber)
    {
        return $query->where('page_number', $pageNumber);
    }

    /**
     * Scope: chunks in a specific chapter.
     */
    public function scopeInChapter($query, $chapter)
    {
        return $query->where('chapter', $chapter);
    }

    /**
     * Scope: chunks with a specific heading.
     */
    public function scopeWithHeading($query, $heading)
    {
        return $query->where('heading', $heading);
    }

    /**
     * Scope: search chunks by text content.
     */
    public function scopeSearchText($query, $searchTerm)
    {
        return $query->where('extracted_text', 'like', "%{$searchTerm}%");
    }
}
