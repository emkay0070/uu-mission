<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * MissionSite — an operational location within a campaign.
 *
 * The composite unique (id, campaign_id) is used by child tables
 * (teams, departments, committees, activities, attendance registers,
 * field reports, outcomes) to hold composite FKs that enforce
 * site-campaign consistency (Correction 2).
 */
class MissionSite extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'mission_area_id', 'name', 'description',
        'location_description', 'latitude', 'longitude',
        'status', 'operational_from', 'operational_until',
    ];

    protected function casts(): array
    {
        return [
            'latitude'          => 'decimal:6',
            'longitude'         => 'decimal:6',
            'operational_from'  => 'date',
            'operational_until' => 'date',
        ];
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function missionArea(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionArea::class);
    }

    public function teams(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function activities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function attendanceRegisters(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AttendanceRegister::class);
    }

    public function fieldReports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FieldReport::class);
    }

    public function outcomes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MissionOutcome::class);
    }

    public function assignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignAssignment::class);
    }

    public function logistics(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SiteLogistics::class, 'mission_site_id');
    }

    public function mediaAttachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'attachable');
    }
}
