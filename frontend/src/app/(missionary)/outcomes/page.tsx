'use client';

/**
 * Mission Outcomes — missionary recording workspace.
 *
 * This page lets an authorised field worker record spiritual outcomes (a
 * decision, a baptism request, a follow-up need) against the mission site
 * they are appointed to.
 *
 * Authorisation model
 * -------------------
 * Visibility is driven entirely by EFFECTIVE PERMISSIONS, never by
 * `primary_role` or a hardcoded "missionary" check:
 *
 *   - The module itself is gated in MissionaryShell via `outcomes.record`
 *     (see lib/permissions.ts), so navigating here without the capability
 *     shows the workspace "Not available" state.
 *   - The recording interface below is revealed only when `outcomes.record`
 *     is held.
 *   - The list of SELECTABLE SITES is never derived on the client. It comes
 *     from `GET /workspace/my-sites`, which resolves the user's authorised
 *     (campaign, site) pairs — expanding campaign, site and team assignments —
 *     on the backend. The campaign context travels with each site.
 *
 * The backend still authorises every request: a 403 is rendered honestly, and
 * 422 validation errors are surfaced per field.
 *
 * This deliberately reuses the existing outcomes API and workflow
 * (`outcomesApi`) — it is NOT a second recording system. The administrator
 * interface at /admin/campaigns/[id] is untouched.
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Calendar, Loader2, Plus, Target, User, X } from 'lucide-react';
import {
  outcomesApi,
  refApi,
  workspaceApi,
  type ApiError,
  type MissionOutcome,
  type MySite,
  type OutcomeType,
} from '@/lib/api';
import { hasPermission } from '@/lib/permissions';
import { useAuthStore } from '@/store/auth';
import { EmptyState, ErrorBanner, Skeleton } from '@/components/ui';

/** The form's local shape. Numbers stay as strings until submit. */
type OutcomeForm = {
  mission_site_id: string;
  outcome_type: string;
  outcome_date: string;
  visitor_name: string;
  visitor_gender: string;
  visitor_age: string;
  notes: string;
};

const EMPTY_FORM: OutcomeForm = {
  mission_site_id: '',
  outcome_type: '',
  outcome_date: new Date().toISOString().split('T')[0],
  visitor_name: '',
  visitor_gender: '',
  visitor_age: '',
  notes: '',
};

/** Follow-up status → tailwind text colour, matching the admin interface. */
const FOLLOW_UP_COLORS: Record<string, string> = {
  pending: 'text-amber-400',
  assigned: 'text-blue-400',
  completed: 'text-emerald-400',
  lost_contact: 'text-red-400',
  not_required: 'text-slate-500',
};

