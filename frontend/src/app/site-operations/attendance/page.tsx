'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { attendanceApi, type AttendanceRegister } from '@/lib/api';
import { EmptyState, Pagination, Skeleton, StatusBadge } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import { CheckCircle, ChevronRight, ClipboardList } from 'lucide-react';

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'submitted', label: 'Submitted' },
  { value: 'verified', label: 'Verified' },
] as const;

/**
 * /site-operations/attendance — attendance registers recorded at the active site.
 *
 * A coordinator who holds `attendance.verify` at this site can mark a
 * submitted register as verified; that control is shown only when the backend
 * would accept it, and the register reloads from the server afterwards so the
 * UI never claims a state the backend did not persist.
 */
export default function SiteAttendancePage() {
  const { active, canIn } = useSiteOperations();

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  return (
    <AttendanceBoard
      key={active.site_id}
      siteId={active.site_id}
      campaignId={active.campaign_id}
      canVerify={canIn('attendance.verify')}
    />
  );
}

function AttendanceBoard({
  siteId,
  campaignId,
  canVerify,
}: {
  siteId: number;
  campaignId: number;
  canVerify: boolean;
}) {
  const [registers, setRegisters] = useState<AttendanceRegister[]>([]);
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  async function load() {
    try {
      setLoading(true);
      setError('');
      const res = await attendanceApi.list({
        campaign_id: campaignId,
        mission_site_id: siteId,
        status: status || undefined,
        page,
        // The endpoint caps a page at 100 (`between:5,100`); anything larger
        // is a 422, so extra registers are reached by paging.
        per_page: 100,
      });
      setRegisters(res.data ?? []);
      setTotal(res.total ?? 0);
      setLastPage(res.last_page ?? 1);
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load attendance registers.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [campaignId, siteId, status, page]);

  async function verify(register: AttendanceRegister) {
    setBusyId(register.id);
    setError('');
    try {
      await attendanceApi.verify(register.id);
      await load();
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to verify the register.');
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-xl font-bold text-slate-100">Attendance</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {total} {total === 1 ? 'register' : 'registers'} at this site
          </p>
        </div>
        <div className="flex gap-2 flex-wrap">
          {STATUS_FILTERS.map((filter) => (
            <button
              key={filter.value}
              onClick={() => {
                setStatus(filter.value);
                // A different filter is a different result set: return to its
                // first page rather than staying on a page that may not exist.
                setPage(1);
              }}
              className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${
                status === filter.value
                  ? 'bg-emerald-600 text-white'
                  : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'
              }`}
            >
              {filter.label}
            </button>
          ))}
        </div>
      </div>

      {error && (
        <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
          {error}
        </div>
      )}

      {loading ? (
        <div className="space-y-2">
          {[...Array(6)].map((_, i) => (
            <Skeleton key={i} className="h-16 rounded-xl" />
          ))}
        </div>
      ) : registers.length === 0 ? (
        <EmptyState
          icon={<ClipboardList className="w-6 h-6" />}
          title="No attendance registers"
          body="Registers captured at this site will appear here for verification."
        />
      ) : (
        <div className="space-y-2">
          {registers.map((register) => (
            <div key={register.id} className="card p-4 flex items-center gap-3">
              {/* The row opens the register for inspection; verification stays
                  a separate control so it is never triggered by navigation. */}
              <Link
                href={`/site-operations/attendance/${register.id}`}
                className="flex items-center gap-3 flex-1 min-w-0"
              >
                <div className="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0">
                  <ClipboardList className="w-4 h-4 text-emerald-300" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100 truncate">
                    {register.register_name}
                  </p>
                  <p className="text-xs text-slate-500">
                    {register.register_date} · {register.register_type.replace('_', ' ')}
                    {register.activity?.title ? ` · ${register.activity.title}` : ''}
                  </p>
                </div>
                <StatusBadge status={register.status} />
                <ChevronRight className="w-4 h-4 text-slate-600 shrink-0" />
              </Link>
              {canVerify && register.status === 'submitted' && (
                <button
                  onClick={() => verify(register)}
                  disabled={busyId === register.id}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-medium shrink-0"
                >
                  <CheckCircle className="w-3.5 h-3.5" />
                  {busyId === register.id ? 'Verifying…' : 'Verify'}
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      <Pagination
        page={page}
        lastPage={lastPage}
        total={total}
        onPageChange={setPage}
        disabled={loading}
      />
    </div>
  );
}
