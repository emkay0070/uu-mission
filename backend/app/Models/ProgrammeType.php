<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgrammeType extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'label', 'ministry_owner', 'default_requirements', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function programmes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Programme::class);
    }
}
