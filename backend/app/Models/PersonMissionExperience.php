<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonMissionExperience extends Model
{
    protected $table = 'person_mission_experience';

    protected $fillable = [
        'person_id', 'mission_name', 'location', 'started_on', 'ended_on', 'role', 'description',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
