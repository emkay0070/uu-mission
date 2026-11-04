<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'iso_639_1',
        'iso_639_3',
        'supports_translation',
        'supports_stt',
        'supports_tts',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'supports_translation' => 'boolean',
        'supports_stt' => 'boolean',
        'supports_tts' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
