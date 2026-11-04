<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonAddress extends Model
{
    protected $fillable = [
        'person_id', 'country', 'region', 'district', 'county_municipality',
        'parish_town', 'village_area', 'physical_address', 'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
