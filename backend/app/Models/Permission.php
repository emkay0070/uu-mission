<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'label', 'module'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(SystemRole::class, 'role_permissions');
    }
}
