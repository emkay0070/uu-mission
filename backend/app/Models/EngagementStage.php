<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngagementStage extends Model
{
    protected $fillable = [
        'code',
        'label',
        'color_key',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
