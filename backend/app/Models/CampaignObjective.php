<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignObjective extends Model
{
    public $timestamps = false;

    protected $fillable = ['campaign_id', 'title', 'description', 'status', 'sort_order'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
