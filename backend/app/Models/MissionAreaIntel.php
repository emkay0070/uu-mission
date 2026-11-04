<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionAreaIntel extends Model
{
    protected $fillable = [
        'mission_area_id', 'intel_category', 'content', 'is_verified',
        'source_description', 'contributed_by_person_id', 'observed_on', 'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'is_verified'  => 'boolean',
            'observed_on'  => 'date',
        ];
    }

    public function missionArea(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionArea::class);
    }

    public function contributor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class, 'contributed_by_person_id');
    }

    public function supersededBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
