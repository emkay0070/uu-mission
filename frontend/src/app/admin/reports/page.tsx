'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { campaignsApi, reportsApi, type Campaign, type FieldReport } from '@/lib/api';
import { StatusBadge, Skeleton, EmptyState } from '@/components/ui';
import { FileText, Globe, CheckCircle, RotateCcw, Send } from 'lucide-react';
import type { ApiError } from '@/lib/api';

export default function AllReportsPage() {
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [reportsMap, setReportsMap] = useState<Record<number, FieldReport[]>>({});
  const [filter, setFilter]   = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      setLoading(true);
      const camps = await campaignsApi.list({ status: 'active' });
      setCampaigns(camps.data ?? []);
      const params = filter ? { status: filter } : undefined;
      const entries = await Promise.all(
        (camps.data ?? []).map(async c => [c.id, await reportsApi.list(c.id, params)] as [number, FieldReport[]])
      );
      setReportsMap(Object.fromEntries(entries));
      setLoading(false);
    }
    load();
  }, [filter]);

  async function submitReport(c: Campaign, r: FieldReport) {
    try { await reportsApi.submit(c.id, r.id); setFilter(f => f); } catch (e) { alert((e as ApiError).message); }
  }

  async function reviewReport(c: Campaign, r: FieldReport, action: 'approve' | 'return') {
    try { await reportsApi.review(c.id, r.id, action); setFilter(f => f); } catch (e) { alert((e as ApiError).message); }
  }

  const STATUSES = ['', 'draft', 'submitted', 'reviewed', 'approved'];
  const totalReports = Object.values(reportsMap).flat().length;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-100">Field Reports</h1>
          <p className="text-sm text-slate-400 mt-0.5">{totalReports} reports across all campaigns</p>
        </div>
        <div className="flex gap-2 flex-wrap">
          {STATUSES.map(s => (
            <button key={s} onClick={() => setFilter(s)}
              className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${
                filter === s ? 'bg-brand-600 text-white' : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'
              }`}
            >{s === '' ? 'All' : s}</button>
          ))}
        </div>
      </div>

      {loading ? (
        <div className="space-y-3">{[...Array(5)].map((_, i) => <Skeleton key={i} className="h-24 rounded-xl" />)}</div>
      ) : totalReports === 0 ? (
        <EmptyState icon={<FileText className="w-6 h-6" />} title="No reports" body="No field reports match the selected filter." />
      ) : campaigns.map(c => {
        const reports = reportsMap[c.id] ?? [];
        if (!reports.length) return null;
        return (
          <div key={c.id}>
            <div className="flex items-center gap-2 mb-2">
              <Globe className="w-3.5 h-3.5 text-brand-400" />
              <Link href={`/admin/campaigns/${c.id}`} className="text-sm font-semibold text-slate-300 hover:text-brand-300 transition-colors">
                {c.name}
              </Link>
            </div>
            <div className="space-y-2 pl-5">
              {reports.map(r => (
                <div key={r.id} className="card p-4 space-y-3">
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <div className="flex items-center gap-2">
                        <p className="text-sm font-medium text-slate-100">
                          {r.site?.name ?? `Site #${r.mission_site_id}`}
                        </p>
                        <StatusBadge status={r.status} />
                        <span className="text-xs text-slate-600 bg-surface-2 px-2 py-0.5 rounded">{r.report_period_type}</span>
                      </div>
                      <p className="text-xs text-slate-500 mt-0.5">{r.period_start} → {r.period_end}</p>
                    </div>
                  </div>
                  <div className="grid grid-cols-5 gap-1.5">
                    {[
                      ['Meetings', r.stat_meetings_held],
                      ['Attend.', r.stat_total_attendance],
                      ['Decisions', r.stat_decisions],
                      ['Baptisms', r.stat_baptisms],
                      ['Bible St.', r.stat_bible_studies_requested],
                    ].map(([l, v]) => (
                      <div key={l as string} className="text-center bg-surface-2 rounded-lg py-1.5 px-1">
                        <p className="text-xs font-bold text-slate-100 tabular-nums">{v}</p>
                        <p className="text-[9px] text-slate-500">{l}</p>
                      </div>
                    ))}
                  </div>
                  <div className="flex gap-2">
                    {r.status === 'draft' && (
                      <button onClick={() => submitReport(c, r)} className="btn-primary btn-sm">
                        <Send className="w-3 h-3" /> Submit
                      </button>
                    )}
                    {(r.status === 'submitted' || r.status === 'reviewed') && (
                      <>
                        <button onClick={() => reviewReport(c, r, 'approve')} className="btn-primary btn-sm">
                          <CheckCircle className="w-3 h-3" /> Approve
                        </button>
                        <button onClick={() => reviewReport(c, r, 'return')} className="btn-ghost btn-sm">
                          <RotateCcw className="w-3 h-3" /> Return
                        </button>
                      </>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}
