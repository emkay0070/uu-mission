<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionaryLanguage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'missionary_profile_id', 'language_code', 'is_native', 'proficiency_level',
    ];

    protected function casts(): array
    {
        return [
            'is_native'  => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function profile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionaryProfile::class, 'missionary_profile_id');
    }
}
