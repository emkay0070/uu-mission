<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\AttendanceRegister;
use App\Models\FieldReport;
use App\Models\MissionOutcome;
use App\Models\TeamMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ReportPreparationService — compiles live fieldwork into a report.
 *
 * THE PROBLEM THIS SOLVES
 * -----------------------
 * Before this service existed a secretary had to re-type numbers that the
 * platform already held: how many activities ran, how many people attended,
 * what outcomes were recorded. Re-typing stored data is slow and, worse, it
 * lets the report and the record drift apart — the report says 40, the
 * registers say 43, and nobody can tell which is true.
 *
 * This service reads the authoritative source rows for a report's period and
 * scope and produces a single, honest preparation snapshot. The secretary
 * writes only what a machine cannot know: narrative, observations, testimonies,
 * recommendations and prayer requests.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 *   - It never writes to attendance, activities or outcomes. It is a read.
 *   - It never adds the two attendance totals together. Site attendance and
 *     activity attendance are independent registers and the same person
 *     legitimately appears in both; summing them would count a person twice.
 *   - It never invents a missing figure. When data is absent it says so in
 *     `missing`, rather than emitting a zero that looks like a measured value.
 *   - It never produces a completion PERCENTAGE. A single number would imply a
 *     precision the underlying records do not have, so the snapshot lists what
 *     is present and what is still outstanding instead.
 *   - It never rewrites a submitted report. `reconcile()` compares today's
 *     source records against the preserved submitted version and reports the
 *     difference for a human to accept deliberately.
 *
 * ATTRIBUTION AND SCOPE
 * ---------------------
 *   site      → every register and activity at the report's site, for the period
 *   campaign  → every register and activity in the campaign, for the period
 *   team      → only what is genuinely attributable to that team with the
 *               current schema: the attendance of its own members and the
 *               activities led by them. Activities and registers are not (yet)
 *               attributed to teams, so a team report must not claim the whole
 *               site or it would double-count against a sibling team. This
 *               limitation is stated in the snapshot itself.
 */
class ReportPreparationService
{
    /**
     * Format version of the preparation snapshot. Bump when the shape changes
     * so that a stored `preparation_meta` can be told apart from a current one.
     */
    public const FORMAT_VERSION = 1;

    /**
     * Compile the current preparation snapshot for a report. PURE READ — the
     * report is not modified. Persist the result with persist() when a frozen
     * copy is wanted (e.g. at submission).
     *
     * @return array<string, mixed>
     */
    public function compile(FieldReport $report): array
    {
        $report->loadMissing(['site', 'team', 'campaign']);

        $start = $report->period_start->copy()->startOfDay();
        $end   = $report->period_end->copy()->endOfDay();

        // Team-level reports are narrowed to team-attributable sources; site and
        // campaign reports cover their whole scope.
        $teamPersonIds = $report->isTeamLevel() ? $this->teamPersonIds($report) : null;

        $activities = $this->gatherActivities($report, $start, $end, $teamPersonIds);
        $registers  = $this->gatherRegisters($report, $teamPersonIds);

        $siteRegisters     = $registers->whereNull('activity_id');
        $activityRegisters = $registers->whereNotNull('activity_id');

        $outcomes = $this->gatherOutcomes($report, $start, $end, $teamPersonIds);

        $compiled = [
            'format_version' => self::FORMAT_VERSION,
            'generated_at'   => now()->toIso8601String(),
            'period'         => [
                'start' => $report->period_start->toDateString(),
                'end'   => $report->period_end->toDateString(),
            ],
            'level'  => $report->report_level,
            'scoping' => $this->scoping($report),
            'activities' => $this->summariseActivities($activities, $activityRegisters),
            'attendance' => [
                'site_attendance'     => $this->summariseRegisters($siteRegisters),
                'activity_attendance' => $this->summariseRegisters($activityRegisters),
                // Stated in the payload itself so no consumer adds two numbers
                // that overlap.
                'note' => 'Site attendance and activity attendance are independent records and are never added together.',
            ],
            'participation' => $this->summariseParticipation($activities),
            'outcomes'      => $this->summariseOutcomes($outcomes),
        ];

        // Sources are recorded explicitly so a later reconciliation can say
        // exactly which rows changed rather than "something changed".
        $compiled['sources'] = [
            'activity_ids'            => $activities->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'attendance_register_ids' => $registers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'outcome_ids'             => $outcomes->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];

        $compiled['fingerprint'] = $this->fingerprintFrom($activities, $registers, $outcomes);

        // What a human must still supply, and what the platform already knows.
        $compiled['completeness'] = $this->completeness($report, $activities, $registers, $outcomes);
        $compiled['missing']      = $this->missingInformation($report, $activities, $registers, $outcomes, $start, $end);

        return $compiled;
    }

