<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceDistributionRequest extends Model
{
    protected $fillable = [
        'resource_id', 'requested_by_user_id', 'audience_type', 'audience_key',
        'target_org_unit_id', 'target_campaign_id', 'target_site_id', 'target_team_id',
        'recipient_user_id', 'resource_version_id', 'supersedes_request_id', 'status',
        'submitted_at', 'decided_at', 'decided_by_user_id', 'decision_reason',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class, 'resource_version_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_request_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ResourceDistributionDecision::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(ResourceDistributionRelease::class);
    }
}
