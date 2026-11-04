<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRegister extends Model
{
    protected $fillable = [
        'campaign_id', 'mission_site_id', 'activity_id', 'register_name',
        'register_date', 'register_type', 'status',
        'submitted_by_user_id', 'submitted_at', 'verified_by_user_id', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'register_date' => 'date',
            'submitted_at'  => 'datetime',
            'verified_at'   => 'datetime',
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

    public function activity(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function entries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AttendanceEntry::class);
    }

    public function submittedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function verifiedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
