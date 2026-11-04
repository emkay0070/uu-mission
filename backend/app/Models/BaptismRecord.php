<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaptismRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'person_id',
        'campaign_id',
        'mission_site_id',
        'baptism_date',
        'location',
        'officiated_by_person_id',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'baptism_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function outreachContact(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OutreachContact::class, 'person_id');
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function site(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'mission_site_id');
    }

    public function officiatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class, 'officiated_by_person_id');
    }

    public function verifiedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function scopeVerified($query)
    {
        return $query->where('verification_status', 'verified');
    }

    public function scopePending($query)
    {
        return $query->where('verification_status', 'pending');
    }
}
