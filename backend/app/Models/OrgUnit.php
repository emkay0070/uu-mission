<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * OrgUnit — a node in the organizational hierarchy.
 *
 * Self-referencing via parent_id. Circular ancestor detection is enforced
 * at the application layer (the DB CHECK prevents immediate self-loops).
 */
class OrgUnit extends Model
{
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'org_unit_type_id', 'parent_id', 'name', 'short_code',
        'country_code', 'is_international', 'primary_contact_person_id',
        'active_from', 'active_until',
    ];

    protected function casts(): array
    {
        return [
            'is_international' => 'boolean',
            'active_from'      => 'date',
            'active_until'     => 'date',
        ];
    }

    public function type(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OrgUnitType::class, 'org_unit_type_id');
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'parent_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrgUnit::class, 'parent_id');
    }

    public function primaryContact(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class, 'primary_contact_person_id');
    }

    public function members(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrgMembership::class);
    }

    public function campaigns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Campaign::class, 'lead_org_unit_id');
    }
}
