<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\FieldReport;
use App\Models\MissionSite;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * FieldReportPolicy — authorization for field reports at every rung.
 *
 * Permission tiers:
 *   report.view        read official reports within the user's scope
 *   report.view_team   NARROW: read the DEVELOPING report of the user's OWN team
 *   report.submit      secretaries (site and team) may create and submit
 *   report.review      act on the review chain (approve, forward, consolidate,
 *                      recover) within the user's scope
 *
 * SCOPE SYMMETRY
 * --------------
 * `create` used to demand a campaign-wide appointment, which silently blocked
 * every site- and team-scoped secretary from filing their own report — even
 * though `submit` already accepted site scope. Creation and submission now
 * agree: a report may be created wherever the user may submit one. A site
 * secretary at Site A still cannot touch Site B, because the site-scope check
 * in hasPermissionForSite() only matches their own site (and, for a team-scoped
 * appointment, only the site that team belongs to).
 *
 * THE RUNG DECIDES WHO REVIEWS
 * ----------------------------
 * The hierarchy is Team Secretary → Team Leader → Site Secretary → Campaign
 * Leadership, so review authority follows the rung: campaign leadership may
 * review any report in the campaign, and a team report is first reviewed by its
 * own team leader. A site secretary does not approve a site report — they
 * RECEIVE and CONSOLIDATE it, and campaign leadership reviews the result.
 */
class FieldReportPolicy
{
    public function __construct(private AuthorizationService $auth) {}

    public function viewAny(User $user, Campaign $campaign): bool
    {
        // Admission to the reports listing. A user may open the listing when
        // they hold official report visibility (report.view) OR the narrow
        // developing-team-report visibility (report.view_team) in ANY scope
        // that resolves into this campaign. The controller then narrows the
        // rows: official scopes via scopeQuery(), team visibility via the
        // exact team ids from teamIdsWithPermission(), so a team member sees
        // only their own team's developing report and never the wider site.
        return $this->auth->hasAuthorisedScopeInCampaign(
            $user,
            ['report.view', 'report.view_team'],
            $campaign
        );
    }

    public function view(User $user, FieldReport $report): bool
    {
        $campaign = $report->campaign;
        $site     = $report->site;

        if ($this->auth->hasPermissionForCampaign($user, 'report.view', $campaign)) {
            return true;
        }

        // Site-scoped users can only see reports for their own site. A team
        // assignment is deliberately excluded from this arm: a team must never
        // inherit the whole site's official report visibility.
        if ($site && $this->auth->hasPermissionForSite($user, 'report.view', $site, false)) {
            return true;
        }

        // A team member sees the DEVELOPING report of their own team — and only
        // through the narrow report.view_team permission. This is deliberately
        // separate from official report access so that joining a team never
        // confers broad reporting rights.
        $team = $report->team;

        return $team !== null && $this->auth->hasPermissionForTeam($user, 'report.view_team', $team);
    }

    /**
     * create — may the user open a new report for this campaign/site?
     *
     * Accepts campaign OR site scope so that site- and team-scoped secretaries
     * can file their own reports without a campaign-wide appointment. When a
     * site is supplied the check narrows to it; when a campaign-level report is
     * being opened (no site) only campaign scope qualifies.
     */
    public function create(User $user, Campaign $campaign, ?MissionSite $site = null): bool
    {
        if ($this->auth->hasPermissionForCampaign($user, 'report.submit', $campaign)) {
            return true;
        }

        return $site !== null && $this->auth->hasPermissionForSite($user, 'report.submit', $site);
    }

    public function update(User $user, FieldReport $report): bool
    {
        // Only draft reports can be updated — lifecycle check is in controller.
        return $this->canSubmitForReport($user, $report);
    }

    /**
     * submit — move a draft to submitted status.
     * Requires report.submit at campaign or site scope.
     */
    public function submit(User $user, FieldReport $report): bool
    {
        return $this->canSubmitForReport($user, $report);
    }

    /**
     * review — may the user act at this report's review rung?
     *
     *   - campaign leadership (campaign/org-unit/global scope) may review a
     *     report at any level in the campaign;
     *   - a TEAM report is first reviewed by its own team leader (team scope).
     *
     * A site secretary is deliberately not included: they consolidate, they do
     * not approve a site report.
     */
    public function review(User $user, FieldReport $report): bool
    {
        if ($this->auth->hasPermissionForCampaign($user, 'report.review', $report->campaign)) {
            return true;
        }

        if ($report->isTeamLevel() && $report->team !== null) {
            return $this->auth->hasPermissionForTeam($user, 'report.review', $report->team);
        }

        return false;
    }

    /**
     * forward — advance a report past an outstanding review so a delayed
     * lower-level review cannot stall the chain indefinitely.
     */
    public function forward(User $user, FieldReport $report): bool
    {
        return $this->canActOnChain($user, $report);
    }

    /**
     * consolidate — attach submitted lower-level reports to this report.
     */
    public function consolidate(User $user, FieldReport $report): bool
    {
        return $this->canActOnChain($user, $report);
    }

    /**
     * recover — generate this report from the records that exist when a
     * lower-level submission is delayed. Generating is not approving.
     */
    public function recover(User $user, FieldReport $report): bool
    {
        return $this->canActOnChain($user, $report);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function canSubmitForReport(User $user, FieldReport $report): bool
    {
        $campaign = $report->campaign;

        if ($this->auth->hasPermissionForCampaign($user, 'report.submit', $campaign)) {
            return true;
        }

        // A team-scoped secretary may submit their OWN team's report. This is
        // the one place a team assignment is accepted, and it is anchored to
        // the exact team so it can never spill over to a sibling team or the
        // whole site.
        if ($report->team !== null && $this->auth->hasPermissionForTeam($user, 'report.submit', $report->team)) {
            return true;
        }

        // Otherwise the report belongs to a site, and only the site's own
        // officers (a site-scoped assignment, or a higher scope) may submit it.
        $site = $report->site;

        return $site !== null && $this->auth->hasPermissionForSite($user, 'report.submit', $site, false);
    }

    /**
     * Higher-level chain actions (forward, consolidate, recover) belong to the
     * rung that owns the report: campaign leadership across the whole campaign,
     * or a site officer within their own site. A team assignment is excluded —
     * a team leader reviews their team's report, but does not run the site's
     * consolidation.
     */
    private function canActOnChain(User $user, FieldReport $report): bool
    {
        if ($this->auth->hasPermissionForCampaign($user, 'report.review', $report->campaign)) {
            return true;
        }

        $site = $report->site;

        return $site !== null && $this->auth->hasPermissionForSite($user, 'report.review', $site, false);
    }
}
