<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionaryProfile extends Model
{
    protected $fillable = [
        'person_id', 'home_org_unit_id', 'deployment_status', 'bio', 'training_notes',
    ];

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function homeOrgUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'home_org_unit_id');
    }

    public function skills(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(PersonSkill::class, Person::class, 'id', 'person_id', 'person_id', 'id');
    }

    public function languages(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(PersonLanguage::class, Person::class, 'id', 'person_id', 'person_id', 'id');
    }
}
