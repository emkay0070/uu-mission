<?php

namespace App\Domain;

/**
 * WorkflowDefinitions — the single source of truth for all workflow statuses
 * and valid state-transition rules in the UU Mission platform.
 *
 * WHY THIS EXISTS:
 *   Previously, allowed status values and valid transitions were scattered
 *   as inline `in:...` strings across multiple controllers. When a status
 *   needed to change, every controller, validation rule, factory, and test
 *   had to be updated independently — creating inconsistency risk.
 *
 *   This class is NOT a database-driven workflow engine. The statuses and
 *   transitions are intentional application rules, not administrator-editable
 *   content. They live here (not in a DB table) because:
 *     - They encode invariants (an archived campaign cannot become active).
 *     - Changing them requires a coordinated deployment, not a DB edit.
 *     - They inform validation, authorization, and tests equally.
 *
 * USAGE:
 *   In controllers:  `'in:'.implode(',', WorkflowDefinitions::CAMPAIGN_STATUSES)`
 *   In transitions:  `WorkflowDefinitions::CAMPAIGN_TRANSITIONS[$current] ?? []`
 *   In factories:    reference constants instead of repeating strings.
 */
final class WorkflowDefinitions
{
    // ── Campaign ──────────────────────────────────────────────────────────────

    /** All valid campaign status values. */
    public const CAMPAIGN_STATUSES = ['planning', 'active', 'completed', 'archived'];

    /**
     * Valid forward-only transitions for campaign lifecycle.
     * Campaigns never move backwards. Archived is a terminal state.
     */
    public const CAMPAIGN_TRANSITIONS = [
        'planning' => ['active'],
        'active' => ['completed'],
        'completed' => ['archived'],
        'archived' => [],
    ];

    /** Statuses that lock a campaign — no mutations to sites/teams allowed. */
    public const CAMPAIGN_LOCKED_STATUSES = ['completed', 'archived'];

    // ── Mission site ──────────────────────────────────────────────────────────

    /** All valid site status values. */
    public const SITE_STATUSES = ['planned', 'active', 'closed'];

    // ── Activity ───────────────────────────────────────────────────────────────

    /** All valid activity status values. */
    public const ACTIVITY_STATUSES = ['scheduled', 'in_progress', 'completed', 'cancelled'];

    // ── Missionary assignment ─────────────────────────────────────────────────

    /** All valid assignment status values. */
    public const ASSIGNMENT_STATUSES = ['confirmed', 'arrived', 'completed', 'withdrew', 'no_show'];

    /**
     * Statuses from which an assignment can be deleted.
     * Once a missionary has arrived, the record must be retained (withdrew/completed).
     */
    public const ASSIGNMENT_DELETABLE_STATUSES = ['confirmed', 'no_show'];

    // ── Field report ──────────────────────────────────────────────────────────

    /** All valid field report status values. */
    public const REPORT_STATUSES = ['draft', 'submitted', 'reviewed', 'approved'];

    /** Report period types — technical classification, not admin-configurable. */
    public const REPORT_PERIOD_TYPES = ['daily', 'weekly', 'summary'];

    /**
     * Reporting levels — the rung a report occupies in the hierarchy.
     *
     *   team      filed by a team secretary, owned by a team
     *   site      the site's consolidated report (mission_site_id set)
     *   campaign  campaign leadership's consolidated report
     */
    public const REPORT_LEVELS = ['team', 'site', 'campaign'];

    /**
     * Review chain states. These are deliberately SEPARATE from
     * REPORT_STATUSES so that routing ("whose desk is this on?") never
     * multiplies the document's own lifecycle.
     *
     *   pending_team_review      awaiting the team leader (or overdue, and
     *                            forwarded with the delay recorded)
     *   pending_site_review      awaiting the site secretary's consolidation
     *   pending_campaign_review  awaiting campaign leadership
     *   changes_requested        returned to the author for correction
     *   complete                 the chain finished; nothing further is awaited
     */
    public const REPORT_REVIEW_STATES = [
        'pending_team_review',
        'pending_site_review',
        'pending_campaign_review',
        'changes_requested',
        'complete',
    ];

    /** Review states that represent an outstanding human action. */
    public const REPORT_PENDING_REVIEW_STATES = [
        'pending_team_review',
        'pending_site_review',
        'pending_campaign_review',
    ];

    /**
     * The routing state a report enters the moment it is formally submitted,
     * per level — i.e. whose desk it lands on first.
     *
     *   team     → the team leader reviews it
     *   site     → campaign leadership reviews it
     *   campaign → campaign leadership reviews it
     */
    public const REPORT_SUBMIT_REVIEW_STATE = [
        'team' => 'pending_team_review',
        'site' => 'pending_campaign_review',
        'campaign' => 'pending_campaign_review',
    ];

    /**
     * The routing state a submitted report moves to when the reviewer at its
     * level APPROVES, keyed by report level.
     *
     *   team      pending_team_review → pending_site_review
     *             (the team leader has passed it on; the site secretary's
     *              consolidation is what finally completes it)
     *   site      pending_campaign_review → complete
     *   campaign  pending_campaign_review → complete
     *
     * `complete` means "no further review is awaited". It is deliberately NOT
     * the same thing as the document's `status`, which becomes `approved` for a
     * site/campaign report and stays `submitted` for a team report that is still
     * waiting to be consolidated.
     */
    public const REPORT_REVIEW_APPROVE_NEXT = [
        'team' => [
            'pending_team_review' => 'pending_site_review',
        ],
        'site' => [
            'pending_campaign_review' => 'complete',
        ],
        'campaign' => [
            'pending_campaign_review' => 'complete',
        ],
    ];

    /** Event types recorded in a report's audit history. */
    public const REPORT_EVENT_TYPES = [
        'submitted',
        'approved',
        'returned',
        'forwarded',
        'escalated',
        'consolidated',
        'recovered',
    ];

    // ── Mission outcome follow-up ─────────────────────────────────────────────

    /** All valid follow-up status values. */
    public const FOLLOW_UP_STATUSES = ['pending', 'assigned', 'completed', 'lost_contact', 'not_required'];

    /** Valid result values when completing a follow-up. */
    public const FOLLOW_UP_RESULTS = ['completed', 'lost_contact'];

    // ── Team member roles ─────────────────────────────────────────────────────

    /**
     * Roles a person can hold within a team.
     * These are structural organizational roles, not administrator-configurable.
     * The uniqueness rule (one leader per team) is enforced in TeamController.
     */
    public const TEAM_MEMBER_ROLES = ['leader', 'deputy', 'member'];

    // ── Resources ───────────────────────────────────────────────────────────

    /** All valid resource status values. */
    public const RESOURCE_STATUSES = ['draft', 'pending_review', 'published', 'archived'];

    /**
     * Valid transitions for resource publishing workflow.
     * Draft → Pending Review → Published → Archived.
     * Pending Review can return to Draft for corrections.
     * Published can be Archived but not deleted.
     */
    public const RESOURCE_TRANSITIONS = [
        'draft' => ['pending_review'],
        'pending_review' => ['published', 'draft'],
        'published' => ['archived'],
        'archived' => [],
    ];

    /** Visibility scopes for resources. */
    public const RESOURCE_VISIBILITY_SCOPES = ['union', 'conference', 'campaign', 'site', 'team'];

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Return a comma-separated list suitable for in: validation rules. */
    public static function validationIn(array $values): string
    {
        return implode(',', $values);
    }
}
