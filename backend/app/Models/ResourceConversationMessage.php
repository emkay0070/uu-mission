<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ResourceConversationMessage — a message in an AI conversation.
 *
 * Stores user questions and AI responses with context metadata.
 * Supports streaming, retries, and error states.
 */
class ResourceConversationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'page_number',
        'selected_text',
        'context_metadata',
        'provider_metadata',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'context_metadata' => 'array',
            'provider_metadata' => 'array',
        ];
    }

    /**
     * The conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ResourceConversation::class);
    }

    /**
     * Scope: messages with a specific role.
     */
    public function scopeWithRole($query, $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope: completed messages.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: failed messages.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope: pending messages.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Check if this message is from the user.
     */
    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Check if this message is from the assistant.
     */
    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    /**
     * Check if this message completed successfully.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if this message failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
