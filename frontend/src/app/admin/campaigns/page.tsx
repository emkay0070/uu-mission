'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { campaignsApi, type Campaign } from '@/lib/api';
import { StatusBadge, Skeleton, EmptyState } from '@/components/ui';
import { Globe, ChevronRight, MapPin, Users, FileText, Plus } from 'lucide-react';

const STATUS_FILTERS = ['all', 'planning', 'active', 'completed', 'archived'] as const;

export default function CampaignsPage() {
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [filter,    setFilter]    = useState<string>('all');
  const [loading,   setLoading]   = useState(true);
  const [error,     setError]     = useState('');

  useEffect(() => {
    async function load() {
      setLoading(true); setError('');
      try {
        const params = filter === 'all' ? undefined : { status: filter };
        const res = await campaignsApi.list(params);
        setCampaigns(res.data ?? []);
      } catch (e: unknown) {
        setError((e as { message?: string }).message ?? 'Failed to load campaigns');
      } finally { setLoading(false); }
    }
    load();
  }, [filter]);

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6 animate-fade-up">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-100">Campaigns</h1>
          <p className="text-sm text-slate-400 mt-0.5">All mission campaigns within your authorized scope.</p>
        </div>
        <Link href="/campaigns/new" className="btn-primary btn-sm">
          <Plus className="w-3.5 h-3.5" /> New Campaign
        </Link>
      </div>

      {/* Filters */}
      <div className="flex gap-2 flex-wrap">
        {STATUS_FILTERS.map(s => (
          <button
            key={s}
            onClick={() => setFilter(s)}
            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${
              filter === s
                ? 'bg-brand-600 text-white'
                : 'bg-surface-2 text-slate-400 hover:text-slate-200 border border-surface-border'
            }`}
          >
            {s === 'all' ? 'All' : s.charAt(0).toUpperCase() + s.slice(1)}
          </button>
        ))}
      </div>

      {error && (
        <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">{error}</div>
      )}

      {loading ? (
        <div className="space-y-3">
          {[...Array(4)].map((_, i) => <Skeleton key={i} className="h-24 rounded-xl" />)}
        </div>
      ) : campaigns.length === 0 ? (
        <EmptyState
          icon={<Globe className="w-6 h-6" />}
          title="No campaigns found"
          body="No campaigns match the selected filter within your authorized scope."
        />
      ) : (
        <div className="space-y-3">
          {campaigns.map(c => (
            <Link
              key={c.id}
              href={`/admin/campaigns/${c.id}`}
              className="flex items-start gap-4 card p-5 hover:bg-surface-2 transition-all group"
            >
              <div className="w-11 h-11 rounded-xl bg-brand-600/20 border border-brand-600/30 flex items-center justify-center shrink-0 mt-0.5">
                <Globe className="w-5 h-5 text-brand-400" />
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <p className="font-semibold text-slate-100">{c.name}</p>
                  <StatusBadge status={c.status} />
                </div>
                <p className="text-sm text-slate-400 mt-0.5 truncate">
                  {c.programme?.type?.label} · Led by {c.lead_org_unit?.name}
                </p>
                {(c.start_date || c.end_date) && (
                  <p className="text-xs text-slate-500 mt-1">
                    {c.start_date ?? '?'} → {c.end_date ?? 'ongoing'}
                  </p>
                )}
                <div className="flex items-center gap-5 mt-3">
                  <span className="flex items-center gap-1.5 text-xs text-slate-400">
                    <MapPin className="w-3.5 h-3.5" />{c.sites_count ?? 0} sites
                  </span>
                  <span className="flex items-center gap-1.5 text-xs text-slate-400">
                    <Users className="w-3.5 h-3.5" />{c.teams_count ?? 0} teams
                  </span>
                  <span className="flex items-center gap-1.5 text-xs text-slate-400">
                    <FileText className="w-3.5 h-3.5" />{c.assignments_count ?? 0} missionaries
                  </span>
                </div>
              </div>
              <ChevronRight className="w-4 h-4 text-slate-600 group-hover:text-brand-400 transition-colors shrink-0 mt-1" />
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
