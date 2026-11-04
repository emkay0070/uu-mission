<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteLogistics extends Model
{
    protected $fillable = [
        'mission_site_id',
        'venue_name',
        'venue_address',
        'venue_contact',
        'venue_confirmed',
        'transport_arrangements',
        'transport_confirmed',
        'accommodation_details',
        'accommodation_confirmed',
        'materials_needed',
        'materials_status',
        'budget_allocated',
        'budget_spent',
        'responsible_person_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'venue_confirmed' => 'boolean',
            'transport_confirmed' => 'boolean',
            'accommodation_confirmed' => 'boolean',
            'budget_allocated' => 'decimal:2',
            'budget_spent' => 'decimal:2',
        ];
    }

    public function site(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'mission_site_id');
    }

    public function responsiblePerson(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class, 'responsible_person_id');
    }
}
