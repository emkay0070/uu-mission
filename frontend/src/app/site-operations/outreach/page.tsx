'use client';

import { useEffect, useState } from 'react';
import { outreachContactsApi, refApi, type OutreachContact } from '@/lib/api';
import { EmptyState, ErrorBanner, Modal, Pagination, Skeleton } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import { Plus, Search, UserRound } from 'lucide-react';

/**
 * /site-operations/outreach — outreach contacts registered at the active site.
 *
 * Registration is scoped to the active site automatically: the coordinator
 * does NOT pick a campaign or a site (the two fields that previously made
 * outreach registration depend on a campaign deployment being selected). The
 * backend still authorises the create against the real person/site scope.
 */
export default function SiteOutreachPage() {
  const { active, canIn } = useSiteOperations();

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  return (
    <OutreachBoard
      key={active.site_id}
      siteId={active.site_id}
      campaignId={active.campaign_id}
      canCreate={canIn('outreach-contacts.create')}
    />
  );
}

function OutreachBoard({
  siteId,
  campaignId,
  canCreate,
}: {
  siteId: number;
  campaignId: number;
  canCreate: boolean;
}) {
  const [contacts, setContacts] = useState<OutreachContact[]>([]);
  const [query, setQuery] = useState('');
  /** The search actually applied, debounced off `query`. */
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  /** Bumped after a registration to re-fetch the first page. */
  const [reloadKey, setReloadKey] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showCreate, setShowCreate] = useState(false);

  // The endpoint searches server-side and the list is paged, so the filter
  // cannot be applied to the loaded page alone — it would only ever search the
  // rows already on screen. Debounce the box and let the server answer, and
  // always fall back to page 1 so results are never a stale page of a
  // different query.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(query.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        setLoading(true);
        setError('');
        const res = await outreachContactsApi.list({
          campaign_id: campaignId,
          mission_site_id: siteId,
          search: search || undefined,
          page,
          // The endpoint caps a page at 100 (`between:5,100`); anything larger
          // is a 422, so extra records are reached by paging rather than by
          // asking for one oversized page.
          per_page: 100,
        });
        if (cancelled) return;
        setContacts(res.data ?? []);
        setTotal(res.total ?? 0);
        setLastPage(res.last_page ?? 1);
      } catch (e) {
        if (!cancelled) {
          setError((e as { message?: string }).message ?? 'Failed to load outreach contacts.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [campaignId, siteId, search, page, reloadKey]);

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-xl font-bold text-slate-100">Outreach</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {total} {total === 1 ? 'contact' : 'contacts'} registered at this site
          </p>
        </div>
        {canCreate && (
          <button
            onClick={() => setShowCreate(true)}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors"
          >
            <Plus className="w-4 h-4" /> Add contact
          </button>
        )}
      </div>

      {error && <ErrorBanner message={error} />}

      <div className="relative">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
        <input
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search by name or village…"
          className="w-full pl-10 pr-4 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500"
        />
      </div>

      {loading ? (
        <div className="space-y-2">
          {[...Array(6)].map((_, i) => (
            <Skeleton key={i} className="h-14 rounded-xl" />
          ))}
        </div>
      ) : contacts.length === 0 ? (
        <EmptyState
          icon={<UserRound className="w-6 h-6" />}
          title={search ? 'No matching contacts' : 'No outreach contacts yet'}
          body={
            search
              ? 'Try a different name or village.'
              : 'Contacts registered during outreach at this site will appear here.'
          }
        />
      ) : (
        <div className="card divide-y divide-surface-border">
          {contacts.map((contact) => (
            <div key={contact.id} className="flex items-center gap-3 p-3.5">
              <div className="w-9 h-9 rounded-full bg-emerald-600/20 border border-emerald-600/30 flex items-center justify-center shrink-0">
                <UserRound className="w-4 h-4 text-emerald-300" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-slate-100 truncate">
                  {contact.person
                    ? `${contact.person.first_name} ${contact.person.last_name}`
                    : `Contact #${contact.id}`}
                </p>
                <p className="text-xs text-slate-500">
                  {contact.engagement_stage?.label ?? 'No stage'}
                  {contact.village ? ` · ${contact.village}` : ''}
                  {contact.person?.phone ? ` · ${contact.person.phone}` : ''}
                </p>
              </div>
              {contact.date_registered && (
                <span className="text-xs text-slate-500 shrink-0">
                  {new Date(contact.date_registered).toLocaleDateString()}
                </span>
              )}
            </div>
          ))}
        </div>
      )}

      <Pagination
        page={page}
        lastPage={lastPage}
        total={total}
        onPageChange={setPage}
        disabled={loading}
      />

      {showCreate && (
        <CreateContactModal
          campaignId={campaignId}
          siteId={siteId}
          onClose={() => setShowCreate(false)}
          // Return to the unfiltered first page after registering: the new
          // contact is ordered first, and clearing the search guarantees the
          // operator sees the record they just created.
          onCreated={() => {
            setShowCreate(false);
            setQuery('');
            setSearch('');
            setPage(1);
            setReloadKey((key) => key + 1);
          }}
        />
      )}
    </div>
  );
}

/**
 * Registration form for an outreach contact at the active site.
 *
 * Campaign and site are fixed by the surrounding context, so the only inputs
 * are the person's own details and the stage they enter at.
 */
function CreateContactModal({
  campaignId,
  siteId,
  onClose,
  onCreated,
}: {
  campaignId: number;
  siteId: number;
  onClose: () => void;
  onCreated: () => void;
}) {
  const [stages, setStages] = useState<{ code: string; label: string }[]>([]);
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [phone, setPhone] = useState('');
  const [stage, setStage] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    (async () => {
      try {
        const list = await refApi.engagementStages();
        setStages(list.map((item) => ({ code: item.code, label: item.label })));
        if (list.length > 0) setStage(list[0].code);
      } catch (e) {
        setError((e as { message?: string }).message ?? 'Failed to load engagement stages.');
      }
    })();
  }, []);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (!firstName.trim() || !lastName.trim() || !stage) {
      setError('First name, last name and engagement stage are required.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      await outreachContactsApi.create({
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        phone: phone.trim() || undefined,
        notes: notes.trim() || undefined,
        engagement_stage: stage,
        campaign_id: campaignId,
        mission_site_id: siteId,
      });
      onCreated();
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to create the contact.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title="Add outreach contact">
      <form onSubmit={handleSubmit} className="space-y-4">
        {error && <ErrorBanner message={error} />}
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-1.5">First name</label>
            <input
              value={firstName}
              onChange={(event) => setFirstName(event.target.value)}
              className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-1.5">Last name</label>
            <input
              value={lastName}
              onChange={(event) => setLastName(event.target.value)}
              className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
          </div>
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Engagement stage</label>
          <select
            value={stage}
            onChange={(event) => setStage(event.target.value)}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
          >
            <option value="">Select stage</option>
            {stages.map((option) => (
              <option key={option.code} value={option.code}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Phone (optional)</label>
          <input
            value={phone}
            onChange={(event) => setPhone(event.target.value)}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Notes (optional)</label>
          <textarea
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
            rows={2}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
          />
        </div>
        <div className="flex justify-end gap-2 pt-1">
          <button
            type="button"
            onClick={onClose}
            className="px-3.5 py-2 rounded-lg text-sm text-slate-300 hover:bg-surface-2"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={saving}
            className="px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-sm font-medium"
          >
            {saving ? 'Creating…' : 'Create contact'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
