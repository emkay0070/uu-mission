<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ResourceLearningNote — personal learning notes written by the missionary.
 *
 * Private to the user. Separate from simple ResourceNote annotations.
 * Supports structured note types and references to AI explanations.
 */
class ResourceLearningNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'resource_id',
        'resource_version_id',
        'saved_explanation_id',
        'conversation_message_id',
        'note_type',
        'title',
        'content',
        'chapter',
        'page_number',
        'passage_text',
        'passage_position',
    ];

    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'passage_position' => 'array',
        ];
    }

    /**
     * The user who wrote this note.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The resource this note is about.
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The specific resource version.
     */
    public function resourceVersion(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /**
     * The saved explanation this note references (optional).
     */
    public function savedExplanation(): BelongsTo
    {
        return $this->belongsTo(ResourceSavedExplanation::class);
    }

    /**
     * The conversation message this note references (optional).
     */
    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(ResourceConversationMessage::class);
    }

    /**
     * Scope: notes for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: notes for a specific resource.
     */
    public function scopeForResource($query, $resourceId)
    {
        return $query->where('resource_id', $resourceId);
    }

    /**
     * Scope: notes of a specific type.
     */
    public function scopeWithType($query, $noteType)
    {
        return $query->where('note_type', $noteType);
    }

    /**
     * Scope: notes for a specific chapter.
     */
    public function scopeForChapter($query, $chapter)
    {
        return $query->where('chapter', $chapter);
    }

    /**
     * Scope: chapter-level notes (not whole-book).
     */
    public function scopeChapterLevel($query)
    {
        return $query->whereNotNull('chapter');
    }

    /**
     * Scope: whole-book notes.
     */
    public function scopeWholeBook($query)
    {
        return $query->whereNull('chapter')->whereNull('page_number');
    }
}
