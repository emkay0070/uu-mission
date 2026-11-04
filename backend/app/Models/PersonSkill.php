<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonSkill extends Model
{
    protected $fillable = [
        'person_id', 'skill_category', 'skill_name', 'proficiency_level', 'years_experience', 'notes',
    ];

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
