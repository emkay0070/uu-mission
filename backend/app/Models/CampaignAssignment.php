<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignAssignment extends Model
{
    protected $fillable = [
        'campaign_id', 'person_id', 'mission_site_id', 'assigned_team_id',
        'functional_role_type_id', 'system_role_id',
        'status', 'expected_arrival', 'actual_arrival', 'departure',
        'notes', 'assigned_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_arrival' => 'date',
            'actual_arrival'   => 'date',
            'departure'        => 'date',
        ];
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function site(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'mission_site_id');
    }

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    public function assignedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function functionalRoleType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FunctionalRoleType::class, 'functional_role_type_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsToThrough
    {
        return $this->belongsToThrough(User::class, Person::class);
    }
}
