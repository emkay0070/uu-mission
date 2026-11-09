'use client';

import { useEffect, useState } from 'react';
import { outcomesApi, refApi, type MissionOutcome, type OutcomeType } from '@/lib/api';
import { EmptyState, ErrorBanner, Skeleton } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import { CheckCircle, Heart, XCircle } from 'lucide-react';

const FOLLOW_UP_COLORS: Record<string, string> = {
  pending: 'text-amber-300',
  assigned: 'text-blue-300',
  completed: 'text-emerald-300',
  lost_contact: 'text-red-300',
  not_required: 'text-slate-500',
};

/** Follow-up states that still need someone to close the loop. */
const OPEN_FOLLOW_UP: ReadonlyArray<MissionOutcome['follow_up_status']> = ['pending', 'assigned'];

/**
 * /site-operations/outcomes — spiritual outcomes recorded at the active site.
 *
 * Reviews what was recorded and lets a person who holds
 * `outcomes.manage_followup` at this site close an outstanding follow-up. Every
 * count shown is derived from the returned records; nothing is estimated.
 */
export default function SiteOutcomesPage() {
  const { active, canIn } = useSiteOperations();

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  return (
    <OutcomesBoard
      key={active.site_id}
      siteId={active.site_id}
      campaignId={active.campaign_id}
      canManage={canIn('outcomes.manage_followup')}
    />
  );
}

function OutcomesBoard({
  siteId,
  campaignId,
  canManage,
}: {
  siteId: number;
  campaignId: number;
  canManage: boolean;
}) {
  const [outcomes, setOutcomes] = useState<MissionOutcome[]>([]);
  const [types, setTypes] = useState<OutcomeType[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  async function load() {
    try {
      setLoading(true);
      setError('');
      const [list, outcomeTypes] = await Promise.all([
        outcomesApi.list(campaignId, { site_id: siteId }),
        refApi.outcomeTypes(),
      ]);
      setOutcomes(list);
      setTypes(outcomeTypes);
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load outcomes.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [campaignId, siteId]);

  async function resolve(outcome: MissionOutcome, result: 'completed' | 'lost_contact') {
    setBusyId(outcome.id);
    setError('');
    try {
      await outcomesApi.completeFollowUp(campaignId, outcome.id, result);
      await load();
    } catch (e) {
      setError((e as { message?: string }).message ?? 'The follow-up could not be updated.');
    } finally {
      setBusyId(null);
    }
  }

  const awaiting = outcomes.filter((outcome) =>
    OPEN_FOLLOW_UP.includes(outcome.follow_up_status)
  ).length;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div>
        <h1 className="text-xl font-bold text-slate-100">Outcomes</h1>
        <p className="text-sm text-slate-400 mt-0.5">
          {outcomes.length} recorded · {awaiting} awaiting follow-up
        </p>
      </div>

      {error && <ErrorBanner message={error} />}

      {loading ? (
        <div className="space-y-2">
          {[...Array(6)].map((_, i) => (
            <Skeleton key={i} className="h-14 rounded-xl" />
          ))}
        </div>
      ) : outcomes.length === 0 ? (
        <EmptyState
          icon={<Heart className="w-6 h-6" />}
          title="No outcomes recorded"
          body="Spiritual outcomes recorded at this site will appear here."
        />
      ) : (
        <div className="space-y-2">
          {outcomes.map((outcome) => {
            const typeLabel =
              types.find((type) => type.code === outcome.outcome_type)?.label ??
              outcome.outcome_type.replace(/_/g, ' ');
            const isOpen = OPEN_FOLLOW_UP.includes(outcome.follow_up_status);
            return (
              <div key={outcome.id} className="card p-4 flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg bg-rose-500/10 border border-rose-500/20 flex items-center justify-center shrink-0">
                  <Heart className="w-4 h-4 text-rose-300" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100 truncate">{typeLabel}</p>
                  <p className="text-xs text-slate-500">
                    {outcome.visitor_name ?? 'Recorded person'} · {outcome.outcome_date}
                    {outcome.follow_up_due_date ? ` · due ${outcome.follow_up_due_date}` : ''}
                  </p>
                </div>
                <span
                  className={`text-xs font-medium shrink-0 ${
                    FOLLOW_UP_COLORS[outcome.follow_up_status] ?? 'text-slate-400'
                  }`}
                >
                  {outcome.follow_up_status.replace('_', ' ')}
                </span>
                {canManage && isOpen && (
                  <div className="flex gap-1.5 shrink-0">
                    <button
                      onClick={() => resolve(outcome, 'completed')}
                      disabled={busyId === outcome.id}
                      title="Mark follow-up completed"
                      className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-medium"
                    >
                      <CheckCircle className="w-3.5 h-3.5" /> Done
                    </button>
                    <button
                      onClick={() => resolve(outcome, 'lost_contact')}
                      disabled={busyId === outcome.id}
                      title="Mark as lost contact"
                      className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-surface-2 border border-surface-border hover:text-slate-100 disabled:opacity-50 text-slate-300 text-xs font-medium"
                    >
                      <XCircle className="w-3.5 h-3.5" /> Lost
                    </button>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
