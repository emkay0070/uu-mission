'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { campaignsApi, sitesApi, type Campaign, type MissionSite } from '@/lib/api';
import { StatusBadge, Skeleton, EmptyState } from '@/components/ui';
import { MapPin, Globe, ChevronRight } from 'lucide-react';

export default function AllSitesPage() {
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [sitesMap,  setSitesMap]  = useState<Record<number, MissionSite[]>>({});
  const [loading,   setLoading]   = useState(true);

  useEffect(() => {
    async function load() {
      const camps = await campaignsApi.list({ status: 'active' });
      setCampaigns(camps.data ?? []);
      const entries = await Promise.all(
        (camps.data ?? []).map(async c => [c.id, await sitesApi.list(c.id)] as [number, MissionSite[]])
      );
      setSitesMap(Object.fromEntries(entries));
      setLoading(false);
    }
    load();
  }, []);

  if (loading) return (
    <div className="p-6 space-y-3 max-w-4xl mx-auto">
      {[...Array(6)].map((_, i) => <Skeleton key={i} className="h-20 rounded-xl" />)}
    </div>
  );

  const totalSites = Object.values(sitesMap).flat().length;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div>
        <h1 className="text-xl font-bold text-slate-100">Mission Sites</h1>
        <p className="text-sm text-slate-400 mt-0.5">{totalSites} active sites across all campaigns</p>
      </div>

      {campaigns.length === 0 ? (
        <EmptyState icon={<MapPin className="w-6 h-6" />} title="No active campaigns" />
      ) : campaigns.map(c => {
        const sites = sitesMap[c.id] ?? [];
        return (
          <div key={c.id}>
            <div className="flex items-center gap-2 mb-2">
              <Globe className="w-3.5 h-3.5 text-brand-400" />
              <Link href={`/admin/campaigns/${c.id}`} className="text-sm font-semibold text-slate-300 hover:text-brand-300 transition-colors">
                {c.name}
              </Link>
              <span className="text-xs text-slate-600">({sites.length} sites)</span>
            </div>
            {sites.length === 0 ? (
              <p className="text-sm text-slate-500 pl-6">No sites yet.</p>
            ) : (
              <div className="space-y-2 pl-5">
                {sites.map(s => (
                  <Link key={s.id} href={`/admin/campaigns/${c.id}/sites/${s.id}`} className="block">
                    <div className="card p-3.5 flex items-center gap-3 hover:bg-surface-2 transition-colors cursor-pointer">
                      <div className="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0">
                        <MapPin className="w-3.5 h-3.5 text-emerald-400" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-slate-100">{s.name}</p>
                        <p className="text-xs text-slate-500">
                          {s.location_description ?? 'No location'} ·
                          {s.assignments_count ?? 0} missionaries · {s.field_reports_count ?? 0} reports
                        </p>
                      </div>
                      <StatusBadge status={s.status} />
                      <ChevronRight className="w-4 h-4 text-slate-600" />
                    </div>
                  </Link>
                ))}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
