'use client';

import { useEffect, useState } from 'react';
import { resourcesApi, type ResourceDistributionRequest } from '@/lib/api';
import { EmptyState, ErrorBanner, Skeleton, StatusBadge } from '@/components/ui';
import { useCampaignOperations } from '@/components/campaign-management/CampaignOpsContext';
import { NoCampaignContext } from '@/components/CampaignManagementShell';
import { AlertCircle, CheckCircle, Package, RotateCcw, XCircle } from 'lucide-react';

/**
 * /campaign-management/resources — resource distribution addressed to the campaign.
 *
 * HONEST SCOPE NOTE
 * -----------------
 * Resource distribution in this platform is keyed by AUDIENCE (union, conference,
 * campaign, site, …), and the distribution API is deliberately NOT campaign-scoped:
 * `GET /resources/distribution-requests` returns the requests a person may see
 * (their own, plus those they can review). This page therefore filters that set
 * down to the requests whose audience target is THIS campaign. It does not
 * invent a campaign-scoped endpoint, and it does not present the resource
 * library itself as a campaign function — authoring and submitting a
 * distribution remain library operations.
 */
export default function CampaignResourcesPage() {
  const { active, canIn } = useCampaignOperations();

  if (!active || active.campaign_id == null) {
    return <NoCampaignContext />;
  }

  return (
    <ResourcesBoard
      key={active.campaign_id}
      campaignId={active.campaign_id}
      canDecide={canIn('resources.approve_distribute')}
    />
  );
}

function ResourcesBoard({ campaignId, canDecide }: { campaignId: number; canDecide: boolean }) {
  const [requests, setRequests] = useState<ResourceDistributionRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  async function load() {
    try {
      setLoading(true);
      setError('');
      const response = await resourcesApi.distributionRequests({ per_page: 100 });
      // Keep only the requests actually addressed to this campaign.
      setRequests(response.data.filter((request) => request.target_campaign_id === campaignId));
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load distribution requests.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [campaignId]);

  async function decide(
    request: ResourceDistributionRequest,
    action: 'approved' | 'rejected' | 'returned'
  ) {
    setBusyId(request.id);
    setError('');
    try {
      await resourcesApi.decideDistribution(request.id, { action });
      await load();
    } catch (e) {
      setError((e as { message?: string }).message ?? 'The decision could not be recorded.');
    } finally {
      setBusyId(null);
    }
  }

  const pending = requests.filter((request) => request.status === 'pending_review').length;
  const approved = requests.filter((request) => request.status === 'approved').length;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div>
        <h1 className="text-xl font-bold text-slate-100">Resources</h1>
        <p className="text-sm text-slate-400 mt-0.5">
          {requests.length} distribution {requests.length === 1 ? 'request' : 'requests'} addressed
          to this campaign · {pending} awaiting decision · {approved} approved
        </p>
      </div>

      {error && <ErrorBanner message={error} />}

      {/* State the limitation plainly rather than implying campaign-scoped API. */}
      <div className="card p-4 border-l-4 border-amber-500/60 space-y-1.5">
        <p className="text-sm font-medium text-slate-200 flex items-center gap-2">
          <AlertCircle className="w-4 h-4 text-amber-300" /> What this view can and cannot show
        </p>
        <ul className="text-sm text-slate-400 space-y-1 list-disc pl-5">
          <li>
            Distribution is audience-keyed and the API is library-wide, not campaign-scoped. The
            list below is the distribution requests <em>you</em> may see, filtered to those
            addressed to this campaign.
          </li>
          <li>
            Authoring and submitting a distribution is performed from the resource library, where
            the campaign can be chosen as the audience — it is not duplicated here.
          </li>
        </ul>
      </div>

      {loading ? (
        <div className="space-y-2">
          {[...Array(4)].map((_, i) => (
            <Skeleton key={i} className="h-16 rounded-xl" />
          ))}
        </div>
      ) : requests.length === 0 ? (
        <EmptyState
          icon={<Package className="w-6 h-6" />}
          title="No resource distributions for this campaign"
          body="When resources are distributed with this campaign as the audience, the requests appear here."
        />
      ) : (
        <div className="space-y-2">
          {requests.map((request) => {
            const decid = canDecide && request.status === 'pending_review';
            return (
              <div key={request.id} className="card p-4 flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center shrink-0">
                  <Package className="w-4 h-4 text-amber-300" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100 truncate">
                    {request.resource?.title ?? 'Resource'}
                  </p>
                  <p className="text-xs text-slate-500 truncate">
                    Audience: {request.audience_type} · submitted{' '}
                    {new Date(request.submitted_at).toLocaleDateString()}
                    {request.decision_reason ? ` · ${request.decision_reason}` : ''}
                  </p>
                </div>
                <StatusBadge status={request.status} />
                {decid && (
                  <div className="flex gap-1.5 shrink-0">
                    <button
                      onClick={() => decide(request, 'approved')}
                      disabled={busyId === request.id}
                      title="Approve distribution"
                      className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-medium"
                    >
                      <CheckCircle className="w-3.5 h-3.5" /> Approve
                    </button>
                    <button
                      onClick={() => decide(request, 'returned')}
                      disabled={busyId === request.id}
                      title="Return for changes"
                      className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-surface-2 border border-surface-border hover:text-slate-100 disabled:opacity-50 text-slate-300 text-xs font-medium"
                    >
                      <RotateCcw className="w-3.5 h-3.5" /> Return
                    </button>
                    <button
                      onClick={() => decide(request, 'rejected')}
                      disabled={busyId === request.id}
                      title="Reject distribution"
                      className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-surface-2 border border-surface-border hover:text-red-300 disabled:opacity-50 text-slate-300 text-xs font-medium"
                    >
                      <XCircle className="w-3.5 h-3.5" /> Reject
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
