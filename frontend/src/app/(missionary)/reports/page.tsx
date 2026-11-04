'use client';

import { useEffect, useMemo, useState, type ReactNode } from 'react';
import {
  ClipboardList, FileText, Loader2, Users, MapPin, Building2, AlertTriangle,
  Layers, History, FileCheck2,
} from 'lucide-react';
import {
  workspaceApi, reportsApi,
  type MyAssignment, type FieldReport, type ReportPreparation, type ReportReconciliation,
  type FieldReportEvent,
} from '@/lib/api';
import { hasPermission, hasAnyPermission } from '@/lib/permissions';
import { useAuthStore } from '@/store/auth';
import { EmptyState, ErrorBanner, Skeleton } from '@/components/ui';

/**
 * Reports — the dedicated reporting workspace.
 *
 * This is NOT a feed or a blog. It is the place a secretary or reviewer sees the
 * reports they are responsible for and the state each one is in.
 *
 * TWO ORTHOGONAL FACTS, NEVER MERGED IN THE UI
 * --------------------------------------------
 *   status        the document lifecycle: draft → submitted → reviewed → approved
 *   review_state  whose desk the report is on (team → site → campaign), or that
 *                 a change was requested, or that the chain is complete.
 *
 * A report can be forwarded past an outstanding review without being approved.
 * When that has happened (`has_pending_lower_review`) the workspace shows a
 * "… review pending" badge and NEVER labels the report approved.
 *
 * Every state name shown here is human language. The internal codes never reach
 * the screen.
 */

// ── Human wording for the state codes ─────────────────────────────────────────

/** Review-chain states, translated out of the workflow's internal vocabulary. */
const REVIEW_STATE_LABELS: Record<string, string> = {
  pending_team_review: 'Team review pending',
  pending_site_review: 'Site review pending',
  pending_campaign_review: 'Campaign review pending',
  changes_requested: 'Changes requested',
  complete: 'Review complete',
};

/** Document lifecycle states. */
const STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  submitted: 'Submitted',
  reviewed: 'Reviewed',
  approved: 'Approved',
};

/** The three rungs a report can be filed at. */
const LEVEL_LABELS: Record<string, string> = {
  team: 'Team',
  site: 'Site',
  campaign: 'Campaign',
};

/** Audit-trail event types, translated to plain sentences. */
const EVENT_LABELS: Record<FieldReportEvent['event_type'], string> = {
  submitted: 'Submitted for review',
  approved: 'Approved',
  returned: 'Returned for changes',
  forwarded: 'Forwarded past an outstanding review',
  escalated: 'Escalated',
  consolidated: 'Consolidated lower-level reports',
  recovered: 'Generated from available records',
};

type Tone = 'green' | 'amber' | 'blue' | 'slate';

/** Human label for a review state; falls back to nothing rather than the code. */
function reviewStateLabel(state: string | null): string {
  if (!state) return '';
  return REVIEW_STATE_LABELS[state] ?? 'In review';
}

/** The one badge shown for a report's overall position. */
function reportStatus(report: FieldReport): { label: string; tone: Tone } {
  // An outstanding lower-level review means the report is NOT approved, however
  // far up the chain it has travelled. This rule is deliberately first.
  if (report.has_pending_lower_review) {
    const level = report.outstanding_review_level
      ? LEVEL_LABELS[report.outstanding_review_level]
      : 'Lower';
    return { label: `${level} review pending`, tone: 'amber' };
  }

  switch (report.status) {
    case 'approved':
      return { label: STATUS_LABELS.approved, tone: 'green' };
    case 'reviewed':
      return { label: STATUS_LABELS.reviewed, tone: 'blue' };
    case 'submitted':
      return { label: STATUS_LABELS.submitted, tone: 'blue' };
    default:
      return { label: STATUS_LABELS.draft, tone: 'slate' };
  }
}

