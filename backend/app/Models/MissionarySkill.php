<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionarySkill extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'missionary_profile_id', 'skill_category', 'skill_name', 'proficiency_level',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function profile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionaryProfile::class, 'missionary_profile_id');
    }
}
