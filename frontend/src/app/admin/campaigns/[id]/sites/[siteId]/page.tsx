'use client';
import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { sitesApi, assignmentsApi, reportsApi, outcomesApi, type MissionSite, type Assignment, type FieldReport, type MissionOutcome } from '@/lib/api';
import { StatusBadge, Skeleton, EmptyState, ErrorBanner } from '@/components/ui';
import { MapPin, Calendar, Users, FileText, Heart, ChevronLeft, AlertCircle } from 'lucide-react';
import Link from 'next/link';

export default function SiteDetailPage() {
  const { id, siteId } = useParams<{ id: string; siteId: string }>();
  const router = useRouter();
  const [site, setSite] = useState<MissionSite | null>(null);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [reports, setReports] = useState<FieldReport[]>([]);
  const [outcomes, setOutcomes] = useState<MissionOutcome[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [activeTab, setActiveTab] = useState<'overview' | 'personnel' | 'operations' | 'logistics'>('overview');

  useEffect(() => {
    async function load() {
      try {
        setLoading(true);
        const [s, a, r, o] = await Promise.all([
          sitesApi.get(Number(id), Number(siteId)),
          assignmentsApi.list(Number(id), { site_id: Number(siteId) }),
          reportsApi.list(Number(id), { site_id: Number(siteId) }),
          outcomesApi.list(Number(id), { site_id: Number(siteId) }),
        ]);
        setSite(s);
        setAssignments(a);
        setReports(r);
        setOutcomes(o);
      } catch (e: unknown) {
        setError((e as { message: string }).message);
      } finally {
        setLoading(false);
      }
    }
    load();
  }, [id, siteId]);

  if (loading) return (
    <div className="p-6 space-y-4 max-w-5xl mx-auto">
      <Skeleton className="h-8 w-64" />
      <Skeleton className="h-32 rounded-xl" />
      <Skeleton className="h-48 rounded-xl" />
    </div>
  );

  if (error || !site) return <ErrorBanner message={error || 'Site not found'} />;

  const TABS = [
    { id: 'overview' as const, label: 'Overview', icon: MapPin },
    { id: 'personnel' as const, label: 'Personnel', icon: Users },
    { id: 'operations' as const, label: 'Field Operations', icon: FileText },
    { id: 'logistics' as const, label: 'Logistics', icon: AlertCircle },
  ];

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6 animate-fade-up">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-center gap-3">
          <Link href={`/admin/campaigns/${id}`} className="p-2 rounded-lg bg-surface-2 hover:bg-surface-3 transition-colors">
            <ChevronLeft className="w-4 h-4 text-slate-400" />
          </Link>
          <div>
            <h1 className="text-xl font-bold text-slate-100">{site.name}</h1>
            <p className="text-sm text-slate-400 mt-0.5">{site.location_description || 'No location description'}</p>
          </div>
        </div>
        <StatusBadge status={site.status} />
      </div>

      {/* Tabs */}
      <div className="flex gap-2 border-b border-surface-border pb-1">
        {TABS.map(tab => {
          const Icon = tab.icon;
          return (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex items-center gap-2 px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-1 ${
                activeTab === tab.id
                  ? 'border-brand-500 text-brand-400'
                  : 'border-transparent text-slate-400 hover:text-slate-200'
              }`}
            >
              <Icon className="w-4 h-4" />
              {tab.label}
            </button>
          );
        })}
      </div>

      {/* Tab Content */}
      {activeTab === 'overview' && <OverviewTab site={site} assignments={assignments} />}
      {activeTab === 'personnel' && <PersonnelTab assignments={assignments} />}
      {activeTab === 'operations' && <OperationsTab reports={reports} outcomes={outcomes} />}
      {activeTab === 'logistics' && <LogisticsTab site={site} />}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Overview Tab
// ══════════════════════════════════════════════════════════════════════════════
function OverviewTab({ site, assignments }: { site: MissionSite; assignments: Assignment[] }) {
  const coordinator = assignments.find(a => a.site?.id === site.id)?.person;

  return (
    <div className="space-y-4">
      {/* Site Info */}
      <div className="card p-5">
        <h3 className="section-label mb-4">Site Information</h3>
        <div className="grid md:grid-cols-2 gap-4">
          <div>
            <p className="text-xs text-slate-500 mb-1">Status</p>
            <StatusBadge status={site.status} />
          </div>
          {site.operational_from && (
            <div>
              <p className="text-xs text-slate-500 mb-1">Operational Period</p>
              <p className="text-sm text-slate-300">
                {site.operational_from} {site.operational_until && `→ ${site.operational_until}`}
              </p>
            </div>
          )}
          {site.latitude && site.longitude && (
            <div>
              <p className="text-xs text-slate-500 mb-1">Coordinates</p>
              <p className="text-sm text-slate-300">{site.latitude}, {site.longitude}</p>
            </div>
          )}
        </div>
        {site.description && (
          <div className="mt-4">
            <p className="text-xs text-slate-500 mb-1">Description</p>
            <p className="text-sm text-slate-300 leading-relaxed">{site.description}</p>
          </div>
        )}
      </div>

      {/* Quick Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div className="card p-4">
          <Users className="w-5 h-5 text-brand-400 mb-2" />
          <p className="text-2xl font-bold text-slate-100">{assignments.length}</p>
          <p className="text-xs text-slate-500">Personnel</p>
        </div>
        <div className="card p-4">
          <FileText className="w-5 h-5 text-emerald-400 mb-2" />
          <p className="text-2xl font-bold text-slate-100">{site.activities_count ?? 0}</p>
          <p className="text-xs text-slate-500">Activities</p>
        </div>
        <div className="card p-4">
          <FileText className="w-5 h-5 text-purple-400 mb-2" />
          <p className="text-2xl font-bold text-slate-100">{site.field_reports_count ?? 0}</p>
          <p className="text-xs text-slate-500">Reports</p>
        </div>
        <div className="card p-4">
          <Heart className="w-5 h-5 text-rose-400 mb-2" />
          <p className="text-2xl font-bold text-slate-100">{site.outcomes_count ?? 0}</p>
          <p className="text-xs text-slate-500">Outcomes</p>
        </div>
      </div>

      {/* Readiness Status */}
      <div className="card p-5 border-l-4 border-amber-500">
        <h3 className="section-label mb-2">Site Readiness</h3>
        <p className="text-sm text-slate-400">
          Site readiness calculation will be implemented based on personnel assignments, activity scheduling, and logistics confirmation.
        </p>
      </div>
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Personnel Tab
// ══════════════════════════════════════════════════════════════════════════════
function PersonnelTab({ assignments }: { assignments: Assignment[] }) {
  if (assignments.length === 0) {
    return <EmptyState icon={<Users className="w-6 h-6" />} title="No personnel assigned" body="Assign missionaries to this site to see them here." />;
  }

  return (
    <div className="space-y-2">
      <p className="text-sm text-slate-400">{assignments.length} personnel assigned</p>
      {assignments.map(a => (
        <div key={a.id} className="card p-4 flex items-center gap-3">
          <div className="w-10 h-10 rounded-full bg-brand-600/20 border border-brand-600/30 flex items-center justify-center text-sm font-bold text-brand-300">
            {a.person.first_name[0]}{a.person.last_name[0]}
          </div>
          <div className="flex-1 min-w-0">
            <p className="font-medium text-slate-100">{a.person.full_name}</p>
            <p className="text-xs text-slate-500">
              {a.expected_arrival && `Arriving ${a.expected_arrival}`}
              {a.actual_arrival && ` · Arrived ${a.actual_arrival}`}
            </p>
          </div>
          <StatusBadge status={a.status} />
        </div>
      ))}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Field Operations Tab
// ══════════════════════════════════════════════════════════════════════════════
function OperationsTab({ reports, outcomes }: { reports: FieldReport[]; outcomes: MissionOutcome[] }) {
  return (
    <div className="space-y-6">
      {/* Reports */}
      <div>
        <h3 className="section-label mb-3">Field Reports</h3>
        {reports.length === 0 ? (
          <EmptyState icon={<FileText className="w-6 h-6" />} title="No reports" body="Field reports will appear here once submitted." />
        ) : (
          <div className="space-y-2">
            {reports.map(r => (
              <div key={r.id} className="card p-4">
                <div className="flex items-center justify-between mb-2">
                  <p className="text-sm font-medium text-slate-100">{r.period_start} → {r.period_end}</p>
                  <StatusBadge status={r.status} />
                </div>
                <div className="grid grid-cols-4 gap-2 text-center">
                  <div className="bg-surface-2 rounded p-2">
                    <p className="text-sm font-bold text-slate-100">{r.stat_meetings_held}</p>
                    <p className="text-[10px] text-slate-500">Meetings</p>
                  </div>
                  <div className="bg-surface-2 rounded p-2">
                    <p className="text-sm font-bold text-slate-100">{r.stat_total_attendance}</p>
                    <p className="text-[10px] text-slate-500">Attendance</p>
                  </div>
                  <div className="bg-surface-2 rounded p-2">
                    <p className="text-sm font-bold text-slate-100">{r.stat_decisions}</p>
                    <p className="text-[10px] text-slate-500">Decisions</p>
                  </div>
                  <div className="bg-surface-2 rounded p-2">
                    <p className="text-sm font-bold text-slate-100">{r.stat_baptisms}</p>
                    <p className="text-[10px] text-slate-500">Baptisms</p>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Outcomes */}
      <div>
        <h3 className="section-label mb-3">Spiritual Outcomes</h3>
        {outcomes.length === 0 ? (
          <EmptyState icon={<Heart className="w-6 h-6" />} title="No outcomes" body="Spiritual outcomes will appear here once recorded." />
        ) : (
          <div className="space-y-2">
            {outcomes.map(o => (
              <div key={o.id} className="card p-4 flex items-center gap-3">
                <div className="w-8 h-8 rounded-lg bg-rose-500/10 border border-rose-500/20 flex items-center justify-center shrink-0">
                  <Heart className="w-4 h-4 text-rose-400" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100">{o.outcome_type.replace(/_/g, ' ')}</p>
                  <p className="text-xs text-slate-500">
                    {o.visitor_name || 'Recorded person'} · {o.outcome_date}
                  </p>
                </div>
                <StatusBadge status={o.follow_up_status} />
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Logistics Tab
// ══════════════════════════════════════════════════════════════════════════════
function LogisticsTab({ site }: { site: MissionSite }) {
  return (
    <div className="card p-8 text-center">
      <AlertCircle className="w-12 h-12 text-slate-600 mx-auto mb-4" />
      <h3 className="text-lg font-semibold text-slate-300 mb-2">Logistics Tracking</h3>
      <p className="text-sm text-slate-500 max-w-md mx-auto">
        Venue, transport, accommodation, and materials tracking will be implemented here.
        This will help ensure each site is operationally ready for field work.
      </p>
    </div>
  );
}
