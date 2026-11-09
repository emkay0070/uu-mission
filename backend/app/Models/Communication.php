<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Communication — an official mission announcement, update or instruction.
 *
 * Each communication targets exactly one scope (global, org_unit, campaign,
 * site or team) and follows a minimal publishing lifecycle:
 *   draft   → not visible to recipients
 *   published → visible to authorised recipients
 *   revoked → soft-deleted; no longer presented as active
 *
 * Historical records are preserved: revoked communications are soft-deleted,
 * never hard-deleted, and their communication_reads tracking rows remain.
 */
class Communication extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_DRAFT = 'draft';

    const STATUS_PUBLISHED = 'published';

    const STATUS_REVOKED = 'revoked';

    const TYPE_ANNOUNCEMENT = 'announcement';

    const TYPE_ORGANISATIONAL_UPDATE = 'organisational_update';

    const TYPE_INSTRUCTION = 'instruction';

    protected $fillable = [
        'author_user_id', 'title', 'body', 'communication_type',
        'scope_type', 'scoped_org_unit_id', 'scoped_campaign_id',
        'scoped_site_id', 'scoped_team_id', 'requires_acknowledgement',
        'status', 'published_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'requires_acknowledgement' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function scopedOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'scoped_org_unit_id');
    }

    public function scopedCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'scoped_campaign_id');
    }

    public function scopedSite(): BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'scoped_site_id');
    }

    public function scopedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'scoped_team_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(CommunicationRead::class);
    }

    /**
     * Scope: published, non-expired, non-soft-deleted communications —
     * the set eligible to be shown to recipients.
     */
    public function scopePublished($query)
    {
        $now = now();

        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            });
    }

    /**
     * Scope: restrict to communications the given user is authorised to
     * receive, based on their receivable scope IDs.
     *
     * @param  array{global: bool, org_units: int[], campaigns: int[],
     *   sites: int[], teams: int[]}  $scopes
     */
    public function scopeReceivableBy($query, array $scopes)
    {
        $clauses = [];

        if ($scopes['global'] ?? false) {
            $clauses[] = fn ($q) => $q->where('scope_type', 'global');
        }

        if ($scopes['org_units'] ?? []) {
            $clauses[] = fn ($q) => $q->where('scope_type', 'org_unit')
                ->whereIn('scoped_org_unit_id', $scopes['org_units']);
        }

        if ($scopes['campaigns'] ?? []) {
            $clauses[] = fn ($q) => $q->where('scope_type', 'campaign')
                ->whereIn('scoped_campaign_id', $scopes['campaigns']);
        }

        if ($scopes['sites'] ?? []) {
            $clauses[] = fn ($q) => $q->where('scope_type', 'site')
                ->whereIn('scoped_site_id', $scopes['sites']);
        }

        if ($scopes['teams'] ?? []) {
            $clauses[] = fn ($q) => $q->where('scope_type', 'team')
                ->whereIn('scoped_team_id', $scopes['teams']);
        }

        if ($clauses === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($clauses) {
            foreach ($clauses as $clause) {
                $q->orWhere($clause);
            }
        });
    }

    /**
     * Return the scope target as (type, id) for authorisation checks.
     *
     * @return array{0: string, 1: int|null}
     */
    public function assignmentScope(): array
    {
        return match ($this->scope_type) {
            'global' => ['global', null],
            'org_unit' => ['org_unit', $this->scoped_org_unit_id],
            'campaign' => ['campaign', $this->scoped_campaign_id],
            'site' => ['site', $this->scoped_site_id],
            'team' => ['team', $this->scoped_team_id],
            default => ['global', null],
        };
    }
}
