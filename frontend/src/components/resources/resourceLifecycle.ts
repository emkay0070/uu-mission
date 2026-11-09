import type { Resource, ResourceDistributionRequest } from '@/lib/api';

/**
 * Contribution lifecycle helpers.
 *
 * The API exposes two complementary views of a contributor's work:
 *  - `/resources/my-drafts` returns private drafts;
 *  - `/resources/distribution-requests` returns the submission lifecycle.
 *
 * The UI joins them by resource id so a contributor sees one card per resource
 * with the newest submission state, rather than a draft that silently
 * disappears as soon as it is submitted for review.
 */

export type ContributionState =
  | 'draft'
  | 'pending_review'
  | 'changes_requested'
  | 'rejected'
  | 'approved'
  | 'revoked'
  | 'superseded';

export type RequestStatus = ResourceDistributionRequest['status'];

export interface StatusMeta {
  label: string;
  className: string;
  description: string;
}

export const CONTRIBUTION_STATE_META: Record<ContributionState, StatusMeta> = {
  draft: {
    label: 'Draft',
    className: 'bg-slate-500/20 text-slate-300 border border-slate-500/30',
    description: 'Private to you until you submit it for review.',
  },
  pending_review: {
    label: 'Pending review',
    className: 'bg-amber-500/20 text-amber-300 border border-amber-500/30',
    description: 'Waiting for an authorised reviewer to decide.',
  },
  changes_requested: {
    label: 'Changes requested',
    className: 'bg-orange-500/20 text-orange-300 border border-orange-500/30',
    description: 'A reviewer returned this resource for revision.',
  },
  rejected: {
    label: 'Rejected',
    className: 'bg-red-500/20 text-red-300 border border-red-500/30',
    description: 'The reviewer declined this distribution request.',
  },
  approved: {
    label: 'Approved & published',
    className: 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30',
    description: 'A reviewer approved this distribution request.',
  },
  revoked: {
    label: 'Distribution revoked',
    className: 'bg-slate-600/20 text-slate-400 border border-slate-600/30',
    description: 'An approved distribution to this audience was withdrawn.',
  },
  superseded: {
    label: 'Superseded',
    className: 'bg-purple-500/20 text-purple-300 border border-purple-500/30',
    description: 'A newer distribution request replaced this one.',
  },
};

export const REQUEST_STATUS_META: Record<RequestStatus, StatusMeta> = {
  pending_review: CONTRIBUTION_STATE_META.pending_review,
  returned: CONTRIBUTION_STATE_META.changes_requested,
  rejected: CONTRIBUTION_STATE_META.rejected,
  approved: CONTRIBUTION_STATE_META.approved,
  revoked: CONTRIBUTION_STATE_META.revoked,
  superseded: CONTRIBUTION_STATE_META.superseded,
};

export interface ContributionItem {
  resource: Resource;
  request?: ResourceDistributionRequest;
  state: ContributionState;
  latestDecision?: DecisionEntry;
}

export type DecisionEntry = NonNullable<ResourceDistributionRequest['decisions']>[number];

const REVIEW_ACTIONS = ['approved', 'rejected', 'returned', 'revoked', 'superseded'];

export function accessForState(state: ContributionState): {
  canEdit: boolean;
  canSubmit: boolean;
  canResubmit: boolean;
} {
  return {
    canEdit: state === 'draft' || state === 'changes_requested' || state === 'rejected',
    canSubmit: state === 'draft' || state === 'rejected' || state === 'revoked',
    canResubmit: state === 'changes_requested',
  };
}

export function stateForRequestStatus(status: RequestStatus): ContributionState {
  switch (status) {
    case 'pending_review':
      return 'pending_review';
    case 'returned':
      return 'changes_requested';
    case 'rejected':
      return 'rejected';
    case 'approved':
      return 'approved';
    case 'revoked':
      return 'revoked';
    case 'superseded':
      return 'superseded';
  }
}

/** Keep only requests the signed-in contributor created. */
export function selectOwnRequests(
  requests: ResourceDistributionRequest[],
  userId: number
): ResourceDistributionRequest[] {
  return requests.filter((request) => Number(request.requested_by_user_id) === Number(userId));
}

function requestTimestamp(request: ResourceDistributionRequest): number {
  const raw = request.submitted_at ?? request.created_at ?? request.updated_at;
  const value = raw ? new Date(raw).getTime() : 0;
  return Number.isFinite(value) ? value : 0;
}

function isNewerRequest(
  candidate: ResourceDistributionRequest,
  current: ResourceDistributionRequest
): boolean {
  const candidateTime = requestTimestamp(candidate);
  const currentTime = requestTimestamp(current);
  if (candidateTime !== currentTime) return candidateTime > currentTime;
  return Number(candidate.id) > Number(current.id);
}

export function latestDecision(
  request: ResourceDistributionRequest | undefined
): DecisionEntry | undefined {
  const decisions = request?.decisions ?? [];
  if (decisions.length === 0) return undefined;

  return [...decisions].sort((a, b) => {
    const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
    const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;
    if (aTime !== bTime) return aTime - bTime;
    return Number(a.id) - Number(b.id);
  })[decisions.length - 1];
}

export function latestReviewDecision(
  request: ResourceDistributionRequest | undefined
): DecisionEntry | undefined {
  const decisions = (request?.decisions ?? [])
    .filter((decision) => REVIEW_ACTIONS.includes(decision.action))
    .sort((a, b) => {
      const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
      const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;
      if (aTime !== bTime) return aTime - bTime;
      return Number(a.id) - Number(b.id);
    });

  return decisions[decisions.length - 1];
}

export function reviewerFeedback(item: ContributionItem): string | null {
  if (!item.request) return null;
  if (item.request.decision_reason) return item.request.decision_reason;
  return latestReviewDecision(item.request)?.reason ?? null;
}

export function buildContributionItems(
  drafts: Resource[],
  requests: ResourceDistributionRequest[],
  userId: number
): ContributionItem[] {
  const items = new Map<number, ContributionItem>();

  for (const draft of drafts) {
    items.set(draft.id, {
      resource: draft,
      state: draft.status === 'pending_review' ? 'pending_review' : 'draft',
    });
  }

  const latestByResource = new Map<number, ResourceDistributionRequest>();
  for (const request of selectOwnRequests(requests, userId)) {
    const current = latestByResource.get(request.resource_id);
    if (!current || isNewerRequest(request, current)) {
      latestByResource.set(request.resource_id, request);
    }
  }

  for (const [resourceId, request] of latestByResource) {
    const existing = items.get(resourceId);
    // A returned or rejected submission reverses the resource to `draft`, so
    // `/my-drafts` carries newer metadata and file-version data than the
    // request payload captured at submission time. Prefer the draft record.
    const resource = existing?.resource ?? request.resource;
    if (!resource) continue;

    items.set(resourceId, {
      resource,
      request,
      state: stateForRequestStatus(request.status),
      latestDecision: latestReviewDecision(request),
    });
  }

  return [...items.values()].sort((a, b) => {
    const aTime = a.request ? requestTimestamp(a.request) : new Date(a.resource.updated_at).getTime();
    const bTime = b.request ? requestTimestamp(b.request) : new Date(b.resource.updated_at).getTime();
    return bTime - aTime;
  });
}

export function contributionStateLabel(state: ContributionState): string {
  return CONTRIBUTION_STATE_META[state].label;
}
