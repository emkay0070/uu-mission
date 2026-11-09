'use client';

import { useEffect, useState } from 'react';
import {
  AlertCircle,
  ArrowLeft,
  CheckCircle2,
  FileText,
  Loader2,
  Send,
  ShieldCheck,
  X,
} from 'lucide-react';
import type { DistributionTargets, Resource, ResourceDistributionRequest } from '@/lib/api';
import { resourcesApi } from '@/lib/api';
import { formatAudience, formatFileSize } from './audience';
import {
  emptyAudienceSelection,
  isAudienceSelectionComplete,
  selectionFromRequest,
  toDistributionSubmission,
  availableAudienceTypes,
  type AudienceSelection,
} from './audience';
import { AudienceScopePicker } from './AudienceScopePicker';

export type SubmitDistributionMode = 'submit' | 'resubmit';

/**
 * Contributor-facing submission flow.
 *
 * Step one proposes an audience and step two explicitly confirms that the
 * resource is being sent for review, not published. The backend requires an
 * audience on submission, so the proposal is a necessary input — it is never
 * treated as an automatic final decision.
 */
export function SubmitDistributionDialog({
  open,
  resource,
  request,
  mode,
  onClose,
  onSubmitted,
}: {
  open: boolean;
  resource: Resource;
  request?: ResourceDistributionRequest;
  mode: SubmitDistributionMode;
  onClose: () => void;
  onSubmitted: (request: ResourceDistributionRequest) => void;
}) {
  const [step, setStep] = useState<'audience' | 'confirm'>('audience');
  const [selection, setSelection] = useState<AudienceSelection>(emptyAudienceSelection());
  const [targets, setTargets] = useState<DistributionTargets | null>(null);
  const [loadingTargets, setLoadingTargets] = useState(false);
  const [targetsError, setTargetsError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;

    setStep('audience');
    setError(null);
    setTargetsError(null);

    setSelection(
      mode === 'resubmit' && request
        ? selectionFromRequest(request)
        : emptyAudienceSelection()
    );

    setLoadingTargets(true);
    resourcesApi
      .distributionTargets()
      .then((data) => setTargets(data))
      .catch((err: any) => setTargetsError(err?.message || 'Unable to load distribution scopes.'))
      .finally(() => setLoadingTargets(false));
  }, [open, mode, request, resource.id]);

  if (!open) return null;

  const file = resource.currentVersion ?? request?.version;
  const canContinue =
    mode === 'resubmit'
      ? Boolean(request)
      : isAudienceSelectionComplete(selection) && !loadingTargets && !targetsError;

  const handleSubmit = async () => {
    setSubmitting(true);
    setError(null);

    try {
      const submitted =
        mode === 'resubmit' && request
          ? await resourcesApi.resubmitDistribution(request.id)
          : await resourcesApi.submitDistribution(
              resource.id,
              toDistributionSubmission(selection)
            );
      onSubmitted(submitted);
    } catch (err: any) {
      setError(err?.message || 'The submission could not be sent for review.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/65 p-3 backdrop-blur-sm sm:p-6">
      <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-surface-border bg-surface-2 p-5 shadow-2xl sm:p-6">
        <div className="mb-5 flex items-start justify-between gap-4">
          <div>
            <div className="mb-2 flex items-center gap-2 text-brand-400">
              <Send className="h-5 w-5" />
              <span className="text-xs font-semibold uppercase tracking-wider">
                {mode === 'resubmit' ? 'Resubmission' : 'Distribution review'}
              </span>
            </div>
            <h2 className="text-xl font-bold text-slate-100">
              {mode === 'resubmit' ? 'Resubmit for review' : 'Submit for distribution'}
            </h2>
            <p className="mt-1 text-sm text-slate-400">
              {step === 'audience'
                ? 'Choose the audience you are proposing for review.'
                : 'Confirm that this resource should be sent for review.'}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-surface-3 hover:text-slate-200"
            aria-label="Close"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="mb-5 rounded-xl border border-surface-border bg-surface-3/70 p-4">
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-brand-500/15 text-brand-400">
              <FileText className="h-5 w-5" />
            </div>
            <div className="min-w-0">
              <p className="truncate font-semibold text-slate-100">{resource.title}</p>
              <p className="truncate text-xs text-slate-400">
                {file?.original_filename ?? 'No file attached'}
                {file?.file_size ? ` · ${formatFileSize(file.file_size)}` : ''}
              </p>
            </div>
          </div>
        </div>

        {step === 'audience' && (
          <>
            {mode === 'resubmit' && request ? (
              <div className="space-y-4">
                <div className="rounded-xl border border-surface-border bg-surface-3/60 p-4">
                  <p className="section-label mb-1">Current review scope</p>
                  <p className="text-sm font-medium text-slate-200">
                    {formatAudience(request, targets)}
                  </p>
                  <p className="mt-2 text-xs text-slate-500">
                    Resubmitting reuses the existing request and its audience. The latest uploaded
                    version will be sent to the reviewer.
                  </p>
                </div>
                <p className="text-xs text-slate-500">
                  Need a different audience? Close this dialog and choose{' '}
                  <span className="text-slate-300">Submit with a different audience</span>.
                </p>
              </div>
            ) : (
              <AudienceScopePicker
                targets={targets}
                value={selection}
                onChange={setSelection}
                loading={loadingTargets}
                error={targetsError}
                disabled={submitting}
              />
            )}

            <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
              <button
                type="button"
                onClick={onClose}
                disabled={submitting}
                className="rounded-lg bg-surface-3 px-4 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-600/40 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={() => setStep('confirm')}
                disabled={!canContinue || submitting}
                className="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-600 disabled:opacity-50"
              >
                Continue
              </button>
            </div>
          </>
        )}

        {step === 'confirm' && (
          <div className="space-y-4">
            <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200">
              <div className="flex items-start gap-3">
                <ShieldCheck className="mt-0.5 h-5 w-5 flex-shrink-0" />
                <div className="space-y-1">
                  <p className="font-semibold">This does not publish the resource.</p>
                  <p className="text-amber-200/80">
                    {mode === 'resubmit'
                      ? 'The reviewer will receive the latest version. They may approve it, return it for changes, or reject it.'
                      : 'An authorised reviewer will inspect the resource and decide whether to release it to the proposed audience.'}
                  </p>
                </div>
              </div>
            </div>

            <dl className="divide-y divide-surface-border rounded-xl border border-surface-border bg-surface-3/60">
              <div className="flex items-start justify-between gap-4 p-4">
                <dt className="text-sm text-slate-400">Resource</dt>
                <dd className="text-right text-sm font-medium text-slate-200">{resource.title}</dd>
              </div>
              <div className="flex items-start justify-between gap-4 p-4">
                <dt className="text-sm text-slate-400">File</dt>
                <dd className="max-w-[60%] truncate text-right text-sm text-slate-200">
                  {file?.original_filename ?? 'No file attached'}
                </dd>
              </div>
              <div className="flex items-start justify-between gap-4 p-4">
                <dt className="text-sm text-slate-400">Proposed audience</dt>
                <dd className="max-w-[65%] text-right text-sm font-medium text-slate-200">
                  {mode === 'resubmit' && request
                    ? formatAudience(request, targets)
                    : formatAudience(
                        {
                          ...(request ?? ({} as ResourceDistributionRequest)),
                          audience_type: selection.audience_type,
                          target_org_unit_id: selection.target_org_unit_id ?? null,
                          target_campaign_id: selection.target_campaign_id ?? null,
                          target_site_id: selection.target_site_id ?? null,
                          target_team_id: selection.target_team_id ?? null,
                          recipient_user_id: selection.recipient_user_id ?? null,
                        },
                        targets
                      )}
                </dd>
              </div>
            </dl>

            {error && (
              <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span>{error}</span>
              </div>
            )}

            <div className="flex flex-col-reverse gap-3 pt-1 sm:flex-row sm:justify-end">
              <button
                type="button"
                onClick={() => {
                  setStep('audience');
                  setError(null);
                }}
                disabled={submitting}
                className="inline-flex items-center justify-center gap-2 rounded-lg bg-surface-3 px-4 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-600/40 disabled:opacity-50"
              >
                <ArrowLeft className="h-4 w-4" />
                Back
              </button>
              <button
                type="button"
                onClick={handleSubmit}
                disabled={submitting}
                className="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-600 disabled:opacity-50"
              >
                {submitting ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    Sending for review…
                  </>
                ) : (
                  <>
                    <CheckCircle2 className="h-4 w-4" />
                    {mode === 'resubmit' ? 'Resubmit for review' : 'Submit for review'}
                  </>
                )}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
