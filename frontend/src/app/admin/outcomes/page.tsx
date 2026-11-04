'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { campaignsApi, outcomesApi, refApi, type Campaign, type MissionOutcome, type OutcomeType } from '@/lib/api';
import { Skeleton, EmptyState } from '@/components/ui';
import { Target, Globe } from 'lucide-react';

export default function AllOutcomesPage() {
  const [campaigns,   setCampaigns]   = useState<Campaign[]>([]);
  const [outcomesMap, setOutcomesMap] = useState<Record<number, MissionOutcome[]>>({});
  const [types,       setTypes]       = useState<OutcomeType[]>([]);
  const [filterType,  setFilterType]  = useState('');
  const [loading,     setLoading]     = useState(true);

  useEffect(() => {
    async function load() {
      setLoading(true);
      const [camps, outcomeTypes] = await Promise.all([
        campaignsApi.list({ status: 'active' }),
        refApi.outcomeTypes(),
      ]);
      setCampaigns(camps.data ?? []);
      setTypes(outcomeTypes);
      const params = filterType ? { outcome_type: filterType } : undefined;
      const entries = await Promise.all(
        (camps.data ?? []).map(async c => [c.id, await outcomesApi.list(c.id, params)] as [number, MissionOutcome[]])
      );
      setOutcomesMap(Object.fromEntries(entries));
      setLoading(false);
    }
    load();
  }, [filterType]);

  const FOLLOW_UP_COLORS: Record<string, string> = {
    pending:      'text-amber-400',
    assigned:     'text-blue-400',
    completed:    'text-emerald-400',
    lost_contact: 'text-red-400',
    not_required: 'text-slate-500',
  };

  const total = Object.values(outcomesMap).flat().length;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-100">Spiritual Outcomes</h1>
          <p className="text-sm text-slate-400 mt-0.5">{total} outcomes recorded</p>
        </div>
        {/* Type filter — sourced from DB via API */}
        <div className="flex gap-2 flex-wrap">
          <button onClick={() => setFilterType('')}
            className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${!filterType ? 'bg-brand-600 text-white' : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'}`}>
            All types
          </button>
          {types.map(t => (
            <button key={t.code} onClick={() => setFilterType(t.code)}
              className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${filterType === t.code ? 'bg-brand-600 text-white' : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'}`}>
              {t.label}
            </button>
          ))}
        </div>
      </div>

      {loading ? (
        <div className="space-y-2">{[...Array(8)].map((_, i) => <Skeleton key={i} className="h-14 rounded-xl" />)}</div>
      ) : total === 0 ? (
        <EmptyState icon={<Target className="w-6 h-6" />} title="No outcomes" body="Spiritual outcomes will appear here once recorded." />
      ) : campaigns.map(c => {
        const outcomes = outcomesMap[c.id] ?? [];
        if (!outcomes.length) return null;
        return (
          <div key={c.id}>
            <div className="flex items-center gap-2 mb-2">
              <Globe className="w-3.5 h-3.5 text-brand-400" />
              <Link href={`/admin/campaigns/${c.id}`} className="text-sm font-semibold text-slate-300 hover:text-brand-300 transition-colors">
                {c.name}
              </Link>
              <span className="text-xs text-slate-600">({outcomes.length})</span>
            </div>
            <div className="space-y-1.5 pl-5">
              {outcomes.map(o => {
                const typeDef = types.find(t => t.code === o.outcome_type);
                return (
                  <div key={o.id} className="card p-3 flex items-center gap-3">
                    <div className="w-7 h-7 rounded-lg bg-brand-600/10 border border-brand-600/20 flex items-center justify-center shrink-0">
                      <Target className="w-3 h-3 text-brand-400" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm text-slate-100">{o.visitor_name ?? 'Unknown visitor'}</p>
                      <p className="text-xs text-slate-500">
                        {typeDef?.label ?? o.outcome_type} · {o.outcome_date}
                        {o.site?.name && ` · ${o.site.name}`}
                      </p>
                    </div>
                    <span className={`text-xs font-medium shrink-0 ${FOLLOW_UP_COLORS[o.follow_up_status] ?? 'text-slate-400'}`}>
                      {o.follow_up_status.replace('_', ' ')}
                    </span>
                  </div>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
