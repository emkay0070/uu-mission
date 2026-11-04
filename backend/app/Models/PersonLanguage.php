<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonLanguage extends Model
{
    protected $fillable = [
        'person_id', 'language_code', 'is_native', 'proficiency_level', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_native' => 'boolean',
        ];
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function language(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_code', 'code');
    }
}