/** A short, human title for a report: its level and, where known, its owner. */
function reportTitle(report: FieldReport): string {
  if (report.report_level === 'team') {
    return report.team?.name ? `Team report — ${report.team.name}` : 'Team report';
  }
  if (report.report_level === 'site') {
    return report.site?.name ? `Site report — ${report.site.name}` : 'Site report';
  }
  return 'Campaign report';
}

/** The reporting period, shown as written dates. */
function reportPeriod(report: FieldReport): string {
  return `${report.period_start} → ${report.period_end}`;
}

// ── Small presentational pieces ───────────────────────────────────────────────

function Badge({ tone, children }: { tone: Tone; children: ReactNode }) {
  const tones: Record<Tone, string> = {
    green: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    amber: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    blue: 'bg-brand-500/15 text-brand-300 border-brand-500/30',
    slate: 'bg-slate-500/15 text-slate-300 border-slate-500/30',
  };
  return (
    <span className={`text-[11px] px-2 py-0.5 rounded-full border whitespace-nowrap ${tones[tone]}`}>
      {children}
    </span>
  );
}

/** One labelled number, used by the live preparation snapshot. */
function MiniStat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-xl bg-surface-3/60 border border-surface-border px-3 py-2">
      <p className="text-xs text-slate-500">{label}</p>
      <p className="text-lg font-semibold text-slate-100">{value}</p>
    </div>
  );
}

/** A titled block of reports. */
function Section({
  icon, title, description, count, children,
}: {
  icon: ReactNode;
  title: string;
  description?: string;
  count?: number;
  children: ReactNode;
}) {
  return (
    <section className="space-y-3">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-start gap-2">
          <span className="text-brand-400 mt-0.5">{icon}</span>
          <div>
            <h2 className="text-base font-semibold text-slate-100">{title}</h2>
            {description && <p className="text-xs text-slate-500 mt-0.5">{description}</p>}
          </div>
        </div>
        {typeof count === 'number' && (
          <span className="text-xs text-slate-500 shrink-0">{count}</span>
        )}
      </div>
      {children}
    </section>
  );
}

