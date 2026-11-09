<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Communication;
use App\Models\CommunicationRead;
use App\Models\OrgUnit;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * CommunicationService — recipient resolution and read/acknowledgement
 * operations for the Communication Centre.
 *
 * Recipients are resolved from existing platform relationships only:
 *   User → Person → OrgMembership / CampaignAssignment / TeamMember
 *   plus active UserRoleAssignment scopes.
 * No parallel membership system is introduced.
 *
 * Read/acknowledgement records are created lazily and are append-only:
 * read_at and acknowledged_at are never reset once set.
 */
class CommunicationService
{
    /**
     * Determine whether the user is an eligible recipient of communications.
     *
     * Eligibility = fully onboarded missionary: active account, a linked
     * person record and a missionary profile (completed onboarding).
     */
    public function isEligibleRecipient(User $user): bool
    {
        return $user->status === 'active'
            && $user->person !== null
            && $user->person->missionaryProfile !== null;
    }

    /**
     * Compute the scopes the given user can receive communications for.
     *
     * @return array{global: bool, org_units: int[], campaigns: int[],
     *   sites: int[], teams: int[]}
     */
    public function userReceivableScopes(User $user): array
    {
        $scopes = [
            'global' => $this->isEligibleRecipient($user),
            'org_units' => [],
            'campaigns' => [],
            'sites' => [],
            'teams' => [],
        ];

        if (! $scopes['global']) {
            return $scopes;
        }

        $person = $user->person;

        // ── Organisational units the user belongs to ──────────────────
        $membershipOrgUnits = $person
            ? collect($person->orgMemberships()->whereNull('ended_on')->pluck('org_unit_id')->all())
            : collect();

        $roleOrgUnits = collect(UserRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'org_unit')
            ->whereNotNull('scoped_org_unit_id')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->pluck('scoped_org_unit_id')->all());

        $userOrgUnitIds = $membershipOrgUnits->merge($roleOrgUnits)->unique()->values()->all();

        // A communication targeted at an org unit reaches members of that
        // unit or any descendant — so we collect every ancestor-or-self of
        // the user's org units.
        $scopes['org_units'] = $this->ancestorsAndSelf($userOrgUnitIds);

        // ── Campaigns the user belongs to ─────────────────────────────
        $campaignIds = [];

        // Direct campaign assignments (person is deployed to the campaign).
        // Only current (confirmed/arrived) assignments confer receipt;
        // completed/withdrew/no_show assignments no longer grant access.
        if ($person) {
            $campaignIds = array_merge($campaignIds,
                $person->campaignAssignments()
                    ->whereIn('status', ['confirmed', 'arrived'])
                    ->pluck('campaign_id')->all());
        }

        // Active role assignments covering campaigns.
        $roleAssignments = UserRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->get();

        foreach ($roleAssignments as $assignment) {
            if ($assignment->scope_type === 'campaign' && $assignment->scoped_campaign_id) {
                $campaignIds[] = (int) $assignment->scoped_campaign_id;
            } elseif ($assignment->scope_type === 'org_unit' && $assignment->scoped_org_unit_id) {
                // An org_unit appointment covers campaigns led by that unit
                // or any descendant unit.
                foreach ($this->campaignIdsForOrgUnit((int) $assignment->scoped_org_unit_id) as $id) {
                    $campaignIds[] = $id;
                }
            }
        }

        $scopes['campaigns'] = array_unique($campaignIds);

        // ── Sites and teams the user belongs to ───────────────────────
        $siteIds = [];
        $teamIds = [];

        if ($person) {
            // Campaign assignments at a specific site.
            foreach ($person->campaignAssignments()->whereNotNull('mission_site_id')->get() as $a) {
                $siteIds[] = (int) $a->mission_site_id;
            }
            // Team memberships.
            foreach ($person->teamMemberships()->whereNull('ended_on')->get() as $m) {
                $team = $m->team;
                if ($team) {
                    $teamIds[] = (int) $team->id;
                    if ($team->mission_site_id) {
                        $siteIds[] = (int) $team->mission_site_id;
                    }
                }
            }
        }

        // Role assignments covering sites / teams.
        foreach ($roleAssignments as $assignment) {
            if ($assignment->scope_type === 'site' && $assignment->scoped_site_id) {
                $siteIds[] = (int) $assignment->scoped_site_id;
            } elseif ($assignment->scope_type === 'team' && $assignment->scoped_team_id) {
                $teamIds[] = (int) $assignment->scoped_team_id;
            }
        }

        $scopes['sites'] = array_values(array_unique($siteIds));
        $scopes['teams'] = array_values(array_unique($teamIds));

