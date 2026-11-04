<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgMembership extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'person_id', 'org_unit_id', 'membership_type', 'joined_on', 'ended_on',
        'membership_number', 'baptism_date', 'baptism_location',
        'approval_status', 'approved_by_user_id', 'approved_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'joined_on'  => 'date',
            'ended_on'   => 'date',
            'created_at' => 'datetime',
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

    public function approvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
