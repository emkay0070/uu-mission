<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ResourceSavedExplanation — a deliberately saved AI explanation.
 *
 * Private to the user. Stores a snapshot of the explanation content
 * and references the original assistant message.
 */
class ResourceSavedExplanation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'resource_id',
        'resource_version_id',
        'conversation_message_id',
        'title',
        'content',
        'page_number',
        'selected_text',
    ];

    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
        ];
    }

    /**
     * The user who saved this explanation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The resource this explanation is about.
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
     * The original assistant message this explanation was saved from.
     */
    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(ResourceConversationMessage::class);
    }

    /**
     * Learning notes that reference this saved explanation.
     */
    public function learningNotes(): HasMany
    {
        return $this->hasMany(ResourceLearningNote::class);
    }

    /**
     * Scope: saved explanations for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: saved explanations for a specific resource.
     */
    public function scopeForResource($query, $resourceId)
    {
        return $query->where('resource_id', $resourceId);
    }
}
