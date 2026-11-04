<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * FieldReport — an official record of what happened during a reporting period.
 *
 * TWO ORTHOGONAL AXES
 * -------------------
 *   status        the lifecycle of the document:
 *                 draft → submitted → reviewed → approved
 *   review_state  where it is waiting in the reporting chain (team → site →
 *                 campaign), or that a change was requested. Kept separate so we
 *                 do not multiply workflow statuses — see WorkflowDefinitions.
 *
 * LEVELS
 * ------
 *   team      filed by a team secretary, owned by a team, reviewable by the team
 *             leader and then the site secretary.
 *   site      the site's own report, consolidating submitted team reports.
 *   campaign  campaign leadership's report, consolidating submitted site reports.
 *
 * A report NEVER copies authoritative attendance or activity figures into
 * itself except through the historical, human-entered `stat_*` columns. It
 * references the source rows by id and, via `preparation_meta`, states what the
 * live preparation service compiled and what a human must still supply.
 *
 * `parent_report_id` points upward (a site report consolidates team reports), so
 * a lower-level report is never rewritten by its parent.
 */
class FieldReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'mission_site_id', 'report_level', 'team_id',
        'activity_id', 'attendance_register_id', 'parent_report_id',
        'report_period_type',
        'period_start', 'period_end', 'status', 'narrative',
        'stat_meetings_held', 'stat_total_attendance', 'stat_decisions',
        'stat_baptisms', 'stat_bible_studies_requested',
        'challenges', 'observations', 'testimonies', 'recommendations', 'prayer_requests',
        'review_state', 'review_due_at', 'forwarded_at', 'escalated_at',
        'has_pending_lower_review', 'outstanding_review_level',
        'preparation_meta',
        'submitted_by_user_id', 'submitted_at', 'reviewed_by_user_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start'     => 'date',
            'period_end'       => 'date',
            'submitted_at'     => 'datetime',
            'reviewed_at'      => 'datetime',
            'review_due_at'    => 'datetime',
            'forwarded_at'     => 'datetime',
            'escalated_at'     => 'datetime',
            'has_pending_lower_review' => 'boolean',
            'preparation_meta' => 'array',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function site(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MissionSite::class, 'mission_site_id');
    }

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function activity(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function attendanceRegister(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AttendanceRegister::class);
    }

    /** The report this one consolidates (a higher rung). */
    public function parentReport(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FieldReport::class, 'parent_report_id');
    }

    /** The submitted lower-level reports consolidated into this one. */
    public function childReports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FieldReport::class, 'parent_report_id');
    }

    /** Immutable snapshots taken at every formal submission. */
    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FieldReportVersion::class)->orderByDesc('version_number');
    }

    /** Append-only audit history: every transition this report has been through. */
    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FieldReportEvent::class)->orderBy('created_at');
    }

    public function submittedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isTeamLevel(): bool
    {
        return $this->report_level === 'team';
    }

    public function isSiteLevel(): bool
    {
        return $this->report_level === 'site';
    }

    public function isCampaignLevel(): bool
    {
        return $this->report_level === 'campaign';
    }

    /**
     * Whether the report is still inside the formal review chain (i.e. it has
     * been submitted but a reviewer has not yet completed the chain).
     */
    public function awaitingReview(): bool
    {
        return in_array($this->status, ['submitted', 'reviewed'], true);
    }

    /**
     * The rung whose review this report is currently waiting on, derived from
     * its routing state. Null when nothing is awaited (draft, complete).
     */
    public function reviewerLevel(): ?string
    {
        return match ($this->review_state) {
            'pending_team_review'     => 'team',
            'pending_site_review'     => 'site',
            'pending_campaign_review' => 'campaign',
            default                   => null,
        };
    }

    /**
     * Whether the current reviewer's window has expired without action.
     *
     * A report in this state may legitimately be forwarded onward so the chain
     * does not stall — but forwarding is not approval, and the outstanding
     * review is still recorded against `outstanding_review_level`.
     */
    public function isReviewOverdue(): bool
    {
        return $this->review_due_at !== null
            && $this->review_due_at->isPast()
            && in_array(
                $this->review_state,
                \App\Domain\WorkflowDefinitions::REPORT_PENDING_REVIEW_STATES,
                true
            );
    }
}
