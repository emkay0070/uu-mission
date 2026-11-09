'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import {
  AlertCircle,
  ArrowLeft,
  CalendarDays,
  ClipboardCheck,
  FileText,
  Loader2,
  RefreshCw,
  Search,
  ShieldAlert,
  ShieldCheck,
  User,
  X,
} from 'lucide-react';
import type { DistributionTargets, ResourceDistributionRequest } from '@/lib/api';
import { resourcesApi } from '@/lib/api';
import { useAuthStore } from '@/store/auth';
import { hasPermission } from '@/lib/permissions';
import { cn } from '@/components/ui';
import { fetchAllDistributionRequests } from '@/components/resources/distributionData';
import { audienceTypeLabel, formatAudience } from '@/components/resources/audience';
import { RequestStatusBadge } from '@/components/resources/ResourceStatusBadge';
import { ReviewRequestDetail } from '@/components/resources/ReviewRequestDetail';

type QueueTab = 'pending' | 'decided';

function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function requesterName(request: ResourceDistributionRequest): string {
  if (!request.requester) return `User #${request.requested_by_user_id}`;
  return (
    [request.requester.first_name, request.requester.last_name].filter(Boolean).join(' ') ||
    `User #${request.requested_by_user_id}`
  );
}

export default function DistributionReviewsPage() {
  const { user, initialized } = useAuthStore();
  const canReview = hasPermission(user, 'resources.approve_distribute');

  const [requests, setRequests] = useState<ResourceDistributionRequest[]>([]);
  const [targets, setTargets] = useState<DistributionTargets | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<QueueTab>('pending');
  const [search, setSearch] = useState('');
  const [audienceFilter, setAudienceFilter] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [mobileDetailOpen, setMobileDetailOpen] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [requestData, targetData] = await Promise.all([
        fetchAllDistributionRequests(),
        resourcesApi.distributionTargets().catch(() => null),
      ]);
      setRequests(requestData);
      setTargets(targetData);
      setSelectedId((current) =>
        current && requestData.some((request) => request.id === current) ? current : null
      );
    } catch (err: any) {
      setError(err?.message || 'Unable to load distribution requests.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (initialized && canReview) {
      load();
    }
  }, [initialized, canReview, load]);

  useEffect(() => {
    setSelectedId(null);
    setMobileDetailOpen(false);
  }, [tab]);

  const reviewable = useMemo(
    () =>
      requests.filter(
        (request) => Number(request.requested_by_user_id) !== Number(user?.id)
      ),
    [requests, user?.id]
  );

  const pending = useMemo(
    () => reviewable.filter((request) => request.status === 'pending_review'),
    [reviewable]
  );
  const decided = useMemo(
    () => reviewable.filter((request) => request.status !== 'pending_review'),
    [reviewable]
  );

  const activeRequests = tab === 'pending' ? pending : decided;

  const audienceTypes = useMemo(
    () => [...new Set(activeRequests.map((request) => request.audience_type))].sort(),
    [activeRequests]
  );

  const filtered = useMemo(() => {
    const needle = search.trim().toLowerCase();

    return activeRequests.filter((request) => {
      if (audienceFilter && request.audience_type !== audienceFilter) return false;
      if (!needle) return true;

      const haystack = [
        request.resource?.title,
        request.resource?.description,
        requesterName(request),
        formatAudience(request, targets),
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

      return haystack.includes(needle);
    });
  }, [activeRequests, audienceFilter, search, targets]);

  const selected = reviewable.find((request) => request.id === selectedId) ?? null;

  const handleDecided = (updated: ResourceDistributionRequest) => {
    setRequests((current) =>
      current.map((request) => (request.id === updated.id ? updated : request))
    );
    setSelectedId(updated.id);
  };

  if (!initialized) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface">
        <Loader2 className="h-8 w-8 animate-spin text-brand-400" />
      </div>
    );
  }

  if (!canReview) {
    return (
      <div className="min-h-screen bg-surface p-4 sm:p-6">
        <div className="mx-auto max-w-xl rounded-2xl border border-surface-border bg-surface-2 p-8 text-center">
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-red-500/10 text-red-400">
            <ShieldAlert className="h-7 w-7" />
          </div>
          <h1 className="text-2xl font-bold text-slate-100">Reviewer access required</h1>
          <p className="mt-2 text-sm text-slate-400">
            Distribution review is limited to users with approval authority for the requested
            audience. Your account does not currently hold the{' '}
            <code className="rounded bg-surface-3 px-1.5 py-0.5 text-xs text-slate-300">
              resources.approve_distribute
            </code>{' '}
            capability.
          </p>
          <Link
            href="/resources"
            className="mt-6 inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600"
          >
            <ArrowLeft className="h-4 w-4" />
            Back to Resources
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-surface p-4 sm:p-6">
      <div className="mx-auto max-w-7xl">
        <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <Link
              href="/resources"
              className="mb-3 inline-flex items-center gap-1.5 text-sm text-slate-400 transition-colors hover:text-brand-300"
            >
              <ArrowLeft className="h-4 w-4" />
              Resources
            </Link>
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="text-2xl font-bold text-slate-100 sm:text-3xl">Distribution Reviews</h1>
              <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-medium text-emerald-300">
                <ShieldCheck className="h-3.5 w-3.5" />
                Authorised reviewer
              </span>
            </div>
            <p className="mt-2 max-w-2xl text-sm text-slate-400">
              Inspect distribution requests and decide whether a resource may be released to its
              proposed audience. The queue only contains requests within your approval scope.
            </p>
          </div>
          <button
            type="button"
            onClick={load}
            disabled={loading}
            className="inline-flex items-center justify-center gap-2 self-start rounded-lg border border-surface-border bg-surface-2 px-4 py-2 text-sm text-slate-300 transition-colors hover:border-brand-500 hover:text-brand-300 disabled:opacity-50"
          >
            <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} />
            Refresh queue
          </button>
        </div>

        <div className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div className="rounded-xl border border-amber-500/25 bg-amber-500/5 p-4">
            <p className="text-xs font-semibold uppercase tracking-wider text-amber-300/80">Pending</p>
            <p className="mt-1 text-2xl font-bold text-amber-200">{pending.length}</p>
          </div>
          <div className="rounded-xl border border-orange-500/25 bg-orange-500/5 p-4">
            <p className="text-xs font-semibold uppercase tracking-wider text-orange-300/80">
              Returned
            </p>
            <p className="mt-1 text-2xl font-bold text-orange-200">
              {requests.filter((request) => request.status === 'returned').length}
            </p>
          </div>
          <div className="rounded-xl border border-emerald-500/25 bg-emerald-500/5 p-4">
            <p className="text-xs font-semibold uppercase tracking-wider text-emerald-300/80">
              Approved
            </p>
            <p className="mt-1 text-2xl font-bold text-emerald-200">
              {requests.filter((request) => request.status === 'approved').length}
            </p>
          </div>
        </div>

        {error && (
          <div className="mb-5 flex items-start justify-between gap-3 rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-300">
            <div className="flex items-start gap-2">
              <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              <span>{error}</span>
            </div>
            <button type="button" onClick={load} className="text-xs font-medium underline">
              Try again
            </button>
          </div>
        )}

        <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex w-full gap-1 rounded-xl border border-surface-border bg-surface-2 p-1 sm:w-auto">
            <button
              type="button"
              onClick={() => setTab('pending')}
              className={cn(
                'flex-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors sm:flex-none',
                tab === 'pending'
                  ? 'bg-brand-500 text-white'
                  : 'text-slate-400 hover:bg-surface-3 hover:text-slate-200'
              )}
            >
              Pending review
            </button>
            <button
              type="button"
              onClick={() => setTab('decided')}
              className={cn(
                'flex-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors sm:flex-none',
                tab === 'decided'
                  ? 'bg-brand-500 text-white'
                  : 'text-slate-400 hover:bg-surface-3 hover:text-slate-200'
              )}
            >
              Decided
            </button>
          </div>

          <div className="flex flex-col gap-2 sm:flex-row">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search requests…"
                className="w-full rounded-lg border border-surface-border bg-surface-2 py-2 pl-9 pr-3 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline-none sm:w-64"
              />
            </div>
            <select
              value={audienceFilter}
              onChange={(event) => setAudienceFilter(event.target.value)}
              className="rounded-lg border border-surface-border bg-surface-2 px-3 py-2 text-sm text-slate-200 focus:border-brand-500 focus:outline-none"
            >
              <option value="">All audiences</option>
              {audienceTypes.map((type) => (
                <option key={type} value={type}>
                  {audienceTypeLabel(type)}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">
          <div className="space-y-3">
            {loading ? (
              <div className="space-y-3">
                {[0, 1, 2].map((index) => (
                  <div
                    key={index}
                    className="h-32 animate-pulse rounded-xl border border-surface-border bg-surface-2"
                  />
                ))}
              </div>
            ) : filtered.length === 0 ? (
              <div className="rounded-2xl border border-surface-border bg-surface-2 p-8 text-center">
                <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-surface-3 text-slate-500">
                  <ClipboardCheck className="h-6 w-6" />
                </div>
                <p className="font-semibold text-slate-300">
                  {tab === 'pending' ? 'No requests awaiting review' : 'No decided requests yet'}
                </p>
                <p className="mt-1 text-sm text-slate-500">
                  {search || audienceFilter
                    ? 'Try clearing the search or audience filter.'
                    : tab === 'pending'
                    ? 'New distribution submissions within your scope will appear here.'
                    : 'Decisions you record will be listed here.'}
                </p>
              </div>
            ) : (
              filtered.map((request) => {
                const isSelected = request.id === selectedId;
                const isOwn = Number(request.requested_by_user_id) === Number(user?.id);
                return (
                  <button
                    key={request.id}
                    type="button"
                    onClick={() => {
                      setSelectedId(request.id);
                      setMobileDetailOpen(true);
                    }}
                    className={cn(
                      'w-full rounded-xl border bg-surface-2 p-4 text-left transition-all hover:border-brand-500/60',
                      isSelected ? 'border-brand-500 ring-1 ring-brand-500/30' : 'border-surface-border'
                    )}
                  >
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                      <RequestStatusBadge status={request.status} />
                      <span className="flex items-center gap-1 text-xs text-slate-500">
                        <CalendarDays className="h-3 w-3" />
                        {formatDate(request.submitted_at)}
                      </span>
                    </div>
                    <h3 className="line-clamp-2 font-semibold text-slate-100">
                      {request.resource?.title ?? `Resource #${request.resource_id}`}
                    </h3>
                    <p className="mt-1 flex items-center gap-1 text-xs text-slate-400">
                      <User className="h-3 w-3" />
                      {requesterName(request)}
                      {isOwn && (
                        <span className="ml-1 rounded bg-brand-500/15 px-1.5 py-0.5 text-[10px] text-brand-300">
                          Your submission
                        </span>
                      )}
                    </p>
                    <p className="mt-1 truncate text-xs text-slate-500">
                      {formatAudience(request, targets)}
                    </p>
                    {request.version && (
                      <p className="mt-1 flex items-center gap-1 truncate text-xs text-slate-500">
                        <FileText className="h-3 w-3 flex-shrink-0" />
                        {request.version.original_filename}
                      </p>
                    )}
                  </button>
                );
              })
            )}
          </div>

          <div className="hidden lg:block">
            {selected ? (
              <div className="sticky top-6 max-h-[calc(100vh-3rem)] overflow-y-auto rounded-2xl border border-surface-border bg-surface-2 p-5">
                <ReviewRequestDetail
                  request={selected}
                  targets={targets}
                  onDecided={handleDecided}
                />
              </div>
            ) : (
              <div className="flex min-h-[420px] flex-col items-center justify-center rounded-2xl border border-dashed border-surface-border bg-surface-2 p-8 text-center">
                <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-500/10 text-brand-400">
                  <ClipboardCheck className="h-7 w-7" />
                </div>
                <p className="font-semibold text-slate-300">Select a request to review</p>
                <p className="mt-1 max-w-sm text-sm text-slate-500">
                  Inspect the resource, check its proposed audience, then approve, return, or reject
                  the request with feedback.
                </p>
              </div>
            )}
          </div>
        </div>

        <p className="mt-6 text-xs text-slate-500">
          Your queue is derived from the backend&apos;s scoped approval rules. A request appears here
          only when you hold{' '}
          <code className="rounded bg-surface-3 px-1 py-0.5">resources.approve_distribute</code>{' '}
          authority for its audience. Your own submissions are excluded from this queue and remain
          under My Contributions. The backend remains authoritative for every decision.
        </p>
      </div>

      {mobileDetailOpen && selected && (
        <div className="fixed inset-0 z-[9998] bg-black/70 p-3 backdrop-blur-sm lg:hidden">
          <div className="max-h-full overflow-y-auto rounded-2xl border border-surface-border bg-surface-2 p-4">
            <ReviewRequestDetail
              request={selected}
              targets={targets}
              onDecided={handleDecided}
              onClose={() => setMobileDetailOpen(false)}
            />
            <button
              type="button"
              onClick={() => setMobileDetailOpen(false)}
              className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-surface-3 px-4 py-2 text-sm text-slate-300 hover:bg-slate-600/40"
            >
              <X className="h-4 w-4" />
              Close
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
