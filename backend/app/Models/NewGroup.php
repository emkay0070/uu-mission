<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewGroup extends Model
{
    protected $fillable = [
        'campaign_id',
        'mission_site_id',
        'name',
        'location_description',
        'contact_person_name',
        'contact_person_phone',
        'stage',
        'regular_attendance',
        'first_meeting_date',
        'leader_person_id',
        'leadership_notes',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'first_meeting_date' => 'date',
            'regular_attendance' => 'integer',
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
}
