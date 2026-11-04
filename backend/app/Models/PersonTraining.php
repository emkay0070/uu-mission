<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonTraining extends Model
{
    protected $table = 'person_training';

    protected $fillable = [
        'person_id', 'training_type', 'training_name', 'training_provider',
        'completed_on', 'expires_on', 'certificate_number', 'verification_status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'completed_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
