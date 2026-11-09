<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CommunicationRead — one user's read/acknowledgement state for a
 * communication. Created lazily on first interaction; read_at and
 * acknowledged_at are immutable after first assignment.
 */
class CommunicationRead extends Model
{
    use HasFactory;

    protected $fillable = [
        'communication_id', 'user_id', 'read_at', 'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
