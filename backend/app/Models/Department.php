<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Department — operational group within a campaign or site.
 *
 * Examples: Youth Ministries, Women's Ministries, Children's Ministries
 * Can be campaign-wide or site-specific.
 */
class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'mission_site_id',
        'name',
        'leader_person_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function site(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'mission_site_id');
    }

    public function leader(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class, 'leader_person_id');
    }

    public function members(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeptMember::class);
    }

    /** Check if this department is site-specific. */
    public function isSiteSpecific(): bool
    {
        return $this->mission_site_id !== null;
    }
}
