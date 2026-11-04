<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FunctionalRoleType extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'code', 'label', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
