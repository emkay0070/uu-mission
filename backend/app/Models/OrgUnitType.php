<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrgUnitType extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['code', 'label', 'sort_order', 'can_have_children', 'is_active'];

    protected function casts(): array
    {
        return [
            'can_have_children' => 'boolean',
            'is_active'         => 'boolean',
            'created_at'        => 'datetime',
        ];
    }

    public function orgUnits(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrgUnit::class);
    }
}