    /**
     * Compile and store the snapshot on the report's `preparation_meta`.
     *
     * Used when a draft is frozen (submission) and when a user explicitly asks
     * the workspace to recalculate. It never touches human-authored content or
     * the historical `stat_*` columns.
     */
    public function persist(FieldReport $report): FieldReport
    {
        $snapshot = $this->compile($report);

        $report->forceFill(['preparation_meta' => $snapshot])->save();

        return $report->fresh();
    }

    /**
     * Generate a report from the records that EXIST, when a lower-level
     * submission is delayed.
     *
     * Example: a Site Secretary prepares the site report even though one Team
     * Secretary has not submitted yet.
     *
     * Everything the platform can supply is compiled as usual (compile()); on
     * top of that this records an explicit classification so a reader can always
     * tell which information is verified, which was generated automatically,
     * which a human wrote, which is missing, and which reviews are outstanding.
     *
     * NOTHING is invented: no figures, no outcomes, no narrative — absent data
     * is reported as missing, never as a zero. And generating is deliberately
     * NOT a submission: the report stays a draft and must be reviewed before it
     * is formally submitted.
     */
    public function recover(FieldReport $report): FieldReport
    {
        $compiled = $this->compile($report);

        $compiled['recovery'] = [
            'generated_at' => now()->toIso8601String(),
            'basis'        => 'Generated from the field records available at this time.',
            'verified'     => $this->recoveryVerified($compiled),
            'auto_generated' => [
                'activities'                  => $compiled['activities']['count'],
                'site_attendance_entries'     => $compiled['attendance']['site_attendance']['entries'],
                'activity_attendance_entries' => $compiled['attendance']['activity_attendance']['entries'],
                'outcomes'                    => $compiled['outcomes']['total'],
            ],
            'manually_supplemented' => $compiled['completeness']['human_sections']['written'],
            'missing'               => $compiled['missing'],
            'pending_reviews'       => $this->pendingLowerReviews($report),
            'note' => 'Generating a report is not approval. Verified, automatically generated, '
                .'manually supplemented, missing and pending-review information are listed separately, '
                .'and nothing has been invented.',
        ];

        $report->forceFill(['preparation_meta' => $compiled])->save();

        return $report->fresh();
    }

    /**
     * The figures a recovery can present as verified: entries taken from
     * registers whose review has been completed.
     *
     * @param  array<string, mixed>  $compiled
     * @return array<string, mixed>
     */
    private function recoveryVerified(array $compiled): array
    {
        $site     = $compiled['attendance']['site_attendance']['register_status']['verified'];
        $activity = $compiled['attendance']['activity_attendance']['register_status']['verified'];

        return [
            'verified_registers' => $site + $activity,
            'note'               => 'Entries taken from registers that have been verified.',
        ];
    }

