<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ResourceConversation — private AI conversation about a resource.
 *
 * Private to the user who created it. Linked to a specific resource version
 * to preserve conversations when resources are updated.
 */
class ResourceConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'resource_id',
        'resource_version_id',
        'title',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * The user who owns this conversation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The resource this conversation is about.
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The specific resource version this conversation is about.
     */
    public function resourceVersion(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class);
    }

    /**
     * Messages in this conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ResourceConversationMessage::class, 'conversation_id')->orderBy('created_at');
    }

    /**
     * Scope: conversations for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: conversations for a specific resource.
     */
    public function scopeForResource($query, $resourceId)
    {
        return $query->where('resource_id', $resourceId);
    }

    /**
     * Scope: conversations for a specific resource version.
     */
    public function scopeForResourceVersion($query, $resourceVersionId)
    {
        return $query->where('resource_version_id', $resourceVersionId);
    }

    /**
     * Scope: conversations with recent activity.
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('last_message_at', '>=', now()->subDays($days));
    }
}