/** A single, clickable report row. */
function ReportRow({
  report, selected, overdue, onSelect,
}: {
  report: FieldReport;
  selected: boolean;
  overdue: boolean;
  onSelect: () => void;
}) {
  const status = reportStatus(report);
  return (
    <button
      onClick={onSelect}
      className={`w-full text-left card border p-4 transition-colors ${
        selected ? 'border-brand-500/60' : 'border-surface-border hover:border-surface-border/60'
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-medium text-slate-100 truncate">{reportTitle(report)}</p>
          <p className="text-xs text-slate-500 mt-0.5">{reportPeriod(report)}</p>
        </div>
        <div className="flex items-center gap-1.5 shrink-0">
          {overdue && <Badge tone="amber">Delayed</Badge>}
          <Badge tone={status.tone}>{status.label}</Badge>
        </div>
      </div>
      {/* The review-chain position, unless the badge above already says it. */}
      {report.review_state && !report.has_pending_lower_review && (
        <p className="text-xs text-slate-500 mt-2">{reviewStateLabel(report.review_state)}</p>
      )}
    </button>
  );
}

/** The list of reports in a section, with a calm empty state. */
function ReportList({
  reports, selectedId, delayedIds, onSelect,
}: {
  reports: FieldReport[];
  selectedId: number | null;
  delayedIds: Set<number>;
  onSelect: (report: FieldReport) => void;
}) {
  if (reports.length === 0) {
    return (
      <div className="card border border-surface-border p-4">
        <p className="text-sm text-slate-500">Nothing here right now.</p>
      </div>
    );
  }
  return (
    <div className="space-y-2">
      {reports.map((report) => (
        <ReportRow
          key={report.id}
          report={report}
          selected={selectedId === report.id}
          overdue={delayedIds.has(report.id)}
          onSelect={() => onSelect(report)}
        />
      ))}
    </div>
  );
}

// ── Live preparation (pure read) ──────────────────────────────────────────────

/**
 * The live compilation of what a report currently covers, as it stands.
 *
 * This is a calm read, safe to refresh: while it loads we simply say
 * "Compiling…". We show only human-meaningful figures — never the internal
 * format version, fingerprint or source-id lists.
 */
function LivePreparation({ campaignId, reportId }: { campaignId: number; reportId: number }) {
  const [data, setData] = useState<{
    preparation: ReportPreparation;
    reconciliation: ReportReconciliation;
  } | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    setData(null);
    reportsApi
      .preparation(campaignId, reportId)
      .then((res) => {
        if (active) setData(res);
      })
      .catch((err: unknown) => {
        if (active) {
          setError((err as { message?: string })?.message || 'Could not read the records for this report.');
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [campaignId, reportId]);

  if (loading) {
    return (
      <div className="flex items-center gap-2 text-sm text-slate-400 py-2">
        <Loader2 className="w-4 h-4 animate-spin" />
        Compiling this report from the records so far…
      </div>
    );
  }

  if (error) {
    return <p className="text-xs text-slate-500 py-1">{error}</p>;
  }

  if (!data) return null;

  const { preparation, reconciliation } = data;

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500">
        Covered period: {preparation.period.start} → {preparation.period.end}
      </p>
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <MiniStat label="Activities" value={preparation.activities.count} />
        {/* Site and activity attendance stay separate here too. */}
        <MiniStat label="Site attendance" value={preparation.attendance.site_attendance.entries} />
        <MiniStat label="Activity attendance" value={preparation.attendance.activity_attendance.entries} />
        <MiniStat label="Outcomes" value={preparation.outcomes.total} />
      </div>
      <p className="text-xs text-slate-500">{preparation.attendance.note}</p>
      {/* Changes against a preserved submitted version, in plain words. */}
      {reconciliation.has_preserved_version && reconciliation.changed && reconciliation.message && (
        <p className="text-xs text-amber-300">{reconciliation.message}</p>
      )}
    </div>
  );
}

// ── Audit history (append-only) ───────────────────────────────────────────────

/** The report's history, loaded on demand so the workspace opens quietly. */
function AuditHistory({ campaignId, reportId }: { campaignId: number; reportId: number }) {
  const [open, setOpen] = useState(false);
  const [events, setEvents] = useState<FieldReportEvent[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const toggle = async () => {
    const next = !open;
    setOpen(next);
    if (next && events === null) {
      setLoading(true);
      setError(null);
      try {
        setEvents(await reportsApi.events(campaignId, reportId));
      } catch (err: unknown) {
        setError((err as { message?: string })?.message || 'Could not read the report history.');
      } finally {
        setLoading(false);
      }
    }
  };

  return (
    <div className="border-t border-surface-border pt-3">
      <button
        onClick={toggle}
        className="flex items-center gap-2 text-xs text-slate-400 hover:text-slate-200 transition-colors"
      >
        <History className="w-3.5 h-3.5" />
        {open ? 'Hide history' : 'Show history'}
      </button>

      {open && (
        <div className="mt-3 space-y-2">
          {loading && (
            <p className="text-xs text-slate-500 flex items-center gap-2">
              <Loader2 className="w-3.5 h-3.5 animate-spin" /> Reading history…
            </p>
          )}
          {error && <p className="text-xs text-slate-500">{error}</p>}
          {!loading && !error && events && events.length === 0 && (
            <p className="text-xs text-slate-500">This report has no history yet.</p>
          )}
          {!loading && !error && events?.map((event) => (
            <div key={event.id} className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-xs text-slate-200">
                  {EVENT_LABELS[event.event_type] ?? 'Updated'}
                </p>
                {event.notes && (
                  <p className="text-xs text-slate-500 truncate">{event.notes}</p>
                )}
              </div>
              <span className="text-[11px] text-slate-500 shrink-0">
                {new Date(event.created_at).toLocaleDateString()}
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ── Selected report detail ────────────────────────────────────────────────────

/**
 * The detail panel for one report. Offers the live preparation read and, for a
 * draft the user may author, the "Generate from records" action.
 *
 * When a lower-level submission is still outstanding, generation is done through
 * recovery (build from what exists) rather than a plain re-prepare — the honest
 * route, and one that never pretends the missing submission arrived.
 */
function ReportDetail({
  campaignId, report, canSubmit, canPreview, onClose, onChanged,
}: {
  campaignId: number;
  report: FieldReport;
  canSubmit: boolean;
  canPreview: boolean;
  onClose: () => void;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  // Bumping this key re-mounts the live preparation reader for a refresh.
  const [previewKey, setPreviewKey] = useState(0);

  const isDraft = report.status === 'draft';
  // An outstanding lower review means a lower submission is still missing, so
  // the report is generated from the records that exist (recovery).
  const useRecovery = report.has_pending_lower_review;
  const status = reportStatus(report);

  const generate = async () => {
    setBusy(true);
    setMessage(null);
    setActionError(null);
    try {
      if (useRecovery) {
        const res = await reportsApi.recover(campaignId, report.id);
        setMessage(res.message);
      } else {
        const res = await reportsApi.prepare(campaignId, report.id);
        setMessage(res.message);
      }
      setPreviewKey((k) => k + 1);
      onChanged();
    } catch (err: unknown) {
      setActionError(
        (err as { message?: string })?.message || 'Could not generate the report from records.'
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="card border border-brand-500/40 p-4 space-y-4">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-start gap-2 min-w-0">
          <FileText className="w-4 h-4 text-brand-400 mt-0.5 shrink-0" />
          <div className="min-w-0">
            <h2 className="text-sm font-semibold text-slate-100 truncate">{reportTitle(report)}</h2>
            <p className="text-xs text-slate-500 mt-0.5">{reportPeriod(report)}</p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <Badge tone={status.tone}>{status.label}</Badge>
          <button onClick={onClose} className="text-xs text-slate-400 hover:text-slate-200">
            Close
          </button>
        </div>
      </div>

      {/* The review-chain position, spelled out when a lower review is open. */}
      {report.review_state && (
        <p className="text-xs text-slate-400">
          {reviewStateLabel(report.review_state)}
          {report.has_pending_lower_review && report.outstanding_review_level
            ? ` — ${LEVEL_LABELS[report.outstanding_review_level]} review still outstanding`
            : ''}
        </p>
      )}

      {/* Live preparation — a calm read of what the report currently covers. */}
      {canPreview && (
        <div className="rounded-xl border border-surface-border bg-surface-2/60 p-3">
          <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2">
              <Layers className="w-4 h-4 text-brand-400" />
              <p className="text-sm font-medium text-slate-100">What this report covers</p>
            </div>
            <button
              onClick={() => setPreviewKey((k) => k + 1)}
              className="text-xs text-brand-300 hover:text-brand-200"
            >
              Preview
            </button>
          </div>
          <LivePreparation key={previewKey} campaignId={campaignId} reportId={report.id} />
        </div>
      )}

      {/* Generate from records — drafts only, and only with the authoring right. */}
      {isDraft && canSubmit && (
        <div className="flex flex-wrap items-center gap-3">
          <button
            onClick={generate}
            disabled={busy}
            className="px-3 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-sm font-medium transition-colors flex items-center gap-2 disabled:opacity-50"
          >
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <FileCheck2 className="w-4 h-4" />}
            Generate from records
          </button>
          <p className="text-xs text-slate-500">
            Builds the draft from the fieldwork already recorded. This is not a submission.
          </p>
        </div>
      )}

      {message && <p className="text-xs text-emerald-300">{message}</p>}
      {actionError && <p className="text-xs text-red-400">{actionError}</p>}

      <AuditHistory campaignId={campaignId} reportId={report.id} />
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ReportsPage() {
  const { user } = useAuthStore();

  const [assignment, setAssignment] = useState<MyAssignment | null>(null);
  const [reports, setReports] = useState<FieldReport[]>([]);
  const [pendingReviews, setPendingReviews] = useState<FieldReport[]>([]);
  const [delayed, setDelayed] = useState<FieldReport[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<FieldReport | null>(null);

  // Reading a report (and its live preparation) needs official visibility or
  // the narrow team visibility. Authoring a draft needs the submit capability.
  const canPreview = hasAnyPermission(user, ['report.view', 'report.view_team']);
  const canSubmit = hasPermission(user, 'report.submit');

  useEffect(() => {
    loadAssignment();
  }, []);

  useEffect(() => {
    if (assignment?.campaign?.id) loadReports(assignment.campaign.id);
  }, [assignment]);

  const loadAssignment = async () => {
    try {
      const response = await workspaceApi.myAssignment();
      setAssignment(response.assignment);
    } catch (err) {
      console.error('Failed to load assignment:', err);
    }
  };

  const loadReports = async (campaignId: number) => {
    setLoading(true);
    setError(null);
    try {
      // Three reads: everything visible, what awaits a reviewer, and what is
      // past its review window. The two flags are server-authoritative, so the
      // client never guesses at "pending" or "overdue".
      const [all, awaiting, late] = await Promise.all([
        reportsApi.list(campaignId),
        reportsApi.list(campaignId, { pending_review: true }),
        reportsApi.list(campaignId, { overdue: true }),
      ]);
      setReports(all);
      setPendingReviews(awaiting);
      setDelayed(late);
    } catch (err: unknown) {
      setError((err as { message?: string })?.message || 'Failed to load reports');
    } finally {
      setLoading(false);
    }
  };

  const refresh = () => {
    if (assignment?.campaign?.id) loadReports(assignment.campaign.id);
  };

  // The overdue ids drive the "Delayed" badge anywhere a report appears.
  const delayedIds = useMemo(() => new Set(delayed.map((r) => r.id)), [delayed]);

  // ── Sections, derived from the visible set ──────────────────────────────────
  // Reports still being prepared — the ones a secretary can still shape.
  const drafting = useMemo(() => reports.filter((r) => r.status === 'draft'), [reports]);

  // What expects this user's action: reports they filed, or ones returned to
  // them for changes.
  const responsibilities = useMemo(
    () =>
      reports.filter(
        (r) =>
          (user?.id != null && r.submitted_by_user_id === user.id) ||
          r.review_state === 'changes_requested'
      ),
    [reports, user]
  );

  const teamReports = useMemo(() => reports.filter((r) => r.report_level === 'team'), [reports]);
  const siteReports = useMemo(() => reports.filter((r) => r.report_level === 'site'), [reports]);
  const campaignReports = useMemo(
    () => reports.filter((r) => r.report_level === 'campaign'),
    [reports]
  );

  const select = (report: FieldReport) => setSelected(report);

  return (
    <div className="p-4 md:p-6 max-w-5xl mx-auto space-y-8">
      {/* Header */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Reports</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {assignment?.campaign?.name
              ? `Reporting for ${assignment.campaign.name}.`
              : 'Your reporting workspace.'}
          </p>
        </div>
        <button
          onClick={refresh}
          disabled={loading}
          className="px-4 py-2 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-xl text-sm font-medium transition-colors flex items-center gap-2 disabled:opacity-50"
        >
          {loading && <Loader2 className="w-4 h-4 animate-spin" />}
          Refresh
        </button>
      </div>

      {error && <ErrorBanner message={error} />}

      {/* A report is open: show its detail above the sections. */}
      {selected && assignment?.campaign?.id && (
        <ReportDetail
          key={selected.id}
          campaignId={assignment.campaign.id}
          report={selected}
          canSubmit={canSubmit}
          canPreview={canPreview}
          onClose={() => setSelected(null)}
          onChanged={refresh}
        />
      )}

      {loading && reports.length === 0 ? (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </div>
      ) : !assignment?.campaign?.id ? (
        <EmptyState
          icon={<ClipboardList className="w-6 h-6" />}
          title="No reporting context"
          body="Reports appear once you are deployed to a campaign."
        />
      ) : reports.length === 0 ? (
        <EmptyState
          icon={<ClipboardList className="w-6 h-6" />}
          title="Nothing to report on yet"
          body="Reports you can file or review will appear here."
        />
      ) : (
        <>
          <Section
            icon={<ClipboardList className="w-4 h-4" />}
            title="My reporting workspace"
            description="Reports still being prepared — the ones you can shape and submit."
            count={drafting.length}
          >
            <ReportList
              reports={drafting}
              selectedId={selected?.id ?? null}
              delayedIds={delayedIds}
              onSelect={select}
            />
          </Section>

          <Section
            icon={<FileCheck2 className="w-4 h-4" />}
            title="My responsibilities"
            description="Reports you filed, and any returned to you for changes."
            count={responsibilities.length}
          >
            <ReportList
              reports={responsibilities}
              selectedId={selected?.id ?? null}
              delayedIds={delayedIds}
              onSelect={select}
            />
          </Section>

          <Section
            icon={<Users className="w-4 h-4" />}
            title="Team reporting"
            description="Reports filed by teams. A forward is not an approval."
            count={teamReports.length}
          >
            <ReportList
              reports={teamReports}
              selectedId={selected?.id ?? null}
              delayedIds={delayedIds}
              onSelect={select}
            />
          </Section>

          <Section
            icon={<AlertTriangle className="w-4 h-4" />}
            title="Pending reviews"
            description="Reports waiting on a reviewer, including any past their review window."
            count={pendingReviews.length}
          >
            <ReportList
              reports={pendingReviews}
              selectedId={selected?.id ?? null}
              delayedIds={delayedIds}
              onSelect={select}
            />
          </Section>

          <Section
            icon={<MapPin className="w-4 h-4" />}
            title="Site reporting"
            description="Each site's consolidated report."
            count={siteReports.length}
          >
            <ReportList
              reports={siteReports}
              selectedId={selected?.id ?? null}
              delayedIds={delayedIds}
              onSelect={select}
            />
          </Section>

          <Section
            icon={<Building2 className="w-4 h-4" />}
            title="Campaign reporting"
            description="Campaign leadership's consolidated report."
            count={campaignReports.length}
          >
            <ReportList
              reports={campaignReports}
              selectedId={selected?.id ?? null}
              delayedIds={delayedIds}
              onSelect={select}
            />
          </Section>

          <Section
            icon={<AlertTriangle className="w-4 h-4" />}
            title="Delayed submissions"
            description="Reports whose review window has passed and which level is still expected."
            count={delayed.length}
          >
            {delayed.length === 0 ? (
              <div className="card border border-surface-border p-4">
                <p className="text-sm text-slate-500">Nothing here right now.</p>
              </div>
            ) : (
              <div className="space-y-2">
                {delayed.map((report) => (
                  <button
                    key={report.id}
                    onClick={() => select(report)}
                    className={`w-full text-left card border p-4 transition-colors ${
                      selected?.id === report.id
                        ? 'border-brand-500/60'
                        : 'border-surface-border hover:border-surface-border/60'
                    }`}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="text-sm font-medium text-slate-100 truncate">
                          {reportTitle(report)}
                        </p>
                        <p className="text-xs text-slate-500 mt-0.5">{reportPeriod(report)}</p>
                        {/* Which level the review is still expected from. */}
                        {report.outstanding_review_level && (
                          <p className="text-xs text-amber-300 mt-1">
                            {LEVEL_LABELS[report.outstanding_review_level]} review outstanding
                          </p>
                        )}
                      </div>
                      <Badge tone="amber">Delayed</Badge>
                    </div>
                  </button>
                ))}
              </div>
            )}
          </Section>
        </>
      )}
    </div>
  );
}
