<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * OfficeHeld — historical record of a person holding a formal position.
 *
 * held_until = NULL means currently in office.
 */
class OfficeHeld extends Model
{
    use HasFactory;

    protected $table = 'offices_held';

    public $timestamps = false;

    protected $fillable = [
        'person_id',
        'org_unit_id',
        'office_type_id',
        'held_from',
        'held_until',
    ];

    protected function casts(): array
    {
        return [
            'held_from' => 'date',
            'held_until' => 'date',
        ];
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function orgUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function officeType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OfficeType::class);
    }

    /** Check if this office is currently held. */
    public function isActive(): bool
    {
        return $this->held_until === null;
    }
}
