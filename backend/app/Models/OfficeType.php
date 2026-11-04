<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * OfficeType — catalogue of formal organisational positions.
 *
 * Examples: Union Youth President, Conference Youth Director, District Youth Coordinator
 */
class OfficeType extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['code', 'label', 'org_unit_type_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function orgUnitType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OrgUnitType::class);
    }

    public function officesHeld(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OfficeHeld::class);
    }
}
