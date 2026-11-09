'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { peopleApi, type Person } from '@/lib/api';
import { EmptyState, Pagination, Skeleton } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import { Search, User } from 'lucide-react';

/**
 * /site-operations/people — the people registered at the active site.
 *
 * Read-only view over the existing people endpoint, scoped to this site. It
 * deliberately does NOT re-implement registration: person registration belongs
 * to the People workspace, and duplicating it here would fork identity
 * creation into two divergent flows.
 */
export default function SitePeoplePage() {
  const { active } = useSiteOperations();

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  return <PeopleBoard key={active.site_id} siteId={active.site_id} campaignId={active.campaign_id} />;
}

function PeopleBoard({ siteId, campaignId }: { siteId: number; campaignId: number }) {
  const [people, setPeople] = useState<Person[]>([]);
  const [query, setQuery] = useState('');
  /** The search actually applied, debounced off `query`. */
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

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
        const res = await peopleApi.list({
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
        setPeople(res.data ?? []);
        setTotal(res.total ?? 0);
        setLastPage(res.last_page ?? 1);
      } catch (e) {
        if (!cancelled) setError((e as { message?: string }).message ?? 'Failed to load people.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [campaignId, siteId, search, page]);

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div>
        <h1 className="text-xl font-bold text-slate-100">People</h1>
        <p className="text-sm text-slate-400 mt-0.5">
          {total} {total === 1 ? 'person' : 'people'} registered at this site
        </p>
      </div>

      <div className="relative">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
        <input
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search by name or phone…"
          className="w-full pl-10 pr-4 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500"
        />
      </div>

      {error && (
        <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
          {error}
        </div>
      )}

      {loading ? (
        <div className="space-y-2">
          {[...Array(6)].map((_, i) => (
            <Skeleton key={i} className="h-14 rounded-xl" />
          ))}
        </div>
      ) : people.length === 0 ? (
        <EmptyState
          icon={<User className="w-6 h-6" />}
          title={search ? 'No matching people' : 'No people registered at this site'}
          body={
            search
              ? 'Try a different name or phone number.'
              : 'People registered against this mission site will appear here.'
          }
        />
      ) : (
        <div className="card divide-y divide-surface-border">
          {people.map((person) => (
            <Link
              key={person.id}
              href={`/people/${person.id}`}
              className="flex items-center gap-3 p-3.5 hover:bg-surface-2 transition-colors"
            >
              <div className="w-9 h-9 rounded-full bg-emerald-600/20 border border-emerald-600/30 flex items-center justify-center shrink-0">
                <User className="w-4 h-4 text-emerald-300" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-slate-100 truncate">{person.full_name}</p>
                <p className="text-xs text-slate-500">
                  {person.gender ?? 'Not recorded'}
                  {person.phone ? ` · ${person.phone}` : ''}
                </p>
              </div>
            </Link>
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
    </div>
  );
}