    /**
     * Lower-level reports covering the same period that have NOT been submitted.
     *
     * These are the delayed or missing submissions a recovery exists to work
     * around. They are listed by identity so the officer can chase them by name
     * — they are never guessed at or filled in.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingLowerReviews(FieldReport $report): array
    {
        $childLevel = $report->isCampaignLevel()
            ? 'site'
            : ($report->isSiteLevel() ? 'team' : null);

        if ($childLevel === null) {
            return [];
        }

        $query = FieldReport::query()
            ->where('campaign_id', $report->campaign_id)
            ->where('id', '!=', $report->id)
            ->where('report_level', $childLevel)
            ->whereNotIn('status', ['submitted', 'reviewed', 'approved'])
            ->whereDate('period_start', '<=', $report->period_end->toDateString())
            ->whereDate('period_end', '>=', $report->period_start->toDateString());

        if ($childLevel === 'team' && $report->mission_site_id !== null) {
            $query->where('mission_site_id', $report->mission_site_id);
        }

        return $query->get()
            ->map(fn (FieldReport $c) => [
                'id'              => $c->id,
                'report_level'    => $c->report_level,
                'team_id'         => $c->team_id,
                'mission_site_id' => $c->mission_site_id,
                'status'          => $c->status,
                'review_state'    => $c->review_state,
            ])
            ->values()
            ->all();
    }

    /**
     * Compare today's source records against the report's most recently
     * preserved submitted version.
     *
     * This is what lets a late register or a corrected figure be surfaced
     * WITHOUT silently rewriting an already-submitted report: the change is
     * described here, and a human decides whether to reconcile it.
     *
     * @return array<string, mixed>
     */
    public function reconcile(FieldReport $report): array
    {
        $preserved = $report->versions()->first();

        if ($preserved === null) {
            return [
                'has_preserved_version' => false,
                'preserved_version'     => null,
                'changed'               => false,
                'added'                 => $this->emptyIdSets(),
                'removed'               => $this->emptyIdSets(),
                'message'               => null,
            ];
        }

        $current  = $this->compile($report);
        $snapshot = is_array($preserved->snapshot) ? $preserved->snapshot : [];
        $previous = $snapshot['preparation'] ?? [];

        $previousSources = $previous['sources'] ?? $this->emptyIdSets();
        $currentSources  = $current['sources'];

        $added   = $this->diffIdSets($currentSources, $previousSources);
        $removed = $this->diffIdSets($previousSources, $currentSources);

        $changed = $added !== $this->emptyIdSets() || $removed !== $this->emptyIdSets();

        return [
            'has_preserved_version' => true,
            'preserved_version'     => [
                'version_number' => $preserved->version_number,
                'status'         => $preserved->status,
                'review_state'   => $preserved->review_state,
                'created_at'     => $preserved->created_at?->toIso8601String(),
            ],
            'changed' => $changed,
            'added'   => $added,
            'removed' => $removed,
            'message' => $changed
                ? 'Source records have changed since this report was formally submitted. '
                    .'The submitted version is preserved; review the changes and reconcile deliberately.'
                : null,
        ];
    }

    /**
     * A stable fingerprint of the source rows a report draws on.
     *
     * Stored inside every preparation snapshot and every preserved version, so
     * two snapshots can be compared cheaply.
     */
    public function fingerprint(FieldReport $report): string
    {
        $start = $report->period_start->copy()->startOfDay();
        $end   = $report->period_end->copy()->endOfDay();

        $teamPersonIds = $report->isTeamLevel() ? $this->teamPersonIds($report) : null;

        return $this->fingerprintFrom(
            $this->gatherActivities($report, $start, $end, $teamPersonIds),
            $this->gatherRegisters($report, $teamPersonIds),
            $this->gatherOutcomes($report, $start, $end, $teamPersonIds),
        );
    }

    // ── Source gathering ─────────────────────────────────────────────────────

