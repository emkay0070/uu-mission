<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityType extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'code', 'label', 'category', 'requires_attendance_register',
        'expected_duration_minutes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_attendance_register' => 'boolean',
            'is_active'                    => 'boolean',
            'created_at'                   => 'datetime',
        ];
    }

    public function activities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Activity::class);
    }
}
