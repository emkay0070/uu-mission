<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceDistributionRelease extends Model
{
    protected $fillable = [
        'resource_id', 'resource_distribution_request_id', 'resource_version_id', 'audience_type',
        'audience_key', 'released_by_user_id', 'released_at', 'revoked_at', 'revoked_by_user_id',
        'revocation_reason',
    ];

    protected function casts(): array
    {
        return ['released_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ResourceDistributionRequest::class, 'resource_distribution_request_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ResourceVersion::class, 'resource_version_id');
    }
}
