<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionArea extends Model
{
    protected $fillable = [
        'name', 'country_code', 'region_name', 'description',
        'latitude', 'longitude', 'boundary_geojson',
    ];

    public function intel(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MissionAreaIntel::class);
    }

    public function campaigns(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_target_areas');
    }

    public function sites(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MissionSite::class);
    }
}