    /**
     * Activities whose run date falls in the report period.
     *
     * A planned activity carries `scheduled_at`; an activity recorded without a
     * schedule (a genuinely unplanned occurrence) is dated by when it was
     * created. Both are included — an unplanned activity is still fieldwork.
     *
     * @param  array<int, int>|null  $teamPersonIds  null = no team restriction
     * @return Collection<int, Activity>
     */
    private function gatherActivities(FieldReport $report, Carbon $start, Carbon $end, ?array $teamPersonIds): Collection
    {
        $query = Activity::query()
            ->where('campaign_id', $report->campaign_id)
            ->with(['objectives', 'attendanceRegisters'])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('scheduled_at', [$start, $end])
                  ->orWhere(function ($q2) use ($start, $end) {
                      $q2->whereNull('scheduled_at')->whereBetween('created_at', [$start, $end]);
                  });
            });

        // Site reports (and team reports, via their team's site) are limited to
        // the site; campaign reports span the campaign.
        if ($report->mission_site_id !== null) {
            $query->where('mission_site_id', $report->mission_site_id);
        }

        $activities = $query->orderBy('scheduled_at')->get();

        if ($teamPersonIds !== null) {
            $activities = $activities->filter(
                fn (Activity $a) => $a->led_by_person_id !== null
                    && in_array((int) $a->led_by_person_id, $teamPersonIds, true)
            )->values();
        }

        return $activities;
    }

    /**
     * Attendance registers dated within the report period.
     *
     * For a team report the registers are limited to those whose entries include
     * a team member — the only truthful team attribution the schema allows.
     *
     * @param  array<int, int>|null  $teamPersonIds
     * @return Collection<int, AttendanceRegister>
     */
    private function gatherRegisters(FieldReport $report, ?array $teamPersonIds): Collection
    {
        $query = AttendanceRegister::query()
            ->where('campaign_id', $report->campaign_id)
            ->whereBetween('register_date', [
                $report->period_start->toDateString(),
                $report->period_end->toDateString(),
            ])
            ->with(['entries', 'activity']);

        if ($report->mission_site_id !== null) {
            $query->where('mission_site_id', $report->mission_site_id);
        }

        $registers = $query->get();

        if ($teamPersonIds !== null) {
            $registers = $registers->filter(function (AttendanceRegister $r) use ($teamPersonIds) {
                return $r->entries->contains(
                    fn ($entry) => $entry->person_id !== null
                        && in_array((int) $entry->person_id, $teamPersonIds, true)
                );
            })->values();
        }

        return $registers;
    }

    /**
     * Mission outcomes dated within the report period.
     *
     * @param  array<int, int>|null  $teamPersonIds
     * @return Collection<int, MissionOutcome>
     */
    private function gatherOutcomes(FieldReport $report, Carbon $start, Carbon $end, ?array $teamPersonIds): Collection
    {
        $query = MissionOutcome::query()
            ->where('campaign_id', $report->campaign_id)
            ->whereBetween('outcome_date', [$start->toDateString(), $end->toDateString()]);

        if ($report->mission_site_id !== null) {
            $query->where('mission_site_id', $report->mission_site_id);
        }

        $outcomes = $query->get();

        if ($teamPersonIds !== null) {
            $outcomes = $outcomes->filter(
                fn (MissionOutcome $o) => $o->person_id !== null
                    && in_array((int) $o->person_id, $teamPersonIds, true)
            )->values();
        }

        return $outcomes;
    }

    /**
     * The permanent person ids of a team's members, past and present.
     *
     * Membership that has ended is INCLUDED: a person who served on the team
     * during the report period contributed to it, and history must not be
     * rewritten because they later left.
     *
     * @return array<int, int>
     */
    private function teamPersonIds(FieldReport $report): array
    {
        if ($report->team_id === null) {
            return [];
        }

        return TeamMember::where('team_id', $report->team_id)
            ->pluck('person_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    // ── Summarising ──────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, Activity>  $activities
     * @param  Collection<int, AttendanceRegister>  $activityRegisters
     * @return array<string, mixed>
     */
    private function summariseActivities(Collection $activities, Collection $activityRegisters): array
    {
        $registersByActivity = $activityRegisters->groupBy('activity_id');

        $items = $activities->map(function (Activity $activity) use ($registersByActivity) {
            $registers = $registersByActivity->get($activity->id, collect());

            return [
                'id'           => $activity->id,
                'title'        => $activity->title,
                'status'       => $activity->status,
                'is_planned'   => (bool) $activity->is_planned,
                'scheduled_at' => $activity->scheduled_at?->toIso8601String(),
                'objectives'   => $activity->objectives
                    ->map(fn ($o) => ['id' => $o->id, 'title' => $o->title])
                    ->values()
                    ->all(),
                'attendance'   => [
                    'register_count' => $registers->count(),
                    // 'not_recorded' is deliberate: no register means nobody has
                    // recorded attendance yet — it is not an attendance of zero.
                    'state'          => $registers->isEmpty() ? 'not_recorded' : 'recorded',
                    'entry_count'    => $registers->sum(fn (AttendanceRegister $r) => $r->entries->count()),
                ],
            ];
        })->values()->all();

        return [
            'count'      => $activities->count(),
            'planned'    => $activities->where('is_planned', true)->count(),
            'unplanned'  => $activities->where('is_planned', false)->count(),
            'completed'  => $activities->where('status', 'completed')->count(),
            'cancelled'  => $activities->where('status', 'cancelled')->count(),
            'in_progress'=> $activities->where('status', 'in_progress')->count(),
            'scheduled'  => $activities->where('status', 'scheduled')->count(),
            'items'      => $items,
        ];
    }

    /**
     * Aggregate a set of registers by identity form.
     *
     * @param  Collection<int, AttendanceRegister>  $registers
     * @return array<string, mixed>
     */
    private function summariseRegisters(Collection $registers): array
    {
        $totals = $this->zeroEntryCounts();

        $open = 0;
        $submitted = 0;
        $verified = 0;

        foreach ($registers as $register) {
            $counts = $this->countEntries($register->entries);

            $totals['registers']++;
            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }

            match ($register->status) {
                'verified'  => $verified++,
                'submitted' => $submitted++,
                default     => $open++,
            };
        }

        return [
            'registers'    => $totals['registers'],
            'entries'      => $totals['entries'],
            'identified'   => $totals['identified'],
            'named_unidentified' => $totals['named_unidentified'],
            'anonymous'    => $totals['anonymous'],
            'walk_in'      => $totals['walk_in'],
            'first_time'   => $totals['first_time'],
            'decisions_recorded' => $totals['decisions_recorded'],
            // Review status of the underlying records. A report built on open
            // registers is flagged so it is not mistaken for final figures.
            'register_status' => [
                'open'      => $open,
                'submitted' => $submitted,
                'verified'  => $verified,
            ],
        ];
    }

    /**
     * Planned roster versus recorded attendance for the in-scope activities.
     *
     * The two numbers answer different questions — what was planned and what was
     * recorded — and are never presented as a ratio.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<string, mixed>
     */
    private function summariseParticipation(Collection $activities): array
    {
        $activityIds = $activities->pluck('id')->all();

        $planned = $activityIds === []
            ? 0
            : ActivityParticipant::whereIn('activity_id', $activityIds)->count();

        return [
            'planned_roster_total' => $planned,
            'note' => 'The roster is what was planned; attendance is what was recorded. '
                .'They are not expected to match and are not shown as a ratio.',
        ];
    }

    /**
     * @param  Collection<int, MissionOutcome>  $outcomes
     * @return array<string, mixed>
     */
    private function summariseOutcomes(Collection $outcomes): array
    {
        $byType = $outcomes
            ->groupBy('outcome_type')
            ->map(fn (Collection $group, $type) => [
                'outcome_type' => $type,
                'count'        => $group->count(),
            ])
            ->values()
            ->all();

        $awaitingFollowUp = $outcomes
            ->whereIn('follow_up_status', ['pending', 'assigned'])
            ->count();

        return [
            'total'             => $outcomes->count(),
            'by_type'           => $byType,
            'awaiting_follow_up'=> $awaitingFollowUp,
        ];
    }

    /**
     * Count one register's entries by identity form and arrival snapshot.
     * Mirrors AttendanceController::countEntryForms() so the same rows are
     * always classified the same way.
     *
     * @param  Collection<int, \App\Models\AttendanceEntry>  $entries
     * @return array<string, int>
     */
    private function countEntries(Collection $entries): array
    {
        $counts = $this->zeroEntryCounts();
        unset($counts['registers']);

        foreach ($entries as $entry) {
            $counts['entries']++;

            if ($entry->person_id !== null) {
                $counts['identified']++;
            } elseif ($entry->is_anonymous) {
                $counts['anonymous']++;
            } else {
                $counts['named_unidentified']++;
            }

            if ($entry->is_walk_in) {
                $counts['walk_in']++;
            }
            if ($entry->is_first_time) {
                $counts['first_time']++;
            }
            if ($entry->decision_made !== null) {
                $counts['decisions_recorded']++;
            }
        }

        return $counts;
    }

    // ── Completeness, missing information, scoping ───────────────────────────

    /**
     * What the platform supplied automatically, and what a human must still
     * write. Deliberately lists rather than scores — no completion percentage.
     *
     * @return array<string, mixed>
     */
    private function completeness(FieldReport $report, Collection $activities, Collection $registers, Collection $outcomes): array
    {
        $humanFields = [
            'narrative', 'challenges', 'observations', 'testimonies',
            'recommendations', 'prayer_requests',
        ];

        $awaitingHuman = [];
        foreach ($humanFields as $field) {
            if (trim((string) ($report->$field ?? '')) === '') {
                $awaitingHuman[] = $field;
            }
        }

        return [
            'auto_generated' => [
                'activities'  => $activities->count(),
                'attendance_registers' => $registers->count(),
                'outcomes'    => $outcomes->count(),
            ],
            'human_sections' => [
                'written'  => array_values(array_diff($humanFields, $awaitingHuman)),
                'awaiting' => $awaitingHuman,
            ],
            'note' => 'Attendance figures, activity lists and outcome counts are compiled from the '
                .'field records; they are not re-entered on the report.',
        ];
    }

    /**
     * Human-readable statements of what is absent, so a reviewer is never left
     * to assume a blank field means zero.
     *
     * @return array<int, string>
     */
    private function missingInformation(
        FieldReport $report,
        Collection $activities,
        Collection $registers,
        Collection $outcomes,
        Carbon $start,
        Carbon $end
    ): array {
        $missing = [];

        if ($end->isFuture()) {
            $missing[] = 'The reporting period has not ended; the figures may still change.';
        }

        if ($activities->isEmpty()) {
            $missing[] = 'No activities are recorded for this period.';
        }

        $withoutRegister = $activities->filter(function (Activity $a) {
            return $a->attendanceRegisters->isEmpty();
        })->count();

        if ($withoutRegister > 0) {
            $missing[] = "{$withoutRegister} activity(ies) have no attendance register.";
        }

        $open = $registers->where('status', 'open')->count();
        if ($open > 0) {
            $missing[] = "{$open} attendance register(s) are still open and not yet submitted.";
        }

        $unverified = $registers->whereIn('status', ['open', 'submitted'])->count();
        if ($unverified > 0) {
            $missing[] = "{$unverified} attendance register(s) are not yet verified.";
        }

        if ($outcomes->isEmpty()) {
            $missing[] = 'No outcomes are recorded for this period.';
        } else {
            $awaiting = $outcomes->whereIn('follow_up_status', ['pending', 'assigned'])->count();
            if ($awaiting > 0) {
                $missing[] = "{$awaiting} recorded outcome(s) still await follow-up.";
            }
        }

        if (trim((string) ($report->narrative ?? '')) === '') {
            $missing[] = 'The narrative has not been written yet.';
        }

        return $missing;
    }

    /**
     * Describe, in the payload itself, exactly what this report covers — so a
     * reader never has to infer whether a number is site-wide or team-only.
     *
     * @return array<string, mixed>
     */
    private function scoping(FieldReport $report): array
    {
        if ($report->isCampaignLevel()) {
            return [
                'attribution' => 'campaign',
                'note' => 'Covers every site in the campaign for this period.',
            ];
        }

        if ($report->isTeamLevel()) {
            return [
                'attribution' => 'team',
                'team_id'     => $report->team_id,
                'note' => 'Covers only what is attributable to this team — the attendance of its members '
                    .'and the activities they led. Activities and registers are not yet attributed to teams, '
                    .'so a team report does not claim the whole site.',
            ];
        }

        return [
            'attribution' => 'site',
            'mission_site_id' => $report->mission_site_id,
            'note' => 'Covers every register and activity at this site for this period.',
        ];
    }

    // ── Fingerprinting ───────────────────────────────────────────────────────

    /**
     * Build the fingerprint from the three source collections.
     *
     * @param  Collection<int, Activity>  $activities
     * @param  Collection<int, AttendanceRegister>  $registers
     * @param  Collection<int, MissionOutcome>  $outcomes
     */
    private function fingerprintFrom(Collection $activities, Collection $registers, Collection $outcomes): string
    {
        $payload = [
            'activities' => $activities->map(fn (Activity $a) => [
                'id'        => $a->id,
                'status'    => $a->status,
                'planned'   => (bool) $a->is_planned,
                'sched'     => $a->scheduled_at?->timestamp,
                'updated'   => $a->updated_at?->timestamp,
            ])->values()->all(),

            'registers' => $registers->map(fn (AttendanceRegister $r) => [
                'id'        => $r->id,
                'status'    => $r->status,
                'submitted' => $r->submitted_at?->timestamp,
                'verified'  => $r->verified_at?->timestamp,
                // Register timestamps exist on the table; updated_at moves when a
                // row is touched, so it participates in change detection.
                'updated'   => $r->updated_at?->timestamp,
                // Entries are append/replace rows without updated_at, so their
                // count and newest created_at stand in for a change marker.
                'entries'   => $r->entries->count(),
                'entry_max' => $r->entries->max('created_at'),
            ])->values()->all(),

            'outcomes' => $outcomes->map(fn (MissionOutcome $o) => [
                'id'      => $o->id,
                'type'    => $o->outcome_type,
                'follow'  => $o->follow_up_status,
                'updated' => $o->updated_at?->timestamp,
            ])->values()->all(),
        ];

        return sha1(json_encode($payload));
    }

    // ── Small helpers ────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function zeroEntryCounts(): array
    {
        return [
            'registers'          => 0,
            'entries'            => 0,
            'identified'         => 0,
            'named_unidentified' => 0,
            'anonymous'          => 0,
            'walk_in'            => 0,
            'first_time'         => 0,
            'decisions_recorded' => 0,
        ];
    }

    /** @return array<string, array<int, int>> */
    private function emptyIdSets(): array
    {
        return [
            'activity_ids'            => [],
            'attendance_register_ids' => [],
            'outcome_ids'             => [],
        ];
    }

    /**
     * Ids present in $a but not in $b, per source kind.
     *
     * @param  array<string, array<int, int>>  $a
     * @param  array<string, array<int, int>>  $b
     * @return array<string, array<int, int>>
     */
    private function diffIdSets(array $a, array $b): array
    {
        $diff = [];
        foreach (array_keys($this->emptyIdSets()) as $key) {
            $diff[$key] = array_values(array_diff($a[$key] ?? [], $b[$key] ?? []));
        }

        return $diff;
    }
}
