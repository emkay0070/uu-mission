'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import {
  assignmentsApi,
  campaignsApi,
  outcomesApi,
  reportsApi,
  roleAssignmentsApi,
  sitesApi,
  type Assignment,
  type Campaign,
  type CampaignStats,
  type FieldReport,
  type MissionOutcome,
  type MissionSite,
  type RoleAssignment,
} from '@/lib/api';
import { EmptyState, Skeleton, StatCard, StatusBadge } from '@/components/ui';
import { useCampaignOperations } from '@/components/campaign-management/CampaignOpsContext';
import { NoCampaignContext } from '@/components/CampaignManagementShell';
import {
  AlertCircle,
  CalendarRange,
  CheckCircle2,
  FileText,
  Heart,
  MapPinned,
  Megaphone,
  Target,
  UserCheck,
  Users,
} from 'lucide-react';

/**
 * Everything the campaign overview reads, all scoped to the ACTIVE campaign.
 *
 * Each value comes from an existing, already-authorised endpoint. The overview
 * introduces no new backend surface and invents no metric: where a module
 * cannot yet answer a question, the page says so plainly instead of
 * fabricating a score or a percentage.
 */
interface OverviewData {
  campaign: Campaign;
  stats: CampaignStats;
  sites: MissionSite[];
  assignments: Assignment[];
  reportsAwaitingReview: FieldReport[];
  outcomes: MissionOutcome[];
  /** Users holding a campaign-scoped appointment — the campaign's leadership. */
  leadership: RoleAssignment[];
}

/**
 * /campaign-management — Campaign Leader overview.
 *
 * Answers the questions a campaign leader must ask:
 *   - How is this campaign progressing?  (status, dates, records)
 *   - Which sites are active?  (site statuses and their recorded activity)
 *   - What needs my attention?  (reviews, follow-up, coverage gaps)
 */
export default function CampaignManagementOverviewPage() {
  const { active } = useCampaignOperations();

  if (!active || active.campaign_id == null) {
    return <NoCampaignContext />;
  }

  // `key` remounts the board when the leader switches campaign, so the data
  // can never linger from the campaign they were previously overseeing.
  return <OverviewBoard key={active.campaign_id} campaignId={active.campaign_id} />;
}

