<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamType extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'label', 'dimension', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function teams(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Team::class);
    }
}
