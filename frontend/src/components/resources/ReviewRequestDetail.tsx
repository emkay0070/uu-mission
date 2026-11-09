'use client';

import { useEffect, useMemo, useState } from 'react';
import {
  AlertCircle,
  CalendarDays,
  CheckCircle2,
  Download,
  Eye,
  FileText,
  Loader2,
  MessageSquareWarning,
  RotateCcw,
  ShieldCheck,
  User,
  X,
  XCircle,
} from 'lucide-react';
import type { DistributionTargets, ResourceDistributionRequest } from '@/lib/api';
import { resourcesApi, saveBlobAsFile } from '@/lib/api';
import { cn } from '@/components/ui';
import { formatAudience, formatFileSize } from './audience';
import { RequestStatusBadge } from './ResourceStatusBadge';

type DecisionAction = 'approved' | 'rejected' | 'returned';

const ACTION_LABELS: Record<string, string> = {
  submitted: 'Submitted for review',
  resubmitted: 'Resubmitted for review',
  approved: 'Approved',
  rejected: 'Rejected',
  returned: 'Returned for changes',
  revoked: 'Distribution revoked',
  superseded: 'Superseded',
};

const ACTION_TONES: Record<string, string> = {
  submitted: 'text-brand-300 bg-brand-500/15 border-brand-500/30',
  resubmitted: 'text-brand-300 bg-brand-500/15 border-brand-500/30',
  approved: 'text-emerald-300 bg-emerald-500/15 border-emerald-500/30',
  rejected: 'text-red-300 bg-red-500/15 border-red-500/30',
  returned: 'text-orange-300 bg-orange-500/15 border-orange-500/30',
  revoked: 'text-slate-300 bg-slate-500/15 border-slate-500/30',
  superseded: 'text-purple-300 bg-purple-500/15 border-purple-500/30',
};

function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      });
}

function actorName(actor: { first_name?: string; last_name?: string } | undefined): string {
  if (!actor) return 'Unknown reviewer';
  const name = [actor.first_name, actor.last_name].filter(Boolean).join(' ');
  return name || 'Unknown reviewer';
}

