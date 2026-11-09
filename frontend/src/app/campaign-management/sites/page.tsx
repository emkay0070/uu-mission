'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { sitesApi, type MissionSite } from '@/lib/api';
import { EmptyState, ErrorBanner, Skeleton, StatusBadge } from '@/components/ui';
import { useCampaignOperations } from '@/components/campaign-management/CampaignOpsContext';
import { NoCampaignContext } from '@/components/CampaignManagementShell';
import { AlertCircle, MapPinned, Users } from 'lucide-react';

type SiteFilter = 'all' | 'active' | 'planned' | 'closed';

const FILTERS: Array<{ key: SiteFilter; label: string }> = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'planned', label: 'Planned' },
  { key: 'closed', label: 'Closed' },
];

/**
 * /campaign-management/sites — the campaign's mission sites.
 *
 * A campaign leader needs to see where the campaign is actually operating and
 * where coverage is thin. Every figure is taken from the recorded sites and
 * their counts; the page deliberately shows a site's operational window and
 * its staffing so a gap is visible rather than hidden behind a single status.
 */
export default function CampaignSitesPage() {
  const { active } = useCampaignOperations();

  if (!active || active.campaign_id == null) {
    return <NoCampaignContext />;
  }

  return <SitesBoard key={active.campaign_id} campaignId={active.campaign_id} />;
}

function SitesBoard({ campaignId }: { campaignId: number }) {
  const [sites, setSites] = useState<MissionSite[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [filter, setFilter] = useState<SiteFilter>('all');

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        setLoading(true);
        setError('');
        const list = await sitesApi.list(campaignId);
        if (!cancelled) setSites(list);
      } catch (e) {
        if (!cancelled) {
          setError((e as { message?: string }).message ?? 'Failed to load sites.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [campaignId]);

  const counts = {
    all: sites.length,
    active: sites.filter((site) => site.status === 'active').length,
    planned: sites.filter((site) => site.status === 'planned').length,
    closed: sites.filter((site) => site.status === 'closed').length,
  };

  const visible = filter === 'all' ? sites : sites.filter((site) => site.status === filter);

  // Coverage gaps are computed from the counts the API already returns, so a
  // leader can act on them without the page inventing a readiness score.
  const withoutTeams = sites.filter((site) => (site.teams_count ?? 0) === 0);
  const withoutPersonnel = sites.filter(
    (site) => site.status === 'active' && (site.assignments_count ?? 0) === 0
  );
  const withoutReports = sites.filter(
    (site) => site.status === 'active' && (site.field_reports_count ?? 0) === 0
  );

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6 animate-fade-up">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-xl font-bold text-slate-100">Sites</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {counts.all} {counts.all === 1 ? 'site' : 'sites'} on this campaign
          </p>
        </div>
        <div className="flex gap-1.5">
          {FILTERS.map((entry) => (
            <button
              key={entry.key}
              onClick={() => setFilter(entry.key)}
              className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                filter === entry.key
                  ? 'bg-amber-600/20 text-amber-300 border border-amber-600/30'
                  : 'bg-surface-2 border border-surface-border text-slate-400 hover:text-slate-200'
              }`}
            >
              {entry.label} ({counts[entry.key]})
            </button>
          ))}
        </div>
      </div>

      {error && <ErrorBanner message={error} />}

      {/* Coverage gaps — real, actionable facts drawn from the sites' counts. */}
      {!loading && sites.length > 0 && (withoutTeams.length > 0 || withoutPersonnel.length > 0 || withoutReports.length > 0) && (
        <div className="card p-4 border-l-4 border-amber-500/60 space-y-1.5">
          <p className="text-sm font-medium text-slate-200 flex items-center gap-2">
            <AlertCircle className="w-4 h-4 text-amber-300" /> Coverage to review
          </p>
          <ul className="text-sm text-slate-400 space-y-1 list-disc pl-5">
            {withoutTeams.length > 0 && (
              <li>
                {withoutTeams.length} {withoutTeams.length === 1 ? 'site has' : 'sites have'} no
                teams yet.
              </li>
            )}
            {withoutPersonnel.length > 0 && (
              <li>
                {withoutPersonnel.length} active{' '}
                {withoutPersonnel.length === 1 ? 'site has' : 'sites have'} no deployed personnel.
              </li>
            )}
            {withoutReports.length > 0 && (
              <li>
                {withoutReports.length} active{' '}
                {withoutReports.length === 1 ? 'site has' : 'sites have'} recorded no field reports.
              </li>
            )}
          </ul>
        </div>
      )}

      {loading ? (
        <div className="space-y-2">
          {[...Array(5)].map((_, i) => (
            <Skeleton key={i} className="h-16 rounded-xl" />
          ))}
        </div>
      ) : sites.length === 0 ? (
        <EmptyState
          icon={<MapPinned className="w-6 h-6" />}
          title="No sites on this campaign"
          body="Mission sites created for this campaign will appear here with their status and coverage."
        />
      ) : (
        <div className="space-y-2">
          {visible.map((site) => (
            <div key={site.id} className="card p-4">
              <div className="flex items-start justify-between gap-3 flex-wrap">
                <div className="min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <p className="text-sm font-medium text-slate-100">{site.name}</p>
                    <StatusBadge status={site.status} />
                  </div>
                  <p className="text-xs text-slate-500 mt-0.5">
                    {site.location_description ?? 'No location recorded'}
                    {site.operational_from || site.operational_until
                      ? ` · ${site.operational_from ?? '—'} → ${site.operational_until ?? 'open'}`
                      : ''}
                  </p>
                </div>
                <div className="flex items-center gap-4 text-xs text-slate-400">
                  <span className="inline-flex items-center gap-1">
                    <Users className="w-3.5 h-3.5" /> {site.teams_count ?? 0} teams
                  </span>
                  <span className="tabular-nums">
                    {site.assignments_count ?? 0} personnel
                  </span>
                  <span className="tabular-nums">
                    {site.activities_count ?? 0} activities
                  </span>
                  <span className="tabular-nums">
                    {site.field_reports_count ?? 0} reports
                  </span>
                  <span className="tabular-nums">
                    {site.outcomes_count ?? 0} outcomes
                  </span>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      <p className="text-xs text-slate-500">
        Figures are counts of what has been recorded, not completeness measures. Site personnel
        and deployment detail is available under{' '}
        <Link href="/campaign-management/deployments" className="text-amber-300 hover:underline">
          Deployments
        </Link>
        .
      </p>
    </div>
  );
}
