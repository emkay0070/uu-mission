'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { campaignsApi, teamsApi, type Campaign, type Team } from '@/lib/api';
import { Skeleton, EmptyState } from '@/components/ui';
import { Users, Globe } from 'lucide-react';

export default function AllTeamsPage() {
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [teamsMap,  setTeamsMap]  = useState<Record<number, Team[]>>({});
  const [loading,   setLoading]   = useState(true);

  useEffect(() => {
    async function load() {
      const camps = await campaignsApi.list({ status: 'active' });
      setCampaigns(camps.data ?? []);
      const entries = await Promise.all(
        (camps.data ?? []).map(async c => [c.id, await teamsApi.list(c.id)] as [number, Team[]])
      );
      setTeamsMap(Object.fromEntries(entries));
      setLoading(false);
    }
    load();
  }, []);

  const total = Object.values(teamsMap).flat().length;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div>
        <h1 className="text-xl font-bold text-slate-100">Teams</h1>
        <p className="text-sm text-slate-400 mt-0.5">{total} teams across all active campaigns</p>
      </div>

      {loading ? (
        <div className="space-y-2">{[...Array(8)].map((_, i) => <Skeleton key={i} className="h-14 rounded-xl" />)}</div>
      ) : total === 0 ? (
        <EmptyState icon={<Users className="w-6 h-6" />} title="No teams" />
      ) : campaigns.map(c => {
        const teams = teamsMap[c.id] ?? [];
        if (!teams.length) return null;
        return (
          <div key={c.id}>
            <div className="flex items-center gap-2 mb-2">
              <Globe className="w-3.5 h-3.5 text-brand-400" />
              <Link href={`/admin/campaigns/${c.id}`} className="text-sm font-semibold text-slate-300 hover:text-brand-300 transition-colors">
                {c.name}
              </Link>
              <span className="text-xs text-slate-600">({teams.length})</span>
            </div>
            <div className="grid sm:grid-cols-2 gap-2 pl-5">
              {teams.map(t => (
                <div key={t.id} className="card p-3.5 flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center shrink-0">
                    <Users className="w-3.5 h-3.5 text-purple-400" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-slate-100 truncate">{t.name}</p>
                    <p className="text-xs text-slate-500">
                      {t.team_type?.label ?? 'General'} · {t.members_count ?? 0} members
                    </p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}