function OverviewBoard({ campaignId }: { campaignId: number }) {
  const [data, setData] = useState<OverviewData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        setLoading(true);
        setError('');

        const [campaign, stats, sites, assignments, reports, outcomes, leadership] =
          await Promise.all([
            campaignsApi.get(campaignId),
            campaignsApi.stats(campaignId),
            sitesApi.list(campaignId),
            assignmentsApi.list(campaignId),
            reportsApi.list(campaignId, { pending_review: true }),
            outcomesApi.list(campaignId),
            // Leadership is a campaign-scoped appointment, so a failure here
            // must not take down the whole overview.
            roleAssignmentsApi.listForCampaign(campaignId).catch(() => []),
          ]);

        if (cancelled) return;

        setData({
          campaign,
          stats,
          sites,
          assignments,
          reportsAwaitingReview: reports,
          outcomes,
          leadership,
        });
      } catch (e) {
        if (!cancelled) {
          setError((e as { message?: string }).message ?? 'Failed to load this campaign.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [campaignId]);

  if (loading) {
    return (
      <div className="p-6 max-w-6xl mx-auto space-y-4">
        <Skeleton className="h-8 w-72" />
        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
          {[...Array(4)].map((_, i) => (
            <Skeleton key={i} className="h-24 rounded-xl" />
          ))}
        </div>
        <Skeleton className="h-56 rounded-xl" />
      </div>
    );
  }

  if (error || !data) {
    return (
      <div className="p-6 max-w-6xl mx-auto">
        <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
          {error || 'Failed to load this campaign.'}
        </div>
      </div>
    );
  }

  const { campaign, stats, sites, assignments, reportsAwaitingReview, outcomes, leadership } = data;

  const sitesActive = sites.filter((site) => site.status === 'active');
  const sitesPlanned = sites.filter((site) => site.status === 'planned');
  const sitesClosed = sites.filter((site) => site.status === 'closed');

  const personnelAwaitingArrival = assignments.filter((a) => a.status === 'confirmed');
  const personnelArrived = assignments.filter((a) => a.status === 'arrived');

  const outcomesAwaitingFollowUp = outcomes.filter(
    (outcome) =>
      outcome.follow_up_status === 'pending' || outcome.follow_up_status === 'assigned'
  );

  // Each attention row is a real, actionable fact for THIS campaign. Rows with
  // nothing outstanding are omitted, so the list is genuinely "what needs me"
  // rather than a fixed set of decorative cards.
  const attention = [
    reportsAwaitingReview.length > 0 && {
      href: '/campaign-management/reports',
      icon: FileText,
      tone: 'blue' as const,
      title: `${reportsAwaitingReview.length} ${
        reportsAwaitingReview.length === 1 ? 'report' : 'reports'
      } awaiting review`,
      body: 'Submitted reports still need a review decision in this campaign.',
    },
    outcomesAwaitingFollowUp.length > 0 && {
      href: '/campaign-management/outcomes',
      icon: Heart,
      tone: 'rose' as const,
      title: `${outcomesAwaitingFollowUp.length} ${
        outcomesAwaitingFollowUp.length === 1 ? 'outcome' : 'outcomes'
      } awaiting follow-up`,
      body: 'Recorded outcomes still need someone to close the loop.',
    },
    personnelAwaitingArrival.length > 0 && {
      href: '/campaign-management/deployments',
      icon: UserCheck,
      tone: 'amber' as const,
      title: `${personnelAwaitingArrival.length} ${
        personnelAwaitingArrival.length === 1 ? 'person' : 'people'
      } confirmed but not yet arrived`,
      body: 'Confirming arrival keeps the site coverage figures accurate.',
    },
    sitesPlanned.length > 0 && {
      href: '/campaign-management/sites',
      icon: MapPinned,
      tone: 'emerald' as const,
      title: `${sitesPlanned.length} ${
        sitesPlanned.length === 1 ? 'site' : 'sites'
      } still planned`,
      body: 'Planned sites are not yet operational on this campaign.',
    },
  ].filter(Boolean) as Array<{
    href: string;
    icon: typeof FileText;
    tone: 'amber' | 'blue' | 'rose' | 'emerald';
    title: string;
    body: string;
  }>;

  const toneClass = (tone: 'amber' | 'blue' | 'rose' | 'emerald') =>
    tone === 'amber'
      ? 'bg-amber-500/10 border-amber-500/20 text-amber-300'
      : tone === 'blue'
        ? 'bg-blue-500/10 border-blue-500/20 text-blue-300'
        : tone === 'rose'
          ? 'bg-rose-500/10 border-rose-500/20 text-rose-300'
          : 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300';

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-8 animate-fade-up">
      {/* Header — campaign identity */}
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div className="flex items-center gap-2 text-amber-300">
            <Megaphone className="w-4 h-4" />
            <span className="text-xs font-medium uppercase tracking-wider">
              Campaign Management
            </span>
          </div>
          <div className="flex items-center gap-3 mt-1 flex-wrap">
            <h1 className="text-2xl font-bold text-slate-100">{campaign.name}</h1>
            <StatusBadge status={campaign.status} />
          </div>
          <p className="text-sm text-slate-400 mt-1">
            {campaign.programme?.name ?? 'Programme'}
            {campaign.programme?.type?.label ? ` · ${campaign.programme.type.label}` : ''}
            {campaign.lead_org_unit?.name ? ` · led by ${campaign.lead_org_unit.name}` : ''}
          </p>
        </div>
        <div className="text-right text-xs text-slate-500">
          <div className="flex items-center gap-1.5 justify-end">
            <CalendarRange className="w-3.5 h-3.5" />
            <span>{formatDateRange(campaign.start_date, campaign.end_date)}</span>
          </div>
          {campaign.description && (
            <p className="mt-1 max-w-xs text-slate-500 line-clamp-2">{campaign.description}</p>
          )}
        </div>
      </div>

      {/* Campaign leadership — real appointments, not a fabricated score */}
      <section className="space-y-3">
        <h2 className="section-label">Campaign leadership</h2>
        {leadership.length === 0 ? (
          <div className="card p-4 text-sm text-slate-400">
            No campaign-scoped appointments recorded. The campaign is led by{' '}
            <span className="text-slate-300">
              {campaign.lead_org_unit?.name ?? 'its lead organisational unit'}
            </span>
            .
          </div>
        ) : (
          <div className="flex flex-wrap gap-2">
            {leadership.map((entry) => (
              <div key={entry.id} className="card px-3 py-2">
                <p className="text-sm text-slate-100">
                  {entry.user?.person?.full_name ?? entry.user?.email}
                </p>
                <p className="text-[11px] text-slate-500">{entry.role?.label}</p>
              </div>
            ))}
          </div>
        )}
      </section>

      {/* What needs campaign-level attention */}
      <section className="space-y-3">
        <h2 className="section-label">What needs your attention</h2>
        {attention.length === 0 ? (
          <div className="card p-5 flex items-center gap-3">
            <div className="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0">
              <CheckCircle2 className="w-4 h-4 text-emerald-300" />
            </div>
            <p className="text-sm text-slate-300">
              Nothing is waiting on you at campaign level right now.
            </p>
          </div>
        ) : (
          <div className="grid sm:grid-cols-2 gap-3">
            {attention.map((item) => {
              const Icon = item.icon;
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className="card p-4 flex items-start gap-3 hover:bg-surface-2 transition-colors"
                >
                  <div
                    className={`w-9 h-9 rounded-lg border flex items-center justify-center shrink-0 ${toneClass(item.tone)}`}
                  >
                    <Icon className="w-4 h-4" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-100">{item.title}</p>
                    <p className="text-xs text-slate-500 mt-0.5">{item.body}</p>
                  </div>
                </Link>
              );
            })}
          </div>
        )}
      </section>

      {/* Coverage figures — counts only, never a performance score */}
      <section className="space-y-3">
        <h2 className="section-label">Reach and coverage</h2>
        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <StatCard
            label="Sites"
            value={sites.length}
            sub={`${sitesActive.length} active · ${sitesPlanned.length} planned · ${sitesClosed.length} closed`}
            icon={<MapPinned className="w-4 h-4" />}
          />
          <StatCard
            label="Teams"
            value={stats.teams_count}
            sub={`across ${sites.length} ${sites.length === 1 ? 'site' : 'sites'}`}
            icon={<Users className="w-4 h-4" />}
          />
          <StatCard
            label="Personnel"
            value={stats.missionaries.total}
            sub={`${stats.missionaries.confirmed} confirmed · ${stats.missionaries.arrived} arrived`}
            icon={<UserCheck className="w-4 h-4" />}
          />
          <StatCard
            label="Outcomes"
            value={stats.outcomes.total}
            sub={`${outcomesAwaitingFollowUp.length} awaiting follow-up`}
            icon={<Target className="w-4 h-4" />}
          />
        </div>
      </section>

      {/* Sites and their operational status */}
      <section className="space-y-3">
        <h2 className="section-label flex items-center gap-2">
          <MapPinned className="w-3.5 h-3.5" /> Sites
        </h2>
        {sites.length === 0 ? (
          <EmptyState
            icon={<MapPinned className="w-6 h-6" />}
            title="No sites on this campaign"
            body="Mission sites created for this campaign will appear here with their status."
          />
        ) : (
          <div className="overflow-hidden rounded-xl border border-surface-border">
            <table className="w-full text-sm">
              <thead className="bg-surface-2 text-slate-400">
                <tr>
                  <th className="text-left px-4 py-2 font-medium">Site</th>
                  <th className="text-left px-4 py-2 font-medium">Status</th>
                  <th className="text-right px-4 py-2 font-medium">Teams</th>
                  <th className="text-right px-4 py-2 font-medium">Personnel</th>
                  <th className="text-right px-4 py-2 font-medium">Activities</th>
                  <th className="text-right px-4 py-2 font-medium">Reports</th>
                </tr>
              </thead>
              <tbody>
                {sites.map((site) => (
                  <tr key={site.id} className="border-t border-surface-border">
                    <td className="px-4 py-2.5 text-slate-200">{site.name}</td>
                    <td className="px-4 py-2.5">
                      <StatusBadge status={site.status} />
                    </td>
                    <td className="px-4 py-2.5 text-right tabular-nums text-slate-300">
                      {site.teams_count ?? 0}
                    </td>
                    <td className="px-4 py-2.5 text-right tabular-nums text-slate-300">
                      {site.assignments_count ?? 0}
                    </td>
                    <td className="px-4 py-2.5 text-right tabular-nums text-slate-300">
                      {site.activities_count ?? 0}
                    </td>
                    <td className="px-4 py-2.5 text-right tabular-nums text-slate-300">
                      {site.field_reports_count ?? 0}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {/* Reporting and recorded outcomes */}
      <section className="space-y-3">
        <h2 className="section-label flex items-center gap-2">
          <FileText className="w-3.5 h-3.5" /> Reporting and recorded fieldwork
        </h2>
        <div className="grid sm:grid-cols-3 gap-3">
          <div className="card p-4">
            <p className="section-label">Reports</p>
            <p className="text-2xl font-bold text-slate-100 tabular-nums">{stats.reports.total}</p>
            <p className="text-xs text-slate-500 mt-1">
              {stats.reports.pending} draft · {reportsAwaitingReview.length} awaiting review
            </p>
          </div>
          <div className="card p-4">
            <p className="section-label">Meetings held</p>
            <p className="text-2xl font-bold text-slate-100 tabular-nums">
              {stats.aggregated_from_reports.meetings_held}
            </p>
            <p className="text-xs text-slate-500 mt-1">
              {stats.aggregated_from_reports.total_attendance} total attendance
            </p>
          </div>
          <div className="card p-4">
            <p className="section-label">Decisions &amp; baptisms</p>
            <p className="text-2xl font-bold text-slate-100 tabular-nums">
              {stats.aggregated_from_reports.decisions}
            </p>
            <p className="text-xs text-slate-500 mt-1">
              {stats.aggregated_from_reports.baptisms} baptisms ·{' '}
              {stats.aggregated_from_reports.bible_studies} Bible studies
            </p>
          </div>
        </div>

        {Object.keys(stats.outcomes.by_type ?? {}).length > 0 && (
          <div className="card p-4">
            <p className="section-label mb-2">Outcomes by type</p>
            <div className="flex flex-wrap gap-2">
              {Object.entries(stats.outcomes.by_type).map(([type, count]) => (
                <span
                  key={type}
                  className="text-xs text-slate-300 bg-surface-2 border border-surface-border rounded-full px-2.5 py-1 capitalize"
                >
                  {type.replace(/_/g, ' ')}: <span className="tabular-nums">{count}</span>
                </span>
              ))}
            </div>
          </div>
        )}
      </section>

      {/* Honest data-availability note */}
      <section className="space-y-3">
        <h2 className="section-label flex items-center gap-2">
          <AlertCircle className="w-3.5 h-3.5" /> About these figures
        </h2>
        <div className="card p-5 border-l-4 border-amber-500/60 space-y-2">
          <p className="text-sm text-slate-300">
            Everything above is drawn from records that already exist on this campaign — nothing
            is estimated or scored. Two limitations are worth stating plainly:
          </p>
          <ul className="text-sm text-slate-400 space-y-1 list-disc pl-5">
            <li>
              Figures aggregate whatever has been recorded. Sites that have not yet filed reports
              or outcomes contribute nothing, so these are <span className="text-slate-300">floor</span>{' '}
              counts rather than completeness measures.
            </li>
            <li>
              No progress percentage, performance score or readiness indicator is shown, because
              none can be derived honestly from the records available today.
            </li>
          </ul>
        </div>
      </section>
    </div>
  );
}

/** Format a campaign's start/end dates, tolerating either being absent. */
function formatDateRange(start: string | null, end: string | null): string {
  const fmt = (value: string | null) => (value ? new Date(value).toLocaleDateString() : null);
  const from = fmt(start);
  const to = fmt(end);
  if (from && to) return `${from} → ${to}`;
  if (from) return `From ${from}`;
  if (to) return `Until ${to}`;
  return 'No dates set';
}