export function ReviewRequestDetail({
  request,
  targets,
  onDecided,
  onClose,
}: {
  request: ResourceDistributionRequest;
  targets: DistributionTargets | null;
  onDecided: (request: ResourceDistributionRequest) => void;
  onClose?: () => void;
}) {
  const [action, setAction] = useState<DecisionAction | null>(null);
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    setAction(null);
    setReason('');
    setError(null);
    setSuccess(null);
    setPreviewOpen(false);
    setFileError(null);
  }, [request.id]);

  const resource = request.resource;
  const version = request.version ?? resource?.currentVersion;
  const pending = request.status === 'pending_review';
  const audience = formatAudience(request, targets);

  const mimeType = version?.mime_type ?? '';
  const isPdf = version?.media_type === 'pdf' || mimeType.includes('pdf');
  const isImage = version?.media_type === 'image' || mimeType.startsWith('image/');
  const isPreviewable = Boolean(version && (isPdf || isImage));

  // The preview iframe/image cannot send the bearer token, so fetch the
  // protected file and render its blob URL.
  useEffect(() => {
    if (!previewOpen || !isPreviewable) return;

    let cancelled = false;
    let objectUrl: string | null = null;

    setPreviewUrl(null);
    setPreviewError(null);

    resourcesApi
      .previewBlob(request.resource_id)
      .then((blob) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setPreviewUrl(objectUrl);
      })
      .catch((err: any) => {
        if (cancelled) return;
        setPreviewError(err?.message || 'The file could not be loaded for review.');
      });

    return () => {
      cancelled = true;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [previewOpen, isPreviewable, request.resource_id]);

  const handleDownload = async () => {
    setDownloading(true);
    setFileError(null);
    try {
      const blob = await resourcesApi.downloadBlob(request.resource_id);
      saveBlobAsFile(
        blob,
        version?.original_filename || `resource-${request.resource_id}`
      );
    } catch (err: any) {
      setFileError(err?.message || 'The file could not be downloaded.');
    } finally {
      setDownloading(false);
    }
  };

  const decisions = useMemo(
    () =>
      [...(request.decisions ?? [])].sort((a, b) => {
        const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
        const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;
        return aTime - bTime || Number(a.id) - Number(b.id);
      }),
    [request.decisions]
  );

  const submitDecision = async () => {
    if (!action) return;

    const trimmed = reason.trim();
    if (action !== 'approved' && !trimmed) {
      setError('A reason is required when returning or rejecting a request.');
      return;
    }

    setSubmitting(true);
    setError(null);
    setSuccess(null);

    try {
      const updated = await resourcesApi.decideDistribution(request.id, {
        action,
        reason: trimmed || undefined,
      });
      setSuccess(
        action === 'approved'
          ? 'Approved. The resource has been released to the requested audience.'
          : action === 'returned'
          ? 'Returned to the contributor for changes.'
          : 'Request rejected.'
      );
      setAction(null);
      setReason('');
      onDecided(updated);
    } catch (err: any) {
      setError(err?.message || 'The decision could not be recorded.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <div className="space-y-5">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <div className="mb-2 flex flex-wrap items-center gap-2">
              <RequestStatusBadge status={request.status} />
              <span className="text-xs text-slate-500">Request #{request.id}</span>
            </div>
            <h2 className="text-xl font-bold text-slate-100 sm:text-2xl">
              {resource?.title ?? `Resource #${request.resource_id}`}
            </h2>
            <p className="mt-1 text-sm text-slate-400">
              Submitted {formatDate(request.submitted_at)} by{' '}
              {request.requester
                ? [request.requester.first_name, request.requester.last_name]
                    .filter(Boolean)
                    .join(' ')
                : `user #${request.requested_by_user_id}`}
            </p>
          </div>
          {onClose && (
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-surface-3 hover:text-slate-200"
              aria-label="Close request"
            >
              <X className="h-5 w-5" />
            </button>
          )}
        </div>

        <section className="rounded-xl border border-surface-border bg-surface-3/60 p-4">
          <div className="mb-3 flex items-center gap-2">
            <FileText className="h-4 w-4 text-brand-400" />
            <h3 className="text-sm font-semibold text-slate-200">Resource details</h3>
          </div>
          <div className="space-y-3">
            <p className="text-sm leading-6 text-slate-300">
              {resource?.description || 'No description was provided.'}
            </p>
            <div className="flex flex-wrap gap-2 text-xs">
              {resource?.category && (
                <span className="rounded bg-slate-700/40 px-2 py-1 text-slate-300">
                  {resource.category.label}
                </span>
              )}
              {resource?.language && (
                <span className="rounded bg-slate-700/40 px-2 py-1 text-slate-300">
                  {resource.language.toUpperCase()}
                </span>
              )}
              {resource?.intended_audience && (
                <span className="rounded bg-slate-700/40 px-2 py-1 text-slate-300">
                  Intended: {resource.intended_audience}
                </span>
              )}
            </div>
          </div>
        </section>

        <section className="rounded-xl border border-surface-border bg-surface-3/60 p-4">
          <div className="mb-3 flex items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <FileText className="h-4 w-4 text-brand-400" />
              <h3 className="text-sm font-semibold text-slate-200">File to review</h3>
            </div>
            {version && (
              <span className="rounded-full bg-slate-700/40 px-2 py-1 text-[10px] font-medium uppercase tracking-wider text-slate-400">
                {version.media_type}
              </span>
            )}
          </div>

          {version ? (
            <>
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                  <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-slate-700/40 text-slate-400">
                    <FileText className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-slate-200">
                      {version.original_filename}
                    </p>
                    <p className="text-xs text-slate-500">
                      {formatFileSize(version.file_size)} · {version.mime_type}
                    </p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2">
                  <button
                    type="button"
                    onClick={() => setPreviewOpen(true)}
                    className="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white transition-colors hover:bg-brand-600"
                  >
                    <Eye className="h-4 w-4" />
                    Inspect file
                  </button>
                  <button
                    type="button"
                    onClick={handleDownload}
                    disabled={downloading}
                    className="inline-flex items-center gap-2 rounded-lg bg-slate-700/40 px-3 py-2 text-xs text-slate-300 transition-colors hover:bg-slate-600/40 disabled:opacity-60"
                  >
                    {downloading ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <Download className="h-4 w-4" />
                    )}
                    Download
                  </button>
                </div>
              </div>
              {fileError && (
                <p className="mt-3 text-xs text-red-300">{fileError}</p>
              )}
              {!isPreviewable && (
                <p className="mt-3 text-xs text-slate-500">
                  This file type cannot be displayed inline. Download it to inspect the contents.
                </p>
              )}
            </>
          ) : (
            <p className="text-sm text-slate-400">No file version is attached to this request.</p>
          )}
        </section>

        <section className="rounded-xl border border-surface-border bg-surface-3/60 p-4">
          <div className="mb-3 flex items-center gap-2">
            <ShieldCheck className="h-4 w-4 text-brand-400" />
            <h3 className="text-sm font-semibold text-slate-200">Proposed distribution scope</h3>
          </div>
          <p className="text-base font-semibold text-slate-100">{audience}</p>
          <p className="mt-2 text-xs text-slate-500">
            Approving releases this resource to the requested audience. The review API cannot change
            the audience during approval; if the scope is wrong, return the request with feedback so
            the contributor can resubmit with a different audience.
          </p>
        </section>

        {pending ? (
          <section className="rounded-xl border border-brand-500/25 bg-brand-500/5 p-4">
            <div className="mb-4 flex items-center gap-2">
              <MessageSquareWarning className="h-4 w-4 text-brand-400" />
              <h3 className="text-sm font-semibold text-slate-200">Reviewer decision</h3>
            </div>

            {success && (
              <div className="mb-4 flex items-start gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-300">
                <CheckCircle2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span>{success}</span>
              </div>
            )}

            {!action ? (
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <button
                  type="button"
                  onClick={() => {
                    setAction('approved');
                    setError(null);
                  }}
                  className="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-emerald-500"
                >
                  <CheckCircle2 className="h-4 w-4" />
                  Approve distribution
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setAction('returned');
                    setError(null);
                  }}
                  className="inline-flex items-center justify-center gap-2 rounded-lg bg-orange-600/80 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-orange-600"
                >
                  <RotateCcw className="h-4 w-4" />
                  Return for changes
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setAction('rejected');
                    setError(null);
                  }}
                  className="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600/80 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-red-600"
                >
                  <XCircle className="h-4 w-4" />
                  Reject request
                </button>
              </div>
            ) : (
              <div className="space-y-3">
                <div
                  className={cn(
                    'rounded-lg border p-3 text-sm',
                    action === 'approved'
                      ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200'
                      : action === 'returned'
                      ? 'border-orange-500/30 bg-orange-500/10 text-orange-200'
                      : 'border-red-500/30 bg-red-500/10 text-red-200'
                  )}
                >
                  {action === 'approved' && (
                    <p>
                      Approving will publish this resource to <strong>{audience}</strong> and create
                      the distribution release.
                    </p>
                  )}
                  {action === 'returned' && (
                    <p>
                      The contributor will be able to edit the draft and resubmit it for review. Their
                      feedback is shown with the request.
                    </p>
                  )}
                  {action === 'rejected' && (
                    <p>
                      Rejecting ends this distribution request. The resource returns to the
                      contributor as a draft.
                    </p>
                  )}
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-400" htmlFor="decision-reason">
                    {action === 'approved' ? 'Approval note (optional)' : 'Reason (required)'}
                  </label>
                  <textarea
                    id="decision-reason"
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    rows={3}
                    maxLength={4000}
                    disabled={submitting}
                    placeholder={
                      action === 'approved'
                        ? 'Add a note for the contributor (optional)'
                        : 'Explain what the contributor needs to change'
                    }
                    className="w-full resize-none rounded-lg border border-surface-border bg-surface-3 px-3 py-2 text-sm text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
                  />
                </div>

                {error && (
                  <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300">
                    <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                    <span>{error}</span>
                  </div>
                )}

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <button
                    type="button"
                    onClick={() => {
                      setAction(null);
                      setReason('');
                      setError(null);
                    }}
                    disabled={submitting}
                    className="rounded-lg bg-surface-3 px-3 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-700/40 disabled:opacity-50"
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    onClick={submitDecision}
                    disabled={submitting}
                    className={cn(
                      'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors disabled:opacity-50',
                      action === 'approved'
                        ? 'bg-emerald-600 hover:bg-emerald-500'
                        : action === 'returned'
                        ? 'bg-orange-600 hover:bg-orange-500'
                        : 'bg-red-600 hover:bg-red-500'
                    )}
                  >
                    {submitting ? (
                      <>
                        <Loader2 className="h-4 w-4 animate-spin" />
                        Recording…
                      </>
                    ) : action === 'approved' ? (
                      'Approve & publish'
                    ) : action === 'returned' ? (
                      'Return to contributor'
                    ) : (
                      'Reject request'
                    )}
                  </button>
                </div>
              </div>
            )}

            {!action && error && (
              <div className="mt-3 flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span>{error}</span>
              </div>
            )}
          </section>
        ) : (
          <section className="rounded-xl border border-surface-border bg-surface-3/60 p-4">
            <div className="flex items-start gap-3">
              <CheckCircle2 className="mt-0.5 h-5 w-5 flex-shrink-0 text-slate-400" />
              <div>
                <p className="text-sm font-semibold text-slate-200">
                  This request is {request.status.replaceAll('_', ' ')}.
                </p>
                {request.decision_reason && (
                  <p className="mt-1 text-sm text-slate-400">{request.decision_reason}</p>
                )}
                <p className="mt-1 text-xs text-slate-500">
                  Decided {formatDate(request.decided_at)}
                  {request.decided_by_user_id ? ` · reviewer #${request.decided_by_user_id}` : ''}
                </p>
              </div>
            </div>
          </section>
        )}

        <section className="rounded-xl border border-surface-border bg-surface-3/60 p-4">
          <div className="mb-3 flex items-center gap-2">
            <CalendarDays className="h-4 w-4 text-brand-400" />
            <h3 className="text-sm font-semibold text-slate-200">Review history</h3>
          </div>
          {decisions.length === 0 ? (
            <p className="text-sm text-slate-400">No decisions have been recorded yet.</p>
          ) : (
            <ol className="space-y-3">
              {decisions.map((decision) => (
                <li key={decision.id} className="flex gap-3">
                  <div
                    className={cn(
                      'mt-1 h-2 w-2 flex-shrink-0 rounded-full ring-4',
                      decision.action === 'approved'
                        ? 'bg-emerald-400 ring-emerald-400/10'
                        : decision.action === 'rejected'
                        ? 'bg-red-400 ring-red-400/10'
                        : decision.action === 'returned'
                        ? 'bg-orange-400 ring-orange-400/10'
                        : 'bg-brand-400 ring-brand-400/10'
                    )}
                  />
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span
                        className={cn(
                          'rounded border px-2 py-0.5 text-[11px] font-medium',
                          ACTION_TONES[decision.action] ??
                            'border-surface-border bg-slate-700/40 text-slate-300'
                        )}
                      >
                        {ACTION_LABELS[decision.action] ?? decision.action}
                      </span>
                      <span className="text-xs text-slate-500">{formatDate(decision.created_at)}</span>
                    </div>
                    <p className="mt-1 flex items-center gap-1 text-xs text-slate-400">
                      <User className="h-3 w-3" />
                      {actorName(decision.actor)}
                    </p>
                    {decision.reason && (
                      <p className="mt-1 rounded-lg bg-slate-700/30 p-2 text-sm text-slate-300">
                        {decision.reason}
                      </p>
                    )}
                  </div>
                </li>
              ))}
            </ol>
          )}
        </section>
      </div>

      {previewOpen && version && (
        <div className="fixed inset-0 z-[10000] flex items-center justify-center bg-black/75 p-3 backdrop-blur-sm sm:p-6">
          <div className="flex h-[88vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-surface-border bg-surface-2 shadow-2xl">
            <div className="flex items-center justify-between gap-3 border-b border-surface-border px-4 py-3">
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-slate-100">{version.original_filename}</p>
                <p className="text-xs text-slate-500">
                  {formatFileSize(version.file_size)} · {version.mime_type}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={handleDownload}
                  disabled={downloading}
                  className="inline-flex items-center gap-2 rounded-lg bg-surface-3 px-3 py-1.5 text-xs text-slate-300 hover:bg-slate-700/40 disabled:opacity-60"
                >
                  {downloading ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <Download className="h-3.5 w-3.5" />
                  )}
                  Download
                </button>
                <button
                  type="button"
                  onClick={() => setPreviewOpen(false)}
                  className="rounded-lg p-1.5 text-slate-400 hover:bg-surface-3 hover:text-slate-200"
                  aria-label="Close preview"
                >
                  <X className="h-5 w-5" />
                </button>
              </div>
            </div>
            <div className="flex-1 overflow-auto bg-surface-3">
              {previewError ? (
                <div className="flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
                  <AlertCircle className="h-10 w-10 text-amber-400" />
                  <p className="text-sm text-slate-300">{previewError}</p>
                  <button
                    type="button"
                    onClick={handleDownload}
                    disabled={downloading}
                    className="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                  >
                    <Download className="h-4 w-4" />
                    Download to inspect
                  </button>
                </div>
              ) : !previewUrl ? (
                <div className="flex h-full items-center justify-center">
                  <Loader2 className="h-10 w-10 animate-spin text-brand-400" />
                </div>
              ) : isPdf ? (
                <iframe
                  title={`Preview of ${version.original_filename}`}
                  src={previewUrl}
                  className="h-full w-full"
                />
              ) : isImage ? (
                <div className="flex h-full items-center justify-center p-4">
                  <img
                    src={previewUrl}
                    alt={version.original_filename}
                    className="max-h-full max-w-full object-contain"
                  />
                </div>
              ) : (
                <div className="flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
                  <FileText className="h-12 w-12 text-slate-500" />
                  <p className="text-sm text-slate-300">
                    This file type cannot be previewed in the browser.
                  </p>
                  <button
                    type="button"
                    onClick={handleDownload}
                    disabled={downloading}
                    className="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-60"
                  >
                    <Download className="h-4 w-4" />
                    Download to inspect
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
