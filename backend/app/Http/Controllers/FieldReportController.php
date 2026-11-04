<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Activity;
use App\Models\AttendanceRegister;
use App\Models\Campaign;
use App\Models\FieldReport;
use App\Models\FieldReportEvent;
use App\Models\FieldReportVersion;
use App\Models\MissionSite;
use App\Models\Team;
use App\Services\AuthorizationService;
use App\Services\ReportPreparationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FieldReportController — reporting across all three rungs.
 *
 * Reports are filed per campaign per period, at one of three levels:
 *
 *   team      filed by a team secretary, owned by a team
 *   site      the site's consolidated report (mission_site_id set)
 *   campaign  campaign leadership's consolidated report (no site)
 *
 * Instead of demanding a campaign-wide appointment (which blocked site-scoped
 * secretaries), creation now authorises against the same scope as submission —
 * see FieldReportPolicy. A report REFERENCES its source fieldwork (activity,
 * attendance register, parent report) by id; it never copies authoritative
 * figures into itself.
 *
 * TWO ORTHOGONAL AXES
 * -------------------
 *   status        the document lifecycle: draft → submitted → reviewed →
 *                 approved. Never carries routing information.
 *   review_state  whose desk the report is on (team → site → campaign), or
 *                 that a change was requested, or that the chain is complete.
 *
 * THE REPORTING CHAIN
 * -------------------
 *   Team Secretary → Team Leader review → Site Secretary → Campaign Leadership
 *
 * A delayed Team Leader review must NOT stall the chain, so a report can be
 * FORWARDED past an outstanding review once the configured window expires:
 * the routing state advances, the responsible level is recorded, and the
 * document is emphatically NOT marked approved ("Forwarding is not approval").
 *
 * Every transition — submission, approval, return, forwarding, consolidation,
 * recovery — is appended to `field_report_events` (see events()).
 */
