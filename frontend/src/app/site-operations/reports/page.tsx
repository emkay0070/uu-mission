'use client';

import { useEffect, useState } from 'react';
import { reportsApi, type FieldReport } from '@/lib/api';
import { EmptyState, ErrorBanner, Skeleton, StatusBadge } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import { CheckCircle, FileText, RotateCcw, Send } from 'lucide-react';

/**
 * /site-operations/reports — field reports for the active site.
 *
 * Shows the reporting figures the site actually recorded and lets a reviewer
 * act on reports awaiting a decision. Approve/Return are shown only when the
 * person holds `report.review` at this site, and Submit only when they hold
 * `report.submit`; the backend re-checks both.
 */
export default function SiteReportsPage() {
  const { active, canIn } = useSiteOperations();

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  return (
    <ReportsBoard
      key={active.site_id}
      siteId={active.site_id}
      campaignId={active.campaign_id}
      canReview={canIn('report.review')}
      canSubmit={canIn('report.submit')}
    />
  );
}

function ReportsBoard({
  siteId,
  campaignId,
  canReview,
  canSubmit,
}: {
  siteId: number;
  campaignId: number;
  canReview: boolean;
  canSubmit: boolean;
}) {
  const [reports, setReports] = useState<FieldReport[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  async function load() {
    try {
      setLoading(true);
      setError('');
      const list = await reportsApi.list(campaignId, { site_id: siteId });
      setReports(list);
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load reports.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [campaignId, siteId]);

  async function act(report: FieldReport, action: () => Promise<unknown>) {
    setBusyId(report.id);
    setError('');
    try {
      await action();
      await load();
    } catch (e) {
      setError((e as { message?: string }).message ?? 'The action could not be completed.');
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div>
        <h1 className="text-xl font-bold text-slate-100">Reports</h1>
        <p className="text-sm text-slate-400 mt-0.5">
          {reports.length} {reports.length === 1 ? 'report' : 'reports'} for this site
        </p>
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
          title="No reports for this site"
          body="Field reports filed at this site will appear here for review."
        />
      ) : (
        <div className="space-y-3">
          {reports.map((report) => (
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

              {(canSubmit || canReview) && (
                <div className="flex gap-2">
                  {canSubmit && report.status === 'draft' && (
                    <button
                      onClick={() => act(report, () => reportsApi.submit(campaignId, report.id))}
                      disabled={busyId === report.id}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-medium"
                    >
                      <Send className="w-3.5 h-3.5" /> Submit
                    </button>
                  )}
                  {canReview && (report.status === 'submitted' || report.status === 'reviewed') && (
                    <>
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
                    </>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
