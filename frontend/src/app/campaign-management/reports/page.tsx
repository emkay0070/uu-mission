'use client';

import { useEffect, useState } from 'react';
import { reportsApi, type FieldReport } from '@/lib/api';
import { EmptyState, ErrorBanner, Skeleton, StatusBadge } from '@/components/ui';
import { useCampaignOperations } from '@/components/campaign-management/CampaignOpsContext';
import { NoCampaignContext } from '@/components/CampaignManagementShell';
import { CheckCircle, FileText, RotateCcw } from 'lucide-react';

type ReportFilter = 'all' | 'pending_review' | 'overdue';

const FILTERS: Array<{ key: ReportFilter; label: string }> = [
  { key: 'all', label: 'All reports' },
  { key: 'pending_review', label: 'Awaiting review' },
  { key: 'overdue', label: 'Overdue' },
];

/**
 * /campaign-management/reports — campaign reporting and review.
 *
 * Shows the reporting picture across the whole campaign — team, site and
 * campaign-level reports — and lets a person holding `report.review` on this
 * campaign act on reports awaiting a decision. Reporting progress is expressed
 * as counts of what is recorded; no completion percentage is invented.
 */
export default function CampaignReportsPage() {
  const { active, canIn } = useCampaignOperations();

  if (!active || active.campaign_id == null) {
    return <NoCampaignContext />;
  }

  return (
    <ReportsBoard
      key={active.campaign_id}
      campaignId={active.campaign_id}
      canReview={canIn('report.review')}
    />
  );
}

function ReportsBoard({ campaignId, canReview }: { campaignId: number; canReview: boolean }) {
  const [reports, setReports] = useState<FieldReport[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [filter, setFilter] = useState<ReportFilter>('all');
  const [busyId, setBusyId] = useState<number | null>(null);

  async function load(next: ReportFilter) {
    try {
      setLoading(true);
      setError('');
      const params =
        next === 'pending_review'
          ? { pending_review: true }
          : next === 'overdue'
            ? { overdue: true }
            : undefined;
      const list = await reportsApi.list(campaignId, params);
      setReports(list);
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load reports.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load(filter);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [campaignId, filter]);

  async function act(report: FieldReport, action: () => Promise<unknown>) {
    setBusyId(report.id);
    setError('');
    try {
      await action();
      await load(filter);
    } catch (e) {
      setError((e as { message?: string }).message ?? 'The action could not be completed.');
    } finally {
      setBusyId(null);
    }
  }

  const draftCount = reports.filter((report) => report.status === 'draft').length;
  const awaitingReview = reports.filter(
    (report) => report.status === 'submitted' || report.status === 'reviewed'
  ).length;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-xl font-bold text-slate-100">Reports</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {reports.length} {reports.length === 1 ? 'report' : 'reports'} · {draftCount} draft ·{' '}
            {awaitingReview} awaiting review
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
              {entry.label}
            </button>
          ))}
        </div>
      </div>

      {error && <ErrorBanner message={error} />}

      {loading ? (
        <div className="space-y-3">
          {[...Array(4)].map((_, i) => (
            <Skeleton key={i} className="h-28 rounded-xl" />
          ))}
        </div>
      ) : reports.length === 0 ? (
        <EmptyState
          icon={<FileText className="w-6 h-6" />}
          title={filter === 'all' ? 'No reports on this campaign' : 'Nothing in this view'}
          body={
            filter === 'all'
              ? 'Team, site and campaign reports will appear here as they are filed.'
              : 'No reports currently match this filter.'
          }
        />
      ) : (
        <div className="space-y-3">
          {reports.map((report) => {
            const canAct = canReview && (report.status === 'submitted' || report.status === 'reviewed');
            return (
              <div key={report.id} className="card p-4 space-y-3">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="text-sm font-medium text-slate-100 capitalize">
                        {report.report_level} report
                      </p>
                      <StatusBadge status={report.status} />
                      <span className="text-xs text-slate-500 bg-surface-2 px-2 py-0.5 rounded">
                        {report.report_period_type}
                      </span>
                      {report.review_state && (
                        <span className="text-xs text-amber-300/80">
                          {report.review_state.replace(/_/g, ' ')}
                        </span>
                      )}
                    </div>
                    <p className="text-xs text-slate-500 mt-1">
                      {report.period_start} → {report.period_end}
                      {report.site?.name ? ` · ${report.site.name}` : ''}
                      {report.team?.name ? ` · ${report.team.name}` : ''}
                    </p>
                  </div>
                </div>

                <div className="grid grid-cols-5 gap-1.5">
                  {[
                    ['Meetings', report.stat_meetings_held],
                    ['Attend.', report.stat_total_attendance],
                    ['Decisions', report.stat_decisions],
                    ['Baptisms', report.stat_baptisms],
                    ['Bible St.', report.stat_bible_studies_requested],
                  ].map(([label, value]) => (
                    <div key={label as string} className="text-center bg-surface-2 rounded-lg py-1.5 px-1">
                      <p className="text-sm font-bold text-slate-100 tabular-nums">{value}</p>
                      <p className="text-[9px] text-slate-500">{label}</p>
                    </div>
                  ))}
                </div>

                {canAct && (
                  <div className="flex gap-2">
                    <button
                      onClick={() =>
                        act(report, () => reportsApi.review(campaignId, report.id, 'approve'))
                      }
                      disabled={busyId === report.id}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-medium"
                    >
                      <CheckCircle className="w-3.5 h-3.5" /> Approve
                    </button>
                    <button
                      onClick={() =>
                        act(report, () => reportsApi.review(campaignId, report.id, 'return'))
                      }
                      disabled={busyId === report.id}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-surface-2 border border-surface-border hover:text-slate-100 disabled:opacity-50 text-slate-300 text-xs font-medium"
                    >
                      <RotateCcw className="w-3.5 h-3.5" /> Return
                    </button>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      <p className="text-xs text-slate-500">
        Reporting progress is shown as counts of what has been filed. A campaign may still be
        awaiting reports from sites that have not yet filed one; those absences are not shown as a
        percentage.
      </p>
    </div>
  );
}
