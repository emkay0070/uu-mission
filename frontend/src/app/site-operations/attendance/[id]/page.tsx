'use client';

import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import {
  attendanceApi,
  type AttendanceParticipant,
  type AttendanceReconciliation,
  type AttendanceRegister,
} from '@/lib/api';
import { EmptyState, ErrorBanner, Skeleton, StatCard, StatusBadge, SuccessBanner } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { SiteOpsBackLink } from '@/components/site-operations/SiteOpsBackLink';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import {
  AlertTriangle,
  CheckCircle,
  ClipboardList,
  Loader2,
  Users2,
} from 'lucide-react';

/**
 * /site-operations/attendance/[id] — inspect one attendance register for the
 * ACTIVE site.
 *
 * Both supported register models are rendered:
 *   - a SITE-WIDE register (activity_id null) — the site/day roll call;
 *   - an ACTIVITY register (activity_id set) — a roll call for one activity,
 *     whose planned roster is merged into the participants view.
 *
 * WHAT IS SHOWN
 *   - the register's title, date, type, status and its site/activity context;
 *   - the attendance entries, merged with the planned roster, with each row's
 *     roster/present/walk-in/anonymous facts reported INDEPENDENTLY (the
 *     backend never guesses one from the other);
 *   - a summary of present vs expected, plus reconciliation's unmatched and
 *     unreconciled information, so incomplete records are visible rather than
 *     silently rounded away.
 *
 * VERIFICATION is preserved as-is: the control appears only when the operator
 * holds `attendance.verify` at this site AND the register is submitted, and the
 * register is re-read from the server afterwards so the UI never claims a state
 * the backend did not persist. Recording attendance is deliberately NOT offered
 * here — this pass is inspection only.
 */
export default function SiteAttendanceDetailPage() {
  const { active, canIn } = useSiteOperations();
  const params = useParams<{ id: string }>();
  const registerId = Number(params?.id);

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  if (!Number.isFinite(registerId) || registerId <= 0) {
    return (
      <div className="p-6 max-w-4xl mx-auto">
        <ErrorBanner message="This attendance register could not be identified." />
      </div>
    );
  }

  return (
    <AttendanceRegisterDetail
      key={`${active.site_id}-${registerId}`}
      siteId={active.site_id}
      campaignId={active.campaign_id}
      registerId={registerId}
      canVerify={canIn('attendance.verify')}
    />
  );
}

