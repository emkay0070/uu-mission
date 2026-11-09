'use client';

import { useEffect, useState } from 'react';
import { assignmentsApi, sitesApi, type Assignment, type MissionSite } from '@/lib/api';
import { EmptyState, ErrorBanner, Skeleton, StatusBadge } from '@/components/ui';
import { useCampaignOperations } from '@/components/campaign-management/CampaignOpsContext';
import { NoCampaignContext } from '@/components/CampaignManagementShell';
import { AlertCircle, CheckCircle2, MapPinned, UserCheck, Users } from 'lucide-react';

/** The statuses a deployment can hold, in the order they progress. */
const STATUS_LABELS: Record<Assignment['status'], string> = {
  confirmed: 'Confirmed',
  arrived: 'Arrived',
  completed: 'Completed',
  withdrew: 'Withdrew',
  no_show: 'No show',
};

/**
 * /campaign-management/deployments — campaign personnel and their deployment.
 *
 * Answers "are deployment responsibilities being fulfilled?": who is assigned,
 * where they are deployed, who has arrived, and which active sites still have
 * no personnel. Confirming an arrival is offered only to a person holding
 * `assignments.manage` on this campaign; the backend re-authorises the write.
 */
export default function CampaignDeploymentsPage() {
  const { active, canIn } = useCampaignOperations();

  if (!active || active.campaign_id == null) {
    return <NoCampaignContext />;
  }

  return (
    <DeploymentsBoard
      key={active.campaign_id}
      campaignId={active.campaign_id}
      canManage={canIn('assignments.manage')}
    />
  );
}

function DeploymentsBoard({ campaignId, canManage }: { campaignId: number; canManage: boolean }) {
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [sites, setSites] = useState<MissionSite[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  async function load() {
    try {
      setLoading(true);
      setError('');
      const [assignmentList, siteList] = await Promise.all([
        assignmentsApi.list(campaignId),
        sitesApi.list(campaignId),
      ]);
      setAssignments(assignmentList);
      setSites(siteList);
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load deployments.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [campaignId]);

  async function confirmArrival(assignment: Assignment) {
    setBusyId(assignment.id);
    setError('');
    try {
      await assignmentsApi.update(campaignId, assignment.id, {
        status: 'arrived',
        actual_arrival: new Date().toISOString().slice(0, 10),
      });
      await load();
    } catch (e) {
      setError((e as { message?: string }).message ?? 'The arrival could not be recorded.');
    } finally {
      setBusyId(null);
    }
  }

  const byStatus = (status: Assignment['status']) =>
    assignments.filter((assignment) => assignment.status === status).length;

  const totalActive = assignments.filter(
    (assignment) => assignment.status === 'confirmed' || assignment.status === 'arrived'
  ).length;

  // Deployed personnel per site, from the assignments themselves.
  const deployedSiteIds = new Set(
    assignments
      .filter((assignment) => assignment.mission_site_id != null)
      .map((assignment) => assignment.mission_site_id as number)
  );
  const activeSitesWithoutPersonnel = sites.filter(
    (site) => site.status === 'active' && !deployedSiteIds.has(site.id)
  );

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6 animate-fade-up">
      <div>
        <h1 className="text-xl font-bold text-slate-100">Deployments</h1>
        <p className="text-sm text-slate-400 mt-0.5">
          {assignments.length} {assignments.length === 1 ? 'deployment' : 'deployments'} ·{' '}
          {totalActive} currently serving
        </p>
      </div>

      {error && <ErrorBanner message={error} />}

      {loading ? (
        <div className="space-y-2">
          {[...Array(6)].map((_, i) => (
            <Skeleton key={i} className="h-16 rounded-xl" />
          ))}
        </div>
      ) : assignments.length === 0 ? (
        <EmptyState
          icon={<Users className="w-6 h-6" />}
          title="No deployments recorded"
          body="Missionaries assigned to this campaign will appear here with their deployment details."
        />
      ) : (
        <>
          {/* Deployment status summary — counts of recorded assignments only. */}
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-2">
            {(Object.keys(STATUS_LABELS) as Assignment['status'][]).map((status) => (
              <div key={status} className="card p-3">
                <p className="section-label">{STATUS_LABELS[status]}</p>
                <p className="text-xl font-bold text-slate-100 tabular-nums">
                  {byStatus(status)}
                </p>
              </div>
            ))}
          </div>

          {activeSitesWithoutPersonnel.length > 0 && (
            <div className="card p-4 border-l-4 border-amber-500/60 space-y-1.5">
              <p className="text-sm font-medium text-slate-200 flex items-center gap-2">
                <AlertCircle className="w-4 h-4 text-amber-300" /> Active sites with no personnel
              </p>
              <p className="text-sm text-slate-400">
                {activeSitesWithoutPersonnel.map((site) => site.name).join(', ')}
              </p>
            </div>
          )}

          <div className="space-y-2">
            {assignments.map((assignment) => (
              <div key={assignment.id} className="card p-4 flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center shrink-0">
                  <UserCheck className="w-4 h-4 text-amber-300" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100 truncate">
                    {assignment.person?.full_name ?? 'Unnamed person'}
                  </p>
                  <p className="text-xs text-slate-500 truncate flex items-center gap-1.5">
                    <MapPinned className="w-3 h-3" />
                    {assignment.site?.name ?? 'No site assigned'}
                    {assignment.expected_arrival ? ` · expected ${assignment.expected_arrival}` : ''}
                    {assignment.actual_arrival ? ` · arrived ${assignment.actual_arrival}` : ''}
                  </p>
                </div>
                <StatusBadge status={assignment.status} />
                {canManage && assignment.status === 'confirmed' && (
                  <button
                    onClick={() => confirmArrival(assignment)}
                    disabled={busyId === assignment.id}
                    title="Record that this person has arrived"
                    className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-medium shrink-0"
                  >
                    <CheckCircle2 className="w-3.5 h-3.5" /> Confirm arrival
                  </button>
                )}
              </div>
            ))}
          </div>
        </>
      )}

      <p className="text-xs text-slate-500">
        Deployments are recorded against sites on this campaign. Being deployed does not by itself
        grant anyone operational authority — roles are assigned separately.
      </p>
    </div>
  );
}
