<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityParticipant extends Model
{
    protected $fillable = [
        'activity_id', 'person_id', 'joined_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'joined_date' => 'date',
        ];
    }

    public function activity(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