function AttendanceRegisterDetail({
  siteId,
  campaignId,
  registerId,
  canVerify,
}: {
  siteId: number;
  campaignId: number;
  registerId: number;
  canVerify: boolean;
}) {
  const [register, setRegister] = useState<AttendanceRegister | null>(null);
  const [participants, setParticipants] = useState<AttendanceParticipant[]>([]);
  const [reconciliation, setReconciliation] = useState<AttendanceReconciliation | null>(null);
  const [summary, setSummary] = useState<RegisterSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [verifying, setVerifying] = useState(false);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      setError('');
      const [reg, people, recon] = await Promise.all([
        attendanceApi.get(registerId),
        attendanceApi.participants(registerId),
        // Reconciliation is a pure read of the site/day; it is what surfaces
        // people recorded on an activity but missing from the site register.
        attendanceApi.reconciliation(registerId).catch(() => null),
      ]);
      setRegister(reg);
      setParticipants(people);
      setReconciliation(recon);
      setSummary(summarise(people));
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load this register.');
    } finally {
      setLoading(false);
    }
  }, [registerId]);

  useEffect(() => {
    load();
  }, [load]);

  async function verify() {
    setVerifying(true);
    setError('');
    setNotice('');
    try {
      await attendanceApi.verify(registerId);
      await load();
      setNotice('Register verified.');
    } catch (e) {
      setError((e as { message?: string }).message ?? 'The register could not be verified.');
    } finally {
      setVerifying(false);
    }
  }

  if (loading) {
    return (
      <div className="p-6 max-w-4xl mx-auto space-y-4">
        <Skeleton className="h-6 w-48 rounded-lg" />
        <Skeleton className="h-28 rounded-xl" />
        <Skeleton className="h-20 rounded-xl" />
        <Skeleton className="h-64 rounded-xl" />
      </div>
    );
  }

  if (error && !register) {
    return (
      <div className="p-6 max-w-4xl mx-auto space-y-4">
        <SiteOpsBackLink href="/site-operations/attendance" label="Back to attendance" />
        <ErrorBanner message={error} />
      </div>
    );
  }

  if (!register) {
    return (
      <div className="p-6 max-w-4xl mx-auto space-y-4">
        <SiteOpsBackLink href="/site-operations/attendance" label="Back to attendance" />
        <EmptyState icon={<ClipboardList className="w-6 h-6" />} title="Register not found" />
      </div>
    );
  }

  const foreignSite = register.mission_site_id !== siteId;
  const canVerifyHere = canVerify && !foreignSite && register.status === 'submitted';

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <SiteOpsBackLink href="/site-operations/attendance" label="Back to attendance" />

      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div className="min-w-0">
          <h1 className="text-xl font-bold text-slate-100 truncate">{register.register_name}</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {formatDate(register.register_date)} · {registerTypeLabel(register.register_type)}
            {register.site ? ` · ${register.site.name}` : ''}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <StatusBadge status={register.status} />
          {canVerifyHere && (
            <button
              onClick={verify}
              disabled={verifying}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-sm font-medium transition-colors"
            >
              {verifying ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <CheckCircle className="w-4 h-4" />
              )}
              {verifying ? 'Verifying…' : 'Verify register'}
            </button>
          )}
        </div>
      </div>

      {foreignSite && (
        <ErrorBanner message="This register belongs to a different site. Switch to that site to inspect it." />
      )}

      {error && <ErrorBanner message={error} />}
      {notice && <SuccessBanner message={notice} />}

      {/* Register context — which of the two models this is, and what it belongs to. */}
      <div className="card p-4 space-y-2">
        <p className="section-label">Register context</p>
        <dl className="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
          <div className="flex justify-between gap-3">
            <dt className="text-slate-500">Site</dt>
            <dd className="text-slate-200 text-right">{register.site?.name ?? '—'}</dd>
          </div>
          <div className="flex justify-between gap-3">
            <dt className="text-slate-500">Register type</dt>
            <dd className="text-slate-200 text-right">{registerTypeLabel(register.register_type)}</dd>
          </div>
          {register.activity ? (
            <>
              <div className="flex justify-between gap-3">
                <dt className="text-slate-500">Activity</dt>
                <dd className="text-slate-200 text-right">{register.activity.title}</dd>
              </div>
              <div className="flex justify-between gap-3">
                <dt className="text-slate-500">Activity type</dt>
                <dd className="text-slate-200 text-right">
                  {register.activity.activity_type?.label ?? '—'}
                </dd>
              </div>
            </>
          ) : (
            <div className="flex justify-between gap-3 sm:col-span-2">
              <dt className="text-slate-500">Scope</dt>
              <dd className="text-slate-200 text-right">Site-wide day register</dd>
            </div>
          )}
          {register.submitted_at && (
            <div className="flex justify-between gap-3">
              <dt className="text-slate-500">Submitted</dt>
              <dd className="text-slate-200 text-right">{formatDateTime(register.submitted_at)}</dd>
            </div>
          )}
          {register.verified_at && (
            <div className="flex justify-between gap-3">
              <dt className="text-slate-500">Verified</dt>
              <dd className="text-slate-200 text-right">{formatDateTime(register.verified_at)}</dd>
            </div>
          )}
        </dl>
      </div>

      {/* Summary */}
      {summary && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <StatCard label="Present" value={summary.present} sub={`of ${summary.total} recorded`} />
          <StatCard
            label="Expected"
            value={summary.expected}
            sub={summary.expectedNotRecorded > 0 ? `${summary.expectedNotRecorded} not recorded` : 'on roster'}
          />
          <StatCard label="Walk-ins" value={summary.walkIns} sub="not on the planned roster" />
          <StatCard
            label="Anonymous"
            value={summary.anonymous}
            sub={summary.namedUnidentified > 0 ? `${summary.namedUnidentified} named only` : 'unidentified only'}
          />
        </div>
      )}

      {/* Reconciliation — incomplete or unmatched attendance, stated honestly. */}
      {reconciliation && (
        <ReconciliationPanel reconciliation={reconciliation} />
      )}

      {/* Entries */}
      <div className="card p-5 space-y-4">
        <div className="flex items-center justify-between gap-3">
          <p className="section-label">Attendance entries</p>
          <p className="text-xs text-slate-500">
            {participants.length} {participants.length === 1 ? 'person' : 'people'}
          </p>
        </div>

        {participants.length === 0 ? (
          <EmptyState
            icon={<Users2 className="w-6 h-6" />}
            title="No attendance recorded"
            body="No roster or attendance rows exist for this register yet."
          />
        ) : (
          <ul className="divide-y divide-surface-border">
            {participants.map((row, index) => (
              <li key={row.entry_uuid ?? `row-${index}`} className="flex items-start gap-3 py-3">
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100 truncate">{row.name}</p>
                  <p className="text-xs text-slate-500 truncate">
                    {[row.village, row.phone].filter(Boolean).join(' · ') || 'No further details'}
                  </p>
                  <div className="flex flex-wrap gap-1.5 mt-1.5">
                    {row.is_on_roster && <Chip tone="neutral">On roster</Chip>}
                    {row.is_walk_in && <Chip tone="amber">Walk-in</Chip>}
                    {row.is_anonymous && <Chip tone="neutral">Anonymous</Chip>}
                    {!row.person_id && !row.is_anonymous && <Chip tone="neutral">Unidentified</Chip>}
                    {row.is_first_time && <Chip tone="emerald">First time</Chip>}
                    {row.decision_made && <Chip tone="emerald">Decision: {row.decision_made}</Chip>}
                  </div>
                </div>
                <span
                  className={
                    row.is_present
                      ? 'badge bg-emerald-500/15 text-emerald-300 shrink-0'
                      : 'badge bg-slate-500/15 text-slate-400 shrink-0'
                  }
                >
                  {row.is_present ? 'Present' : 'Not recorded'}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

// ── Reconciliation panel ─────────────────────────────────────────────────────

function ReconciliationPanel({ reconciliation }: { reconciliation: AttendanceReconciliation }) {
  const missing = reconciliation.missing_from_site_register ?? [];
  const unmatchedSite = reconciliation.unmatched?.site;
  const hasUnmatched =
    (unmatchedSite?.anonymous ?? 0) > 0 || (unmatchedSite?.named_unidentified ?? 0) > 0;

  if (!reconciliation.unreconciled?.is_unreconciled && missing.length === 0 && !hasUnmatched) {
    return (
      <div className="card p-4 flex items-start gap-3">
        <CheckCircle className="w-4 h-4 text-emerald-300 mt-0.5 shrink-0" />
        <div>
          <p className="text-sm text-slate-200">Attendance reconciled</p>
          <p className="text-xs text-slate-500 mt-0.5">
            No identifiable activity attendee is missing from this site&apos;s registers for the day.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="card p-4 space-y-3">
      <div className="flex items-center gap-2">
        <AlertTriangle className="w-4 h-4 text-amber-300" />
        <p className="section-label">Reconciliation</p>
      </div>

      {reconciliation.unreconciled?.is_unreconciled && (
        <p className="text-sm text-amber-200">{reconciliation.unreconciled.message}</p>
      )}

      {missing.length > 0 && (
        <div className="space-y-1.5">
          <p className="text-xs font-medium text-slate-400">
            Recorded on an activity but not on this site register
          </p>
          <ul className="flex flex-wrap gap-1.5">
            {missing.map((person) => (
              <li key={person.person_id} className="text-xs text-slate-300 bg-surface-2 border border-surface-border rounded-md px-2 py-1">
                {person.name ?? `Person #${person.person_id}`}
              </li>
            ))}
          </ul>
        </div>
      )}

      {hasUnmatched && (
        <p className="text-xs text-slate-500">
          Not matched to a person: {unmatchedSite?.anonymous ?? 0} anonymous,{' '}
          {unmatchedSite?.named_unidentified ?? 0} named but unidentified. These are counted
          separately and never merged.
        </p>
      )}
    </div>
  );
}

// ── Helpers ──────────────────────────────────────────────────────────────────

interface RegisterSummary {
  total: number;
  present: number;
  expected: number;
  expectedNotRecorded: number;
  walkIns: number;
  anonymous: number;
  namedUnidentified: number;
}

/**
 * The register's own summary, derived from the participants view.
 *
 * `expected` counts the planned roster; `present` counts the rows actually
 * recorded (which is what the register reports, not a judgement about absence).
 */
function summarise(participants: AttendanceParticipant[]): RegisterSummary {
  return {
    total: participants.length,
    present: participants.filter((row) => row.is_present).length,
    expected: participants.filter((row) => row.is_on_roster).length,
    expectedNotRecorded: participants.filter((row) => row.is_on_roster && !row.is_present).length,
    walkIns: participants.filter((row) => row.is_walk_in).length,
    anonymous: participants.filter((row) => row.is_anonymous).length,
    namedUnidentified: participants.filter((row) => !row.person_id && !row.is_anonymous).length,
  };
}

function Chip({ children, tone }: { children: React.ReactNode; tone: 'neutral' | 'amber' | 'emerald' }) {
  const tones = {
    neutral: 'bg-surface-2 text-slate-400 border-surface-border',
    amber: 'bg-amber-500/10 text-amber-300 border-amber-500/25',
    emerald: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/25',
  } as const;

  return (
    <span className={`text-[11px] rounded-md border px-1.5 py-0.5 ${tones[tone]}`}>{children}</span>
  );
}

const TYPE_LABELS: Record<string, string> = {
  meeting: 'Meeting',
  roll_call: 'Roll call',
  service: 'Service',
  training: 'Training',
  community_event: 'Community event',
};

function registerTypeLabel(type: string): string {
  return TYPE_LABELS[type] ?? type.replace('_', ' ');
}

function formatDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString();
}

function formatDateTime(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}