        return $scopes;
    }

    /**
     * Resolve the user IDs who should receive a given communication.
     *
     * Used for potential eager recipient handling and auditing. The list
     * endpoint instead filters communications server-side per user.
     *
     * @return int[]
     */
    public function resolveRecipientUserIds(Communication $communication): array
    {
        $scopes = $communication->scope_type === 'global'
            ? ['global' => true]
            : null;

        $users = User::query()->where('status', 'active')->get();

        return $users->filter(fn (User $user) => $this->canReceive($user, $communication))
            ->pluck('id')
            ->map(fn (int $id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Determine whether the user can receive a specific communication.
     */
    public function canReceive(User $user, Communication $communication): bool
    {
        if (! $this->isEligibleRecipient($user)) {
            return false;
        }

        [$type, $id] = $communication->assignmentScope();

        if ($type === 'global') {
            return true;
        }

        $receivable = $this->userReceivableScopes($user);

        return match ($type) {
            'org_unit' => in_array((int) $id, $receivable['org_units'], true),
            'campaign' => in_array((int) $id, $receivable['campaigns'], true),
            'site' => in_array((int) $id, $receivable['sites'], true),
            'team' => in_array((int) $id, $receivable['teams'], true),
            default => false,
        };
    }

    /**
     * Mark a communication as read for the user. Idempotent: the original
     * read_at timestamp is preserved on subsequent calls.
     */
    public function markAsRead(User $user, Communication $communication): CommunicationRead
    {
        $read = CommunicationRead::firstOrCreate(
            ['communication_id' => $communication->id, 'user_id' => $user->id],
            ['read_at' => now()]
        );

        if ($read->read_at === null) {
            $read->update(['read_at' => now()]);
        }

        return $read->fresh();
    }

    /**
     * Acknowledge a communication for the user. Idempotent. Only permitted
     * when the communication requires acknowledgement.
     *
     * @throws ValidationException when acknowledgement
     *                             is not required for this communication.
     */
    public function markAsAcknowledged(User $user, Communication $communication): CommunicationRead
    {
        if (! $communication->requires_acknowledgement) {
            throw ValidationException::withMessages([
                'acknowledge' => ['This communication does not require acknowledgement.'],
            ]);
        }

        $read = CommunicationRead::firstOrCreate(
            ['communication_id' => $communication->id, 'user_id' => $user->id],
            ['acknowledged_at' => now()]
        );

        if ($read->acknowledged_at === null) {
            $read->update(['acknowledged_at' => now()]);
        }

        return $read->fresh();
    }

    /**
     * Count of currently visible, published, non-expired communications the
     * user is authorised to receive but has not read.
     */
    public function unreadCount(User $user): int
    {
        if (! $this->isEligibleRecipient($user)) {
            return 0;
        }

        $scopes = $this->userReceivableScopes($user);

        $query = Communication::published();

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

        if ($clauses !== []) {
            $query->where(function ($q) use ($clauses) {
                foreach ($clauses as $clause) {
                    $q->orWhere($clause);
                }
            });
        } else {
            return 0;
        }

        return $query->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    /**
     * Communications requiring acknowledgement that the user has not yet
     * acknowledged, ordered most recent first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Communication>
     */
    public function requiringAcknowledgement(User $user): Collection
    {
        if (! $this->isEligibleRecipient($user)) {
            return collect();
        }

        $scopes = $this->userReceivableScopes($user);

        $query = Communication::published()
            ->where('requires_acknowledgement', true)
            ->whereDoesntHave('reads', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->whereNotNull('acknowledged_at');
            });

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

        if ($clauses !== []) {
            $query->where(function ($q) use ($clauses) {
                foreach ($clauses as $clause) {
                    $q->orWhere($clause);
                }
            });
        } else {
            return collect();
        }

        return $query->latest('published_at')->get();
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Return every ancestor-or-self org unit id for the given org unit ids,
     * walking the parent hierarchy.
     *
     * @param  int[]  $orgUnitIds
     * @return int[]
     */
    private function ancestorsAndSelf(array $orgUnitIds): array
    {
        $result = [];
        $frontier = array_values(array_unique(array_filter($orgUnitIds)));

        while ($frontier !== []) {
            $next = [];
            foreach ($frontier as $id) {
                if (in_array($id, $result, true)) {
                    continue;
                }
                $result[] = $id;
                $unit = OrgUnit::find($id);
                if ($unit && $unit->parent_id !== null) {
                    $next[] = (int) $unit->parent_id;
                }
            }
            $frontier = $next;
        }

        return array_values($result);
    }

    /**
     * Return the ids of every campaign led by the given org unit or any of
     * its descendants (mirrors AuthorizationService.orgUnitSubtreeIds).
     *
     * @return int[]
     */
    private function campaignIdsForOrgUnit(int $orgUnitId): array
    {
        $ids = [$orgUnitId];
        $frontier = [$orgUnitId];

        while ($frontier !== []) {
            $children = OrgUnit::whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->diff($ids)
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return Campaign::whereIn('lead_org_unit_id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