export default function MissionOutcomesPage() {
  const { user } = useAuthStore();
  const canRecord = hasPermission(user, 'outcomes.record');

  // Authorised recording sites, resolved by the backend.
  const [sites, setSites] = useState<MySite[]>([]);
  const [outcomeTypes, setOutcomeTypes] = useState<OutcomeType[]>([]);

  // Outcomes for the currently selected campaign.
  const [outcomes, setOutcomes] = useState<MissionOutcome[]>([]);
  const [selectedCampaignId, setSelectedCampaignId] = useState<number | null>(null);

  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [loadError, setLoadError] = useState('');
  // Top-level message for the recording modal (403, network, generic).
  const [formError, setFormError] = useState('');
  // Structured 422 field errors, keyed by the backend's field name.
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const [showNew, setShowNew] = useState(false);
  const [form, setForm] = useState<OutcomeForm>(EMPTY_FORM);

  /** Distinct campaigns the user is authorised to work in, derived from sites. */
  const campaigns = useMemo(() => {
    const byId = new Map<number, { id: number; name: string }>();
    for (const site of sites) {
      if (site.campaign) byId.set(site.campaign.id, { id: site.campaign.id, name: site.campaign.name });
    }
    return [...byId.values()];
  }, [sites]);

  /** Only the authorised sites that belong to the selected campaign. */
  const sitesForCampaign = useMemo(
    () => sites.filter((s) => s.campaign?.id === selectedCampaignId),
    [sites, selectedCampaignId]
  );

  // ── Load authorised context (sites + reference data) ───────────────────────
  useEffect(() => {
    let cancelled = false;

    async function loadContext() {
      setIsLoading(true);
      setLoadError('');
      try {
        const [sitesResponse, types] = await Promise.all([
          workspaceApi.mySites(),
          refApi.outcomeTypes(),
        ]);
        if (cancelled) return;

        setSites(sitesResponse.sites);
        setOutcomeTypes(types);

        // Default to the first authorised campaign so the page is usable
        // immediately; a user spanning several campaigns can switch.
        const firstCampaign = sitesResponse.sites.find((s) => s.campaign)?.campaign?.id ?? null;
        setSelectedCampaignId(firstCampaign);
      } catch (e) {
        if (!cancelled) setLoadError(describeError(e).message);
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    }

    loadContext();
    return () => { cancelled = true; };
  }, []);

  // ── Load outcomes for the selected campaign ────────────────────────────────
  const loadOutcomes = useCallback(async () => {
    if (selectedCampaignId === null) {
      setOutcomes([]);
      return;
    }
    try {
      setOutcomes(await outcomesApi.list(selectedCampaignId));
    } catch (e) {
      setLoadError(describeError(e).message);
    }
  }, [selectedCampaignId]);

  useEffect(() => { loadOutcomes(); }, [loadOutcomes]);

  // ── Form helpers ───────────────────────────────────────────────────────────
  function openForm() {
    // Pre-select the site when the user only has one in this campaign.
    const onlySite = sitesForCampaign.length === 1 ? String(sitesForCampaign[0].id) : '';
    setForm({ ...EMPTY_FORM, mission_site_id: onlySite });
    setFormError('');
    setFieldErrors({});
    setShowNew(true);
  }

  function closeForm() {
    setShowNew(false);
    setForm(EMPTY_FORM);
    setFormError('');
    setFieldErrors({});
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (selectedCampaignId === null) return;

    setIsSubmitting(true);
    setFormError('');
    setFieldErrors({});

    try {
      await outcomesApi.create(selectedCampaignId, {
        mission_site_id: Number(form.mission_site_id),
        outcome_type: form.outcome_type,
        outcome_date: form.outcome_date,
        visitor_name: form.visitor_name || undefined,
        visitor_gender: form.visitor_gender || undefined,
        visitor_age: form.visitor_age ? Number(form.visitor_age) : undefined,
        notes: form.notes || undefined,
      });
      closeForm();
      await loadOutcomes();
    } catch (err) {
      const { message, errors } = describeError(err);
      // 422 validation failures expose a per-field map; render it in place.
      setFieldErrors(errors);
      setFormError(message);
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="p-4 md:p-6 max-w-4xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Mission Outcomes</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            Record decisions, baptisms and follow-up needs at your mission site
          </p>
        </div>

        {/* The recording interface is exposed only to users the backend has
            authorised to record — never on the basis of a role name. */}
        {canRecord && (
          <button
            onClick={openForm}
            disabled={sites.length === 0}
            className="btn-primary btn-sm"
            title={sites.length === 0 ? 'You are not authorised to record at any site' : undefined}
          >
            <Plus className="w-3.5 h-3.5" /> Record Outcome
          </button>
        )}
      </div>

      {loadError && <ErrorBanner message={loadError} />}

      {/* Campaign switcher — only when the user is authorised in more than one */}
      {campaigns.length > 1 && (
        <div className="flex items-center gap-2 flex-wrap">
          <span className="text-xs text-slate-500">Campaign</span>
          {campaigns.map((c) => (
            <button
              key={c.id}
              onClick={() => setSelectedCampaignId(c.id)}
              className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${
                selectedCampaignId === c.id
                  ? 'bg-brand-600 text-white'
                  : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'
              }`}
            >
              {c.name}
            </button>
          ))}
        </div>
      )}

      {/* Body */}
      {isLoading ? (
        <div className="space-y-2">
          {[...Array(5)].map((_, i) => <Skeleton key={i} className="h-14 rounded-xl" />)}
        </div>
      ) : sites.length === 0 ? (
        <EmptyState
          icon={<Target className="w-6 h-6" />}
          title="No authorised sites"
          body="You are not currently appointed to a mission site, so there is nowhere to record outcomes yet."
        />
      ) : outcomes.length === 0 ? (
        <EmptyState
          icon={<Target className="w-6 h-6" />}
          title="No outcomes recorded"
          body="Spiritual outcomes recorded at your site will appear here."
        />
      ) : (
        <div className="space-y-2">
          {outcomes.map((o) => {
            const typeDef = outcomeTypes.find((t) => t.code === o.outcome_type);
            return (
              <div key={o.id} className="card p-3.5 flex items-center gap-3">
                <div className="w-8 h-8 rounded-lg bg-brand-600/10 border border-brand-600/20 flex items-center justify-center shrink-0">
                  <Target className="w-3.5 h-3.5 text-brand-400" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100">
                    {o.visitor_name ?? 'Unknown visitor'}
                  </p>
                  <p className="text-xs text-slate-500">
                    {typeDef?.label ?? o.outcome_type} · {o.outcome_date}
                    {o.site?.name && ` · ${o.site.name}`}
                  </p>
                </div>
                <span className={`text-xs font-medium ${FOLLOW_UP_COLORS[o.follow_up_status] ?? 'text-slate-400'}`}>
                  {o.follow_up_status.replace('_', ' ')}
                </span>
              </div>
            );
          })}
        </div>
      )}

      {/* Record outcome modal */}
      {showNew && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-surface-2 w-full max-w-md rounded-2xl max-h-[90vh] overflow-y-auto">
            <div className="sticky top-0 bg-surface-2 border-b border-surface-border p-4 flex items-center justify-between">
              <h3 className="font-semibold text-slate-100">Record Outcome</h3>
              <button
                onClick={closeForm}
                className="p-2 rounded-lg hover:bg-surface-3/50 transition-colors text-slate-400"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={onSubmit} className="p-4 space-y-4">
              {/* Top-level message (403, network, or the 422 summary) */}
              {formError && <ErrorBanner message={formError} />}

              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Mission Site *</label>
                <select
                  required
                  value={form.mission_site_id}
                  onChange={(e) => setForm({ ...form, mission_site_id: e.target.value })}
                  className="input"
                >
                  <option value="">Select site…</option>
                  {/* Only sites the backend authorised for this user. */}
                  {sitesForCampaign.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                  ))}
                </select>
                <FieldErrors errors={fieldErrors.mission_site_id} />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Outcome Type *</label>
                <select
                  required
                  value={form.outcome_type}
                  onChange={(e) => setForm({ ...form, outcome_type: e.target.value })}
                  className="input"
                >
                  <option value="">Select…</option>
                  {outcomeTypes.map((t) => (
                    <option key={t.code} value={t.code}>{t.label}</option>
                  ))}
                </select>
                <FieldErrors errors={fieldErrors.outcome_type} />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Date *</label>
                <input
                  type="date"
                  required
                  value={form.outcome_date}
                  onChange={(e) => setForm({ ...form, outcome_date: e.target.value })}
                  className="input"
                />
                <FieldErrors errors={fieldErrors.outcome_date} />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-medium text-slate-100 mb-1">Visitor Gender</label>
                  <select
                    value={form.visitor_gender}
                    onChange={(e) => setForm({ ...form, visitor_gender: e.target.value })}
                    className="input"
                  >
                    <option value="">Unknown</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="unspecified">Unspecified</option>
                  </select>
                  <FieldErrors errors={fieldErrors.visitor_gender} />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-100 mb-1">Visitor Age</label>
                  <input
                    type="number"
                    min={1}
                    max={120}
                    value={form.visitor_age}
                    onChange={(e) => setForm({ ...form, visitor_age: e.target.value })}
                    className="input"
                    placeholder="e.g. 24"
                  />
                  <FieldErrors errors={fieldErrors.visitor_age} />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Visitor Name *</label>
                <input
                  type="text"
                  required
                  value={form.visitor_name}
                  onChange={(e) => setForm({ ...form, visitor_name: e.target.value })}
                  className="input"
                  placeholder="Full name if known…"
                />
                {/* Every outcome must identify a person; the backend enforces
                    this with a 422 ("at least one of person_id or visitor_name"). */}
                <p className="mt-1 text-xs text-slate-500">Required — an outcome must identify who it concerns.</p>
                <FieldErrors errors={fieldErrors.visitor_name} />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Notes</label>
                <textarea
                  rows={2}
                  value={form.notes}
                  onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  className="input resize-none"
                  placeholder="Any relevant notes…"
                />
                <FieldErrors errors={fieldErrors.notes} />
              </div>

              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={closeForm}
                  className="flex-1 px-4 py-2 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-xl font-medium transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="flex-1 px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                >
                  {isSubmitting && <Loader2 className="w-4 h-4 animate-spin" />}
                  Record Outcome
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

// ── Presentation helpers ─────────────────────────────────────────────────────

/**
 * Render the backend's structured validation messages for one field.
 *
 * The API returns `{ errors: { field: string[] } }` on a 422; each message is
 * shown directly beneath its control so the user can correct it in place.
 */
function FieldErrors({ errors }: { errors?: string[] }) {
  if (!errors || errors.length === 0) return null;
  return (
    <div className="mt-1 space-y-0.5">
      {errors.map((message, i) => (
        <p key={i} className="text-xs text-red-400">{message}</p>
      ))}
    </div>
  );
}

/**
 * Normalise a thrown API error into a message plus an optional field map.
 *
 * 403 responses carry no `errors`, so they surface as a single banner message
 * ("You are not authorised to record outcomes for this site."); 422 responses
 * additionally populate the per-field map.
 */
function describeError(e: unknown): { message: string; errors: Record<string, string[]> } {
  const err = e as ApiError | undefined;
  return {
    message: err?.message ?? 'Something went wrong. Please try again.',
    errors: err?.errors ?? {},
  };
}