class FieldReportController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List reports for a campaign.
     *
     * GET /api/v1/campaigns/{campaign}/reports
     * ?site_id=5
     * ?team_id=3
     * ?report_level=team|site|campaign
     * ?status=draft|submitted|reviewed|approved
     * ?review_state=pending_team_review|...
     * ?pending_review=1   → only reports awaiting a human review
     * ?overdue=1          → only reports whose review window has expired
     * ?period_type=daily|weekly|summary
     */
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('viewAny', [FieldReport::class, $campaign]);

        $request->validate([
            'site_id'      => ['sometimes', 'integer'],
            'team_id'      => ['sometimes', 'integer'],
            'report_level' => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::REPORT_LEVELS)],
            'status'       => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::REPORT_STATUSES)],
            'review_state' => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::REPORT_REVIEW_STATES)],
            'pending_review' => ['sometimes', 'boolean'],
            'overdue'        => ['sometimes', 'boolean'],
            'period_type'  => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::REPORT_PERIOD_TYPES)],
        ]);

        $query = $campaign->fieldReports()
            ->with(['site', 'team', 'activity', 'submittedBy.person', 'reviewedBy.person'])
            ->orderByDesc('period_start');

        // ── Row visibility ────────────────────────────────────────────────────
        // Admission to the listing is decided by FieldReportPolicy::viewAny();
        // the rows themselves are narrowed here so a user only ever sees the
        // reports their active assignments entitle them to:
        //
        //   report.view       official reports, via the user's (campaign, site)
        //                     pairs — team-derived pairs are excluded so a team
        //                     assignment cannot expose the whole site;
        //   report.view_team  the DEVELOPING report of the user's OWN team,
        //                     matched by exact team id.
        //
        // A global scope grants the whole campaign (no constraint). The two
        // sources are OR-ed, so an officer who both bears official visibility
        // and sits on a team sees the union.
        $scopes  = $this->auth->getAuthorisedScopes($request->user(), 'report.view', false);
        $teamIds = $this->auth->teamIdsWithPermission($request->user(), 'report.view_team');

        // Fail closed: if the user holds neither official visibility nor the
        // narrow team visibility, they see nothing — an empty result, never the
        // whole campaign.
        if ($scopes === [] && $teamIds === []) {
            return response()->json([]);
        }

        $hasGlobalScope = false;
        foreach ($scopes as $scope) {
            if ($scope['campaign_id'] === null && $scope['site_id'] === null) {
                $hasGlobalScope = true;
                break;
            }
        }

        if (! $hasGlobalScope) {
            $query->where(function (Builder $outer) use ($scopes, $teamIds) {
                if ($scopes !== []) {
                    $this->auth->scopeQuery($outer, $scopes, 'campaign_id', 'mission_site_id');
                }
                if ($teamIds !== []) {
                    $outer->orWhereIn('team_id', $teamIds);
                }
            });
        }

        if ($request->filled('site_id')) {
            $query->where('mission_site_id', $request->site_id);
        }

        if ($request->filled('team_id')) {
            $query->where('team_id', $request->team_id);
        }

        if ($request->filled('report_level')) {
            $query->where('report_level', $request->report_level);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('review_state')) {
            $query->where('review_state', $request->review_state);
        }

        // "Awaiting my review" style filters — deliberately expressed in terms of
        // routing state and the review deadline, never in terms of a status.
        if ($request->boolean('pending_review')) {
            $query->whereIn('review_state', WorkflowDefinitions::REPORT_PENDING_REVIEW_STATES);
        }

        if ($request->boolean('overdue')) {
            $query->whereIn('review_state', WorkflowDefinitions::REPORT_PENDING_REVIEW_STATES)
                  ->whereNotNull('review_due_at')
                  ->where('review_due_at', '<', now());
        }

        if ($request->filled('period_type')) {
            $query->where('report_period_type', $request->period_type);
        }

        return response()->json($query->get());
    }

    /**
     * Create a new field report draft.
     *
     * POST /api/v1/campaigns/{campaign}/reports
     *
     * The report's `report_level` determines what it must be anchored to:
     *   team      → a mission_site_id AND a team_id within that site
     *   site      → a mission_site_id (default when none is supplied)
     *   campaign  → nothing (it covers the whole campaign)
     *
     * Source references (activity, attendance register, parent report) are
     * OPTIONAL and validated to belong to the same campaign/site so a report
     * can never point at another campaign's fieldwork.
     */
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $validated = $request->validate([
            'report_level'       => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::REPORT_LEVELS)],
            'mission_site_id'    => ['sometimes', 'nullable', 'integer'],
            'team_id'            => ['sometimes', 'nullable', 'integer'],
            'activity_id'        => ['sometimes', 'nullable', 'integer'],
            'attendance_register_id' => ['sometimes', 'nullable', 'integer'],
            'parent_report_id'   => ['sometimes', 'nullable', 'integer'],
            'report_period_type' => ['required', 'string', 'in:'.WorkflowDefinitions::validationIn(WorkflowDefinitions::REPORT_PERIOD_TYPES)],
            'period_start'       => ['required', 'date'],
            'period_end'         => ['required', 'date', 'after_or_equal:period_start'],
            'narrative'          => ['sometimes', 'nullable', 'string'],
            'stat_meetings_held'            => ['sometimes', 'integer', 'min:0'],
            'stat_total_attendance'         => ['sometimes', 'integer', 'min:0'],
            'stat_decisions'                => ['sometimes', 'integer', 'min:0'],
            'stat_baptisms'                 => ['sometimes', 'integer', 'min:0'],
            'stat_bible_studies_requested'  => ['sometimes', 'integer', 'min:0'],
            'challenges'         => ['sometimes', 'nullable', 'string'],
            'observations'       => ['sometimes', 'nullable', 'string'],
            'testimonies'        => ['sometimes', 'nullable', 'string'],
            'recommendations'    => ['sometimes', 'nullable', 'string'],
            'prayer_requests'    => ['sometimes', 'nullable', 'string'],
        ]);

        $level = $validated['report_level'] ?? 'site';

        // Resolve the site (when one was named) before authorising, because a
        // site- or team-scoped secretary is authorised THROUGH their site.
        $site = isset($validated['mission_site_id'])
            ? $this->resolveSite($campaign, (int) $validated['mission_site_id'])
            : null;

        // A team/site report without a site would be unanchored — and a
        // campaign-level report must NOT carry one (it covers everything).
        if ($level !== 'campaign' && $site === null) {
            return response()->json([
                'message' => "A {$level}-level report must be filed against a mission site (mission_site_id is required).",
            ], 422);
        }

        $this->authorize('create', [FieldReport::class, $campaign, $site]);

        // Relationship integrity — each reference must belong to this campaign
        // (and to the named site where the referenced table carries one).
        $team = isset($validated['team_id'])
            ? $this->resolveTeam($campaign, $site, (int) $validated['team_id'])
            : null;

        if ($level === 'team' && $team === null) {
            return response()->json([
                'message' => 'A team-level report must name the team it belongs to (team_id is required).',
            ], 422);
        }

        if (isset($validated['activity_id'])) {
            $this->assertActivityInScope($campaign, $site, (int) $validated['activity_id']);
        }

        if (isset($validated['attendance_register_id'])) {
            $this->assertRegisterInScope($campaign, $site, (int) $validated['attendance_register_id']);
        }

        if (isset($validated['parent_report_id'])) {
            $this->assertParentReportInScope($campaign, (int) $validated['parent_report_id']);
        }

        $report = $campaign->fieldReports()->create([
            ...$validated,
            'report_level'   => $level,
            'mission_site_id'=> $level === 'campaign' ? null : $site?->id,
            'status'         => 'draft',
        ]);

        return response()->json($report->load(['site', 'team', 'activity']), 201);
    }

    /**
     * Show a field report.
     *
     * GET /api/v1/campaigns/{campaign}/reports/{report}
     */
    public function show(Campaign $campaign, FieldReport $report): JsonResponse
    {
        $this->validateReportScope($campaign, $report);
        $this->authorize('view', $report);

        return response()->json(
            $report->load([
                'site.missionArea', 'team', 'activity', 'attendanceRegister',
                'submittedBy.person', 'reviewedBy.person',
                'childReports', 'versions', 'events.actor.person',
            ])
        );
    }

    /**
     * Update a report (only allowed in draft status).
     *
     * PATCH /api/v1/campaigns/{campaign}/reports/{report}
     */
    public function update(Request $request, Campaign $campaign, FieldReport $report): JsonResponse
    {
        $this->validateReportScope($campaign, $report);
        $this->authorize('update', $report);

        if ($report->status !== 'draft') {
            return response()->json([
                'message' => "Report cannot be edited once submitted (status: '{$report->status}').",
            ], 422);
        }

        $validated = $request->validate([
            'narrative'                     => ['sometimes', 'nullable', 'string'],
            'stat_meetings_held'            => ['sometimes', 'integer', 'min:0'],
            'stat_total_attendance'         => ['sometimes', 'integer', 'min:0'],
            'stat_decisions'                => ['sometimes', 'integer', 'min:0'],
            'stat_baptisms'                 => ['sometimes', 'integer', 'min:0'],
            'stat_bible_studies_requested'  => ['sometimes', 'integer', 'min:0'],
            'challenges'                    => ['sometimes', 'nullable', 'string'],
            'observations'                  => ['sometimes', 'nullable', 'string'],
            'testimonies'                   => ['sometimes', 'nullable', 'string'],
            'recommendations'               => ['sometimes', 'nullable', 'string'],
            'prayer_requests'               => ['sometimes', 'nullable', 'string'],
        ]);

        $report->update($validated);

        return response()->json($report->fresh(['site', 'team', 'activity']));
    }

    /**
     * Submit a draft report into the review chain.
     *
     * POST /api/v1/campaigns/{campaign}/reports/{report}/submit
     *
     * Three things happen, in this order, and all three matter:
     *
     *   1. The compiled fieldwork is frozen onto the report
     *      (`preparation_meta`), so the submitted document states what the
     *      platform knew at that moment.
     *   2. An immutable version snapshot is appended to `field_report_versions`
     *      — later source-record changes must never silently rewrite it.
     *   3. The routing state (`review_state`) is set from the level map, with a
     *      configurable review deadline (`review_due_at`). The document's own
     *      `status` remains a separate axis: a submitted report is `submitted`,
     *      not `approved`.
     */
    public function submit(
        Request $request,
        Campaign $campaign,
        FieldReport $report,
        ReportPreparationService $preparation,
    ): JsonResponse {
        $this->validateReportScope($campaign, $report);
        $this->authorize('submit', $report);

        if ($report->status !== 'draft') {
            return response()->json([
                'message' => "Only draft reports can be submitted (status: '{$report->status}').",
            ], 422);
        }

        // Which desk does this land on first? A team report goes to the team
        // leader; a site or campaign report goes straight to campaign
        // leadership.
        $routedState = WorkflowDefinitions::REPORT_SUBMIT_REVIEW_STATE[$report->report_level]
            ?? 'pending_campaign_review';

        $report->update([
            'status'                   => 'submitted',
            'review_state'             => $routedState,
            'review_due_at'            => now()->addHours($this->reviewWindowHours()),
            // A fresh submission restarts the chain from this report's own
            // rung, so any previously recorded delay is cleared.
            'has_pending_lower_review' => false,
            'outstanding_review_level' => null,
            'submitted_by_user_id'     => $request->user()->id,
            'submitted_at'             => now(),
        ]);

        // Freeze what the platform knows, then preserve an immutable version of
        // it. The version is written AFTER the freeze so the snapshot contains
        // the preparation metadata exactly as submitted.
        $report  = $preparation->persist($report);
        $version = $this->snapshotVersion($report, $request->user()->id);

        $this->recordEvent(
            $report,
            'submitted',
            null,
            $routedState,
            $report->report_level,
            $request->user()->id,
            null,
            ['version_number' => $version->version_number]
        );

        return response()->json([
            'message' => 'Report submitted for review.',
            'report'  => $report->fresh(['site', 'team', 'submittedBy.person']),
        ]);
    }

    /**
     * Review a submitted report — approve it onward, or return it for change.
     *
     * POST /api/v1/campaigns/{campaign}/reports/{report}/review
     * body: { "action": "approve" | "return", "notes": "..." }
     *
     * Approval is routed by LEVEL and by the report's current `review_state`:
     * approving a team report hands it to the site secretary for consolidation
     * (status `reviewed`, still not final); approving a site or campaign report
     * completes the chain (status `approved`).
     *
     * If the report was already forwarded past an outstanding lower review, a
     * late approval by that lower rung is still recorded — but it only clears
     * the outstanding flag; it does not roll the chain backwards and it does
     * not mark the document approved.
     */
    public function review(Request $request, Campaign $campaign, FieldReport $report): JsonResponse
    {
        $this->validateReportScope($campaign, $report);
        $this->authorize('review', $report);

        $request->validate([
            'action' => ['required', 'string', 'in:approve,return'],
            'notes'  => ['sometimes', 'nullable', 'string'],
        ]);

        if (! in_array($report->status, ['submitted', 'reviewed'], true)) {
            return response()->json([
                'message' => "Only submitted reports can be reviewed (status: '{$report->status}').",
            ], 422);
        }

        // A report with no routing state (an older row, or one whose status was
        // set directly) is routed as though it had just been submitted, so
        // existing reports keep working untouched.
        $currentState = $report->review_state
            ?? WorkflowDefinitions::REPORT_SUBMIT_REVIEW_STATE[$report->report_level]
            ?? null;

        $actorLevel      = $report->reviewerLevel() ?? $report->report_level;
        $outstandingLevel = $report->outstanding_review_level;

        if ($request->action === 'return') {
            $report->update([
                'status'              => 'draft',
                'review_state'        => 'changes_requested',
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at'         => now(),
                'review_due_at'       => null,
            ]);

            $this->recordEvent($report, 'returned', $currentState, 'changes_requested', $actorLevel, $request->user()->id, $request->notes);

            return response()->json([
                'message' => 'Report returned to draft for correction.',
                'report'  => $report->fresh(['site', 'submittedBy.person', 'reviewedBy.person']),
            ]);
        }

        $next = WorkflowDefinitions::REPORT_REVIEW_APPROVE_NEXT[$report->report_level][$currentState] ?? null;

        // No onward route. Either nothing is awaited at this level, or the
        // report has already moved on and this is the late review the forwarding
        // was waiting for.
        if ($next === null) {
            if ($report->has_pending_lower_review) {
                $report->update([
                    'has_pending_lower_review' => false,
                    'outstanding_review_level' => null,
                    'reviewed_by_user_id'      => $request->user()->id,
                    'reviewed_at'              => now(),
                ]);

                $this->recordEvent(
                    $report,
                    'approved',
                    $currentState,
                    $report->review_state,
                    $outstandingLevel ?? $actorLevel,
                    $request->user()->id,
                    $request->notes,
                    ['late_review' => true, 'note' => 'Recorded after the report had already been forwarded; not treated as approval.']
                );

                return response()->json([
                    'message' => 'Late review recorded. The report had already moved on, so it is not marked approved.',
                    'report'  => $report->fresh(['site', 'submittedBy.person', 'reviewedBy.person']),
                ]);
            }

            return response()->json([
                'message' => 'This report is not awaiting a review at its current level.',
            ], 422);
        }

        $completed = $next === 'complete';

        $report->update([
            'status'                   => $completed ? 'approved' : 'reviewed',
            'review_state'             => $next,
            'reviewed_by_user_id'      => $request->user()->id,
            'reviewed_at'              => now(),
            'review_due_at'            => $completed ? null : now()->addHours($this->reviewWindowHours()),
            // The reviewer at this rung has acted, so any delay recorded
            // against it is now settled.
            'has_pending_lower_review' => false,
            'outstanding_review_level' => null,
        ]);

        $this->recordEvent($report, 'approved', $currentState, $next, $actorLevel, $request->user()->id, $request->notes);

        return response()->json([
            'message' => $completed
                ? 'Report approved.'
                : 'Review recorded. The report has moved to the next level; it is not yet finally approved.',
            'report' => $report->fresh(['site', 'submittedBy.person', 'reviewedBy.person']),
        ]);
    }

    // ── Forwarding, consolidation, recovery ──────────────────────────────────

    /**
     * Forward a report past an outstanding review.
     *
     * POST /api/v1/campaigns/{campaign}/reports/{report}/forward
     *
     * This is what stops a delayed Team Leader review from stalling the whole
     * chain. Once the configured review window has expired, the report advances
     * to the next rung; campaign leadership may also forward earlier, because
     * they own the chain.
     *
     * FORWARDING IS NOT APPROVAL:
     *   - `status` never becomes `approved`;
     *   - `has_pending_lower_review` stays true and `outstanding_review_level`
     *     names the rung responsible for the delay, so the report continues to
     *     read "Team Review Pending";
     *   - the delay is written to the audit history.
     */
    public function forward(Request $request, Campaign $campaign, FieldReport $report): JsonResponse
    {
        $this->validateReportScope($campaign, $report);
        $this->authorize('forward', $report);

        $request->validate(['notes' => ['sometimes', 'nullable', 'string']]);

        if (! in_array($report->review_state, WorkflowDefinitions::REPORT_PENDING_REVIEW_STATES, true)) {
            return response()->json([
                'message' => 'This report is not awaiting a review, so there is nothing to forward.',
            ], 422);
        }

        $currentState = $report->review_state;
        $outstanding  = $report->reviewerLevel();

        // Ordinary officers may only unblock the chain once the window has
        // actually expired; campaign leadership may act at any time.
        $isCampaignReviewer = $this->auth->hasPermissionForCampaign($request->user(), 'report.review', $campaign);

        if (! $report->isReviewOverdue() && ! $isCampaignReviewer) {
            return response()->json([
                'message' => 'The review window has not expired yet, so the report cannot be forwarded.',
            ], 422);
        }

        $next = match ($currentState) {
            'pending_team_review'     => 'pending_site_review',
            'pending_site_review'     => 'pending_campaign_review',
            'pending_campaign_review' => 'complete',
            default                   => null,
        };

        if ($next === null) {
            return response()->json([
                'message' => 'This report cannot be forwarded from its current state.',
            ], 422);
        }

        $delayHours = $report->review_due_at !== null && $report->review_due_at->isPast()
            ? (int) $report->review_due_at->diffInHours(now())
            : 0;

        $report->update([
            'review_state'             => $next,
            'forwarded_at'             => now(),
            'has_pending_lower_review' => $outstanding !== null,
            'outstanding_review_level' => $outstanding,
            'review_due_at'            => $next === 'complete' ? null : now()->addHours($this->reviewWindowHours()),
        ]);

        $this->recordEvent(
            $report,
            'forwarded',
            $currentState,
            $next,
            $outstanding,
            $request->user()->id,
            $request->notes,
            [
                'reason'            => 'review_window_expired',
                'responsible_level' => $outstanding,
                'delay_hours'       => $delayHours,
                'note'              => 'Forwarding is not approval; the outstanding review remains recorded against the responsible level.',
            ]
        );

        return response()->json([
            'message' => 'Report forwarded. The outstanding review is recorded and the report is not treated as approved.',
            'report'  => $report->fresh(['site', 'team', 'submittedBy.person']),
        ]);
    }

    /**
     * Consolidate submitted lower-level reports into this report.
     *
     * POST /api/v1/campaigns/{campaign}/reports/{report}/consolidate
     * body: { "child_report_ids": [3, 4] }   // optional; defaults to all
     *
     * A site report consolidates the submitted TEAM reports at its site; a
     * campaign report consolidates the submitted SITE reports in the campaign.
     *
     * The lower-level reports are never rewritten — they are LINKED to this
     * report through `parent_report_id` and their routing chain is closed. A
     * child whose document status was never approved keeps that status, because
     * consolidation is not approval.
     *
     * Reports that have not been submitted are never invented or silently
     * filled in; they are reported back so the officer can see exactly what is
     * delayed.
     */
    public function consolidate(
        Request $request,
        Campaign $campaign,
        FieldReport $report,
        ReportPreparationService $preparation,
    ): JsonResponse {
        $this->validateReportScope($campaign, $report);
        $this->authorize('consolidate', $report);

        if (! $report->isSiteLevel() && ! $report->isCampaignLevel()) {
            return response()->json([
                'message' => 'Only site and campaign reports consolidate lower-level reports.',
            ], 422);
        }

        if ($report->status === 'approved') {
            return response()->json([
                'message' => 'An approved report can no longer be consolidated.',
            ], 422);
        }

        $request->validate([
            'child_report_ids'   => ['sometimes', 'array'],
            'child_report_ids.*' => ['integer'],
        ]);

        $childLevel = $report->isCampaignLevel() ? 'site' : 'team';

        // Candidate lower-level reports: same campaign, the right level, inside
        // this report's site (where it has one), and with an overlapping period.
        $candidates = FieldReport::query()
            ->where('campaign_id', $report->campaign_id)
            ->where('id', '!=', $report->id)
            ->where('report_level', $childLevel)
            ->whereDate('period_start', '<=', $report->period_end->toDateString())
            ->whereDate('period_end', '>=', $report->period_start->toDateString());

        if ($childLevel === 'team' && $report->mission_site_id !== null) {
            $candidates->where('mission_site_id', $report->mission_site_id);
        }

        $candidates = $candidates->get();

        $consolidatable = $candidates->filter(
            fn (FieldReport $c) => in_array($c->status, ['submitted', 'reviewed'], true)
        )->values();

        $delayed = $candidates
            ->reject(fn (FieldReport $c) => in_array($c->status, ['submitted', 'reviewed'], true))
            ->values();

        // An explicit list narrows the consolidation. Anything named that is not
        // consolidatable is refused rather than silently accepted.
        $attach = $request->filled('child_report_ids')
            ? $consolidatable->whereIn('id', $request->input('child_report_ids'))->values()
            : $consolidatable;

        $refused = $request->filled('child_report_ids')
            ? collect($request->input('child_report_ids'))
                ->map(fn ($id) => (int) $id)
                ->diff($attach->pluck('id'))
                ->values()
                ->all()
            : [];

        foreach ($attach as $child) {
            $priorState = $child->review_state;

            $child->update([
                'parent_report_id'         => $report->id,
                // The child's chain is finished — but its document status is
                // deliberately NOT set to 'approved'.
                'review_state'             => 'complete',
                'has_pending_lower_review' => false,
                'outstanding_review_level' => null,
                'review_due_at'            => null,
            ]);

            $this->recordEvent(
                $child,
                'consolidated',
                $priorState,
                'complete',
                $child->report_level,
                $request->user()->id,
                null,
                ['parent_report_id' => $report->id, 'note' => 'Consolidated upwards; consolidation is not approval.']
            );
        }

        // Recompile so the consolidated report reflects the records it now
        // stands on.
        $report = $preparation->persist($report);

        $attachedIds = $attach->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $this->recordEvent(
            $report,
            'consolidated',
            $report->review_state,
            $report->review_state,
            $report->report_level,
            $request->user()->id,
            null,
            [
                'child_level'         => $childLevel,
                'attached_report_ids' => $attachedIds,
                'delayed_report_ids'  => $delayed->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'refused_report_ids'  => $refused,
                'note'                => 'Delayed lower-level reports are listed, never invented.',
            ]
        );

        return response()->json([
            'message' => 'Lower-level reports consolidated.',
            'consolidation' => [
                'child_level' => $childLevel,
                'attached'    => $attachedIds,
                'delayed'     => $delayed->map(fn (FieldReport $c) => [
                    'id'           => $c->id,
                    'report_level' => $c->report_level,
                    'team_id'      => $c->team_id,
                    'status'       => $c->status,
                    'review_state' => $c->review_state,
                ])->values()->all(),
                'refused'     => $refused,
            ],
            'report' => $report->fresh(['site', 'team', 'childReports']),
        ]);
    }

    /**
     * Generate this report from the records that DO exist, when a lower-level
     * submission is delayed.
     *
     * POST /api/v1/campaigns/{campaign}/reports/{report}/recover
     *
     * Example: a Site Secretary prepares the site report even though one Team
     * Secretary has not submitted.
     *
     * The generated report distinguishes, explicitly and in the payload:
     *   verified               figures from verified registers
     *   auto_generated         activities, attendance and outcomes compiled
     *   manually_supplemented  the human-written sections already present
     *   missing                what is absent (never shown as a zero)
     *   pending_reviews        lower-level reports still outstanding
     *
     * Nothing is invented — no figures, no outcomes, no narrative. And
     * generating a report is NOT a submission: the report stays a draft and
     * must be reviewed before it is formally submitted.
     */
    public function recover(
        Request $request,
        Campaign $campaign,
        FieldReport $report,
        ReportPreparationService $preparation,
    ): JsonResponse {
        $this->validateReportScope($campaign, $report);
        $this->authorize('recover', $report);

        if ($report->status !== 'draft') {
            return response()->json([
                'message' => "A report can only be generated onto a draft (status: '{$report->status}').",
            ], 422);
        }

        $request->validate(['notes' => ['sometimes', 'nullable', 'string']]);

        $report = $preparation->recover($report);

        $this->recordEvent(
            $report,
            'recovered',
            $report->review_state,
            $report->review_state,
            $report->report_level,
            $request->user()->id,
            $request->notes,
            ['note' => 'Generated from available field records. Generating a report is not approval; it must still be reviewed before formal submission.']
        );

        return response()->json([
            'message'  => 'Report generated from the available records. Review it before formal submission.',
            'recovery' => $report->preparation_meta['recovery'] ?? null,
            'report'   => $report->fresh(['site', 'team']),
        ]);
    }

    /**
     * The report's append-only audit history.
     *
     * GET /api/v1/campaigns/{campaign}/reports/{report}/events
     */
    public function events(Campaign $campaign, FieldReport $report): JsonResponse
    {
        $this->validateReportScope($campaign, $report);
        $this->authorize('view', $report);

        return response()->json($report->events()->with('actor.person')->get());
    }

    // ── Live preparation ─────────────────────────────────────────────────────

    /**
     * Live preparation snapshot for a report.
     *
     * GET /api/v1/campaigns/{campaign}/reports/{report}/preparation
     *
     * PURE READ. Compiles the fieldwork the report covers, as it stands right
     * now, and compares it against the preserved submitted version (when one
     * exists). Nothing is written to the database, so the endpoint is safe for
     * a team member to poll while the report is still being developed.
     */
    public function preparation(
        Campaign $campaign,
        FieldReport $report,
        ReportPreparationService $preparation,
    ): JsonResponse {
        $this->validateReportScope($campaign, $report);
        $this->authorize('view', $report);

        return response()->json([
            'preparation'    => $preparation->compile($report),
            'reconciliation' => $preparation->reconcile($report),
        ]);
    }

    /**
     * Recalculate and freeze the current compilation onto the draft.
     *
     * POST /api/v1/campaigns/{campaign}/reports/{report}/prepare
     *
     * Gated on the draft lifecycle: once a report is submitted its preparation
     * is captured inside the preserved version and must not be rewritten here.
     */
    public function prepare(
        Campaign $campaign,
        FieldReport $report,
        ReportPreparationService $preparation,
    ): JsonResponse {
        $this->validateReportScope($campaign, $report);
        $this->authorize('update', $report);

        if ($report->status !== 'draft') {
            return response()->json([
                'message' => "Preparation can only be recalculated on a draft (status: '{$report->status}').",
            ], 422);
        }

        $updated = $preparation->persist($report);

        return response()->json([
            'message'     => 'Report preparation recalculated from the latest field records.',
            'preparation' => $updated->preparation_meta,
            'report'      => $updated,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validateReportScope(Campaign $campaign, FieldReport $report): void
    {
        if ($report->campaign_id !== $campaign->id) {
            abort(404, 'Report not found within this campaign.');
        }
    }

    /**
     * Resolve a mission site and confirm it belongs to the campaign.
     * Aborts 422 rather than silently accepting a foreign site id.
     */
    private function resolveSite(Campaign $campaign, int $siteId): MissionSite
    {
        $site = MissionSite::where('id', $siteId)
            ->where('campaign_id', $campaign->id)
            ->first();

        if (! $site) {
            abort(422, "Site {$siteId} does not belong to campaign {$campaign->id}.");
        }

        return $site;
    }

    /**
     * Resolve a team and confirm it belongs to the campaign — and, when a site
     * is in play, to that site (a campaign-wide team, mission_site_id = null,
     * is not accepted as a site report's owner).
     */
    private function resolveTeam(Campaign $campaign, ?MissionSite $site, int $teamId): ?Team
    {
        $team = Team::where('id', $teamId)
            ->where('campaign_id', $campaign->id)
            ->first();

        if (! $team) {
            abort(422, "Team {$teamId} does not belong to campaign {$campaign->id}.");
        }

        if ($site !== null && (int) $team->mission_site_id !== (int) $site->id) {
            abort(422, "Team {$teamId} does not belong to site {$site->id}.");
        }

        return $team;
    }

    private function assertActivityInScope(Campaign $campaign, ?MissionSite $site, int $activityId): void
    {
        $query = Activity::where('id', $activityId)->where('campaign_id', $campaign->id);

        if ($site !== null) {
            $query->where('mission_site_id', $site->id);
        }

        if (! $query->exists()) {
            abort(422, "Activity {$activityId} is not part of this campaign/site.");
        }
    }

    private function assertRegisterInScope(Campaign $campaign, ?MissionSite $site, int $registerId): void
    {
        $query = AttendanceRegister::where('id', $registerId)->where('campaign_id', $campaign->id);

        if ($site !== null) {
            $query->where('mission_site_id', $site->id);
        }

        if (! $query->exists()) {
            abort(422, "Attendance register {$registerId} is not part of this campaign/site.");
        }
    }

    /**
     * A parent (consolidating) report must belong to the same campaign. This is
     * what keeps consolidation inside one campaign's reporting tree.
     */
    private function assertParentReportInScope(Campaign $campaign, int $parentReportId): void
    {
        $exists = FieldReport::where('id', $parentReportId)
            ->where('campaign_id', $campaign->id)
            ->exists();

        if (! $exists) {
            abort(422, "Parent report {$parentReportId} is not part of this campaign.");
        }
    }

    /**
     * How long a reviewer has to act before a report may be forwarded past them.
     *
     * Configurable through config/reporting.php (REPORT_REVIEW_WINDOW_HOURS) so
     * the hierarchy's pace can be tuned without a code change.
     */
    private function reviewWindowHours(): int
    {
        return max(1, (int) config('reporting.review_window_hours', 24));
    }

    /**
     * Preserve an immutable version of the report as it stands.
     *
     * Versions are append-only: the next version number is derived from the
     * highest existing one, and nothing is ever updated or deleted. The
     * snapshot carries both the report's own fields and its preparation
     * metadata, so a version can be read back without touching a live table.
     * ReportPreparationService::reconcile() reads the `preparation` key here.
     */
    private function snapshotVersion(FieldReport $report, ?int $userId): FieldReportVersion
    {
        $next = (int) FieldReportVersion::where('field_report_id', $report->id)->max('version_number') + 1;

        return FieldReportVersion::create([
            'field_report_id'    => $report->id,
            'version_number'     => $next,
            'status'             => $report->status,
            'review_state'       => $report->review_state,
            'created_by_user_id' => $userId,
            'created_at'         => now(),
            'snapshot'           => [
                'format_version' => ReportPreparationService::FORMAT_VERSION,
                'captured_at'    => now()->toIso8601String(),
                'report'         => [
                    'id'                            => $report->id,
                    'campaign_id'                   => $report->campaign_id,
                    'mission_site_id'               => $report->mission_site_id,
                    'report_level'                  => $report->report_level,
                    'team_id'                       => $report->team_id,
                    'activity_id'                   => $report->activity_id,
                    'attendance_register_id'        => $report->attendance_register_id,
                    'report_period_type'            => $report->report_period_type,
                    'period_start'                  => $report->period_start?->toDateString(),
                    'period_end'                    => $report->period_end?->toDateString(),
                    'status'                        => $report->status,
                    'review_state'                  => $report->review_state,
                    'narrative'                     => $report->narrative,
                    'challenges'                    => $report->challenges,
                    'observations'                  => $report->observations,
                    'testimonies'                   => $report->testimonies,
                    'recommendations'               => $report->recommendations,
                    'prayer_requests'               => $report->prayer_requests,
                    'stat_meetings_held'            => $report->stat_meetings_held,
                    'stat_total_attendance'         => $report->stat_total_attendance,
                    'stat_decisions'                => $report->stat_decisions,
                    'stat_baptisms'                 => $report->stat_baptisms,
                    'stat_bible_studies_requested'  => $report->stat_bible_studies_requested,
                ],
                'preparation'    => $report->preparation_meta,
            ],
        ]);
    }

    /**
     * Append one transition to a report's audit history.
     *
     * The history is append-only (see FieldReportEvent) and records who acted,
     * from which routing state to which, at which level, and any detail the
     * reader needs to understand a delay — so the story of a report can be read
     * back without guessing.
     *
     * @param  array<string, mixed>  $meta
     */
    private function recordEvent(
        FieldReport $report,
        string $eventType,
        ?string $fromState,
        ?string $toState,
        ?string $level,
        ?int $actorUserId,
        ?string $notes = null,
        array $meta = [],
    ): FieldReportEvent {
        return FieldReportEvent::create([
            'field_report_id' => $report->id,
            'event_type'      => $eventType,
            'from_state'      => $fromState,
            'to_state'        => $toState,
            'level'           => $level,
            'actor_user_id'   => $actorUserId,
            'notes'           => $notes,
            'meta'            => $meta === [] ? null : $meta,
            'created_at'      => now(),
        ]);
    }
}
