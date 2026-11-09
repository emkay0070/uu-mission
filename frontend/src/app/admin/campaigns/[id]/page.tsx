'use client';
import { useEffect, useState, useCallback } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  campaignsApi, sitesApi, teamsApi, assignmentsApi, reportsApi, outcomesApi, activitiesApi, refApi, roleAssignmentsApi, departmentsApi, peopleApi, api,
  type Campaign, type CampaignStats, type MissionSite, type Team,
  type Assignment, type FieldReport, type MissionOutcome, type OutcomeType, type Activity, type CampaignObjective, type Department,
} from '@/lib/api';
import {
  StatusBadge, Skeleton, EmptyState, StatCard, Modal, ErrorBanner, Spinner,
} from '@/components/ui';
import {
  Globe, MapPin, Users, FileText, Target, ChevronRight,
  ArrowLeft, CheckCircle, RotateCcw, Plus, Send, Eye,
  ClipboardCheck, AlertCircle, X, Pencil,
} from 'lucide-react';
import { useForm } from 'react-hook-form';
import type { ApiError } from '@/lib/api';

// ── Tab system ────────────────────────────────────────────────────────────────
type Tab = 'overview' | 'sites' | 'activities' | 'teams' | 'assignments' | 'reports' | 'outcomes' | 'departments';
const TABS: { id: Tab; label: string; icon: React.ElementType }[] = [
  { id: 'overview',    label: 'Overview',     icon: Globe },
  { id: 'sites',       label: 'Sites',        icon: MapPin },
  { id: 'activities',  label: 'Activities',   icon: ClipboardCheck },
  { id: 'teams',       label: 'Teams',        icon: Users },
  { id: 'assignments', label: 'Assignments',  icon: Users },
  { id: 'departments', label: 'Departments',  icon: Users },
  { id: 'reports',     label: 'Reports',      icon: FileText },
  { id: 'outcomes',    label: 'Outcomes',     icon: Target },
];

// ══════════════════════════════════════════════════════════════════════════════
// Main campaign detail page
// ══════════════════════════════════════════════════════════════════════════════
export default function CampaignDetailPage() {
  const { id } = useParams<{ id: string }>();
  const cid     = Number(id);
  const router  = useRouter();

  const [tab,      setTab]      = useState<Tab>('overview');
  const [campaign, setCampaign] = useState<Campaign | null>(null);
  const [stats,    setStats]    = useState<CampaignStats | null>(null);
  const [isTransitioning, setIsTransitioning] = useState(false);
  const [loading,  setLoading]  = useState(true);
  const [error,    setError]    = useState('');

  useEffect(() => {
    async function load() {
      try {
        const [c, s] = await Promise.all([
          campaignsApi.get(cid),
          campaignsApi.stats(cid),
        ]);
        setCampaign(c);
        setStats(s);
      } catch (e: unknown) {
        const err = e as ApiError;
        setError(err.message || 'Failed to load campaign');
      } finally { setLoading(false); }
    }
    load();
  }, [cid, router]);

  const handleToggleStatus = async () => {
    if (!campaign || isTransitioning) return;

    setIsTransitioning(true);
    try {
      const to = campaign.status === 'planning' ? 'active' : 'planning';
      const response = await campaignsApi.transition(cid, to);
      setCampaign(response.campaign);
    } catch (e: unknown) {
      const err = e as ApiError;
      alert(err.message || 'Failed to update campaign status');
    } finally {
      setIsTransitioning(false);
    }
  };

  if (loading) return (
    <div className="p-6 max-w-5xl mx-auto space-y-4">
      <Skeleton className="h-8 w-48" />
      <Skeleton className="h-28 rounded-xl" />
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[...Array(4)].map((_, i) => <Skeleton key={i} className="h-24 rounded-xl" />)}
      </div>
    </div>
  );

  if (error) return (
    <div className="p-6 max-w-2xl mx-auto mt-10">
      <ErrorBanner message={error} />
      <Link href="/admin/campaigns" className="btn-ghost mt-4 inline-flex">
        <ArrowLeft className="w-4 h-4" /> Back to campaigns
      </Link>
    </div>
  );

  if (!campaign) return null;

  return (
    <div className="flex flex-col h-full">
      {/* Campaign header */}
      <div className="border-b border-surface-border bg-surface-1 px-6 py-5">
        <Link href="/campaigns" className="flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-300 mb-3 transition-colors">
          <ArrowLeft className="w-3.5 h-3.5" /> All campaigns
        </Link>
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <div className="flex items-center gap-2.5">
              <div className="w-9 h-9 rounded-xl bg-brand-600/20 border border-brand-600/30 flex items-center justify-center">
                <Globe className="w-4 h-4 text-brand-400" />
              </div>
              <div>
                <h1 className="text-base font-semibold text-slate-100">{campaign.name}</h1>
                <p className="text-[11px] text-slate-400">
                  {campaign.programme?.type?.label} · {campaign.lead_org_unit?.name}
                </p>
              </div>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <button
              onClick={handleToggleStatus}
              disabled={isTransitioning || campaign.status === 'completed' || campaign.status === 'archived'}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${
                campaign.status === 'active'
                  ? 'bg-green-600/20 text-green-400 border border-green-600/30'
                  : campaign.status === 'planning'
                  ? 'bg-amber-600/20 text-amber-400 border border-amber-600/30'
                  : 'bg-surface-2 text-slate-400 border border-surface-border'
              } ${isTransitioning ? 'opacity-50 cursor-not-allowed' : 'hover:opacity-80'}`}
            >
              {isTransitioning ? (
                <Spinner size={3} />
              ) : campaign.status === 'active' ? (
                <>
                  <CheckCircle className="w-3 h-3" /> Active
                </>
              ) : campaign.status === 'planning' ? (
                <>
                  <RotateCcw className="w-3 h-3" /> Planning
                </>
              ) : (
                <StatusBadge status={campaign.status} />
              )}
            </button>
          </div>
        </div>

        {/* Tabs */}
        <div className="flex gap-1 mt-3 overflow-x-auto -mb-px">
          {TABS.map(t => (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              className={`flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-medium border-b-2 whitespace-nowrap transition-all ${
                tab === t.id
                  ? 'border-brand-500 text-brand-300'
                  : 'border-transparent text-slate-500 hover:text-slate-300'
              }`}
            >
              <t.icon className="w-3 h-3" />
              {t.label}
            </button>
          ))}
        </div>
      </div>

      {/* Tab content */}
      <div className="flex-1 overflow-y-auto p-6">
        {tab === 'overview'    && <OverviewTab campaign={campaign} stats={stats} />}
        {tab === 'sites'       && <SitesTab campaignId={cid} />}
        {tab === 'activities'  && <ActivitiesTab campaignId={cid} />}
        {tab === 'teams'       && <TeamsTab campaignId={cid} />}
        {tab === 'assignments' && <AssignmentsTab campaignId={cid} />}
        {tab === 'departments' && <DepartmentsTab campaignId={cid} />}
        {tab === 'reports'     && <ReportsTab campaignId={cid} campaign={campaign} />}
        {tab === 'outcomes'    && <OutcomesTab campaignId={cid} />}
      </div>
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Tab: Overview
// ══════════════════════════════════════════════════════════════════════════════
function OverviewTab({ campaign, stats }: { campaign: Campaign; stats: CampaignStats | null }) {
  const [objectives, setObjectives] = useState<CampaignObjective[]>([]);
  const [showNewObjective, setShowNewObjective] = useState(false);
  const [loadingObjectives, setLoadingObjectives] = useState(true);
  const { register, handleSubmit, reset } = useForm<{ title: string; description?: string }>();

  const handleAddObjective = async (data: { title: string; description?: string }) => {
    try {
      const newObjective = await campaignsApi.objectives.create(campaign.id, data);
      setObjectives([...objectives, newObjective]);
      setShowNewObjective(false);
      reset();
    } catch (e) {
      console.error('Failed to add objective', e);
    }
  };

  useEffect(() => {
    campaignsApi.objectives.list(campaign.id)
      .then(setObjectives)
      .finally(() => setLoadingObjectives(false));
  }, [campaign.id]);

  if (!stats) return <EmptyState icon={<Globe className="w-6 h-6" />} title="Stats unavailable" />;

  const outcomeEntries = Object.entries(stats.outcomes.by_type ?? {});

  return (
    <div className="max-w-4xl space-y-6 animate-fade-up">
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Mission Sites"    value={stats.sites_count}                     icon={<MapPin className="w-4 h-4" />} />
        <StatCard label="Teams"            value={stats.teams_count}                     icon={<Users className="w-4 h-4" />} />
        <StatCard label="Missionaries"     value={stats.missionaries.total}
                  sub={`${stats.missionaries.arrived} arrived`}                         icon={<Users className="w-4 h-4" />} />
        <StatCard label="Reports"          value={stats.reports.total}
                  sub={`${stats.reports.pending} pending review`}                       icon={<FileText className="w-4 h-4" />} />
      </div>

      <div className="grid md:grid-cols-2 gap-4">
        {/* Aggregated report numbers */}
        <div className="card p-5">
          <h3 className="section-label mb-4">From Field Reports</h3>
          <div className="space-y-2.5">
            {[
              { l: 'Meetings held',      v: stats.aggregated_from_reports.meetings_held },
              { l: 'Total attendance',   v: stats.aggregated_from_reports.total_attendance },
              { l: 'Decisions',          v: stats.aggregated_from_reports.decisions },
              { l: 'Baptisms',           v: stats.aggregated_from_reports.baptisms },
              { l: 'Bible study requests', v: stats.aggregated_from_reports.bible_studies },
            ].map(({ l, v }) => (
              <div key={l} className="flex items-center justify-between">
                <span className="text-sm text-slate-400">{l}</span>
                <span className="text-sm font-semibold text-slate-100 tabular-nums">{v.toLocaleString()}</span>
              </div>
            ))}
          </div>
        </div>

        {/* Outcomes by type */}
        <div className="card p-5">
          <h3 className="section-label mb-4">Spiritual Outcomes</h3>
          {outcomeEntries.length === 0 ? (
            <p className="text-sm text-slate-500">No outcomes recorded yet.</p>
          ) : (
            <div className="space-y-2.5">
              {outcomeEntries.map(([code, count]) => (
                <div key={code} className="flex items-center justify-between">
                  <span className="text-sm text-slate-400 capitalize">{code.replace(/_/g, ' ')}</span>
                  <span className="text-sm font-semibold text-slate-100 tabular-nums">{count}</span>
                </div>
              ))}
              <div className="border-t border-surface-border pt-2.5 flex items-center justify-between">
                <span className="text-sm font-medium text-slate-300">Total</span>
                <span className="text-sm font-bold text-brand-300 tabular-nums">{stats.outcomes.total}</span>
              </div>
            </div>
          )}
        </div>
      </div>

      {campaign.description && (
        <div className="card p-5">
          <h3 className="section-label mb-2">About this Campaign</h3>
          <p className="text-sm text-slate-300 leading-relaxed">{campaign.description}</p>
        </div>
      )}

      {/* Campaign Objectives */}
      <div className="card p-5">
        <div className="flex items-center justify-between mb-4">
          <h3 className="section-label">Campaign Objectives</h3>
          {campaign.status === 'planning' && (
            <button onClick={() => setShowNewObjective(true)} className="btn-primary btn-sm">
              <Plus className="w-3.5 h-3.5" /> Add Objective
            </button>
          )}
        </div>
        {loadingObjectives ? (
          <div className="space-y-2">{[...Array(2)].map((_, i) => <Skeleton key={i} className="h-12 rounded-lg" />)}</div>
        ) : objectives.length === 0 ? (
          <p className="text-sm text-slate-500">No objectives set yet.</p>
        ) : (
          <div className="space-y-2">
            {objectives.map(o => (
              <div key={o.id} className="flex items-center gap-3 p-3 bg-surface-2 rounded-lg">
                <StatusBadge status={o.status} />
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100">{o.title}</p>
                  {o.description && <p className="text-xs text-slate-500 mt-0.5">{o.description}</p>}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* New Objective Modal */}
      <Modal open={showNewObjective} onClose={() => setShowNewObjective(false)} title="Add Campaign Objective">
        <form onSubmit={handleSubmit(handleAddObjective)} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-1.5">Title</label>
            <input
              {...register('title', { required: true })}
              placeholder="e.g., Reach 100 new contacts"
              className="w-full px-3 py-2 bg-surface-2 border border-surface-border rounded-lg text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-1.5">Description (optional)</label>
            <textarea
              {...register('description')}
              rows={3}
              className="w-full px-3 py-2 bg-surface-2 border border-surface-border rounded-lg text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none"
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => setShowNewObjective(false)} className="btn-secondary">
              Cancel
            </button>
            <button type="submit" className="btn-primary">
              Add Objective
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Tab: Sites
// ══════════════════════════════════════════════════════════════════════════════
function SitesTab({ campaignId }: { campaignId: number }) {
  const [sites,   setSites]   = useState<MissionSite[]>([]);
  const [loading, setLoading] = useState(true);
  const [error,   setError]   = useState('');
  const [showCreate, setShowCreate] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState('');

  const { register, handleSubmit, reset, formState: { errors } } = useForm<{
    name: string;
    description: string;
    location_description: string;
    operational_from: string;
    operational_until: string;
  }>();

  useEffect(() => {
    sitesApi.list(campaignId)
      .then(setSites)
      .catch((e: ApiError) => setError(e.message))
      .finally(() => setLoading(false));
  }, [campaignId]);

  const handleCreateSite = async (data: any) => {
    setIsSubmitting(true);
    setFormError('');
    try {
      const newSite = await sitesApi.create(campaignId, {
        name: data.name,
        description: data.description || undefined,
        location_description: data.location_description || undefined,
        operational_from: data.operational_from || undefined,
        operational_until: data.operational_until || undefined,
      });
      setSites([...sites, newSite]);
      setShowCreate(false);
      reset();
    } catch (e: unknown) {
      const err = e as ApiError;
      setFormError(err.errors?.name?.[0] || err.message || 'Failed to create site');
    } finally {
      setIsSubmitting(false);
    }
  };

  if (loading) return <div className="space-y-3">{[...Array(4)].map((_, i) => <Skeleton key={i} className="h-20 rounded-xl" />)}</div>;
  if (error)   return <ErrorBanner message={error} />;

  return (
    <div className="max-w-3xl space-y-4 animate-fade-up">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-400">{sites.length} mission sites</p>
        <button onClick={() => setShowCreate(true)} className="btn-primary btn-sm">
          <Plus className="w-3.5 h-3.5" /> Create Site
        </button>
      </div>

      {sites.length === 0 && !showCreate && (
        <EmptyState icon={<MapPin className="w-6 h-6" />} title="No sites yet" body="Mission sites will appear here once created." />
      )}

      {sites.map(s => (
        <Link key={s.id} href={`/admin/campaigns/${campaignId}/sites/${s.id}`} className="block">
          <div className="card p-4 flex items-start gap-3 hover:bg-surface-2 transition-colors cursor-pointer">
            <div className="w-9 h-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0">
              <MapPin className="w-4 h-4 text-emerald-400" />
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-start justify-between">
                <div>
                  <p className="font-medium text-slate-100 text-sm">{s.name}</p>
                  <p className="text-xs text-slate-500">{s.location_description || 'No location'}</p>
                </div>
              </div>
              <div className="flex items-center gap-4 mt-2">
                <span className="text-xs text-slate-500">{s.teams_count ?? 0} teams</span>
                <span className="text-xs text-slate-500">{s.assignments_count ?? 0} missionaries</span>
                <span className="text-xs text-slate-500">{s.field_reports_count ?? 0} reports</span>
                <span className="text-xs text-slate-500">{s.outcomes_count ?? 0} outcomes</span>
              </div>
            </div>
          </div>
        </Link>
      ))}

      {showCreate && (
        <Modal open={showCreate} onClose={() => setShowCreate(false)} title="Create Mission Site">
          <form onSubmit={handleSubmit(handleCreateSite)} className="space-y-4">
            {formError && (
              <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">{formError}</div>
            )}

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Site Name *</label>
              <input
                {...register('name', { required: 'Site name is required' })}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="e.g., Lira-Kato"
              />
              {errors.name && <p className="text-xs text-red-400 mt-1">{errors.name.message}</p>}
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Description</label>
              <textarea
                {...register('description')}
                rows={3}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="Brief description of the mission site..."
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Location Description</label>
              <input
                {...register('location_description')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="e.g., 5km north of Lira town center"
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-1.5">Operational From</label>
                <input
                  {...register('operational_from')}
                  type="date"
                  className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-1.5">Operational Until</label>
                <input
                  {...register('operational_until')}
                  type="date"
                  className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                />
              </div>
            </div>

            <div className="flex justify-end gap-3 pt-2">
              <button type="button" onClick={() => setShowCreate(false)} className="btn-ghost">Cancel</button>
              <button type="submit" disabled={isSubmitting} className="btn-primary">
                {isSubmitting ? <Spinner size={4} /> : 'Create Site'}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Tab: Activities
// ══════════════════════════════════════════════════════════════════════════════
function ActivitiesTab({ campaignId }: { campaignId: number }) {
  const [activities, setActivities] = useState<Activity[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    activitiesApi.list(campaignId)
      .then(setActivities)
      .catch((e: ApiError) => setError(e.message))
      .finally(() => setLoading(false));
  }, [campaignId]);

  if (loading) return <div className="space-y-3">{[...Array(4)].map((_, i) => <Skeleton key={i} className="h-20 rounded-xl" />)}</div>;
  if (error)   return <ErrorBanner message={error} />;
  if (!activities.length) return <EmptyState icon={<ClipboardCheck className="w-6 h-6" />} title="No activities scheduled" body="Schedule field activities like evangelistic meetings, Bible studies, and visitation." />;

  return (
    <div className="max-w-3xl space-y-3 animate-fade-up">
      <p className="text-sm text-slate-400">{activities.length} scheduled activities</p>
      {activities.map(a => (
        <div key={a.id} className="card p-4">
          <div className="flex items-start justify-between gap-3">
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 mb-1">
                <p className="font-medium text-slate-100 text-sm">{a.title}</p>
                <StatusBadge status={a.status} />
              </div>
              <p className="text-xs text-slate-500 mb-2">
                {a.activity_type?.label} · {a.scheduled_at ? new Date(a.scheduled_at).toLocaleDateString() : 'No date'}
                {a.site?.name && ` · ${a.site.name}`}
              </p>
              {a.ledBy && (
                <p className="text-xs text-slate-400">
                  Led by {a.ledBy.full_name}
                </p>
              )}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Tab: Teams
// ══════════════════════════════════════════════════════════════════════════════
function TeamsTab({ campaignId }: { campaignId: number }) {
  const [teams, setTeams] = useState<Team[]>([]);
  const [sites, setSites] = useState<MissionSite[]>([]);
  const [teamTypes, setTeamTypes] = useState<any[]>([]);
  const [orgUnits, setOrgUnits] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showCreate, setShowCreate] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState('');

  const { register, handleSubmit, reset, formState: { errors } } = useForm<{
    name: string;
    description: string;
    mission_site_id: string;
    team_type_id: string;
    origin_org_unit_id: string;
  }>();

  useEffect(() => {
    Promise.all([
      teamsApi.list(campaignId),
      sitesApi.list(campaignId),
      api.get<any[]>('/ref/team-types'),
      api.get<any[]>('/ref/org-units'),
    ])
      .then(([t, s, tt, ou]) => {
        setTeams(t);
        setSites(s);
        setTeamTypes(tt);
        setOrgUnits(ou);
      })
      .catch((e: ApiError) => setError(e.message))
      .finally(() => setLoading(false));
  }, [campaignId]);

  const handleCreateTeam = async (data: any) => {
    setIsSubmitting(true);
    setFormError('');
    try {
      const newTeam = await teamsApi.create(campaignId, {
        name: data.name,
        description: data.description || undefined,
        mission_site_id: data.mission_site_id ? Number(data.mission_site_id) : undefined,
        team_type_id: data.team_type_id ? Number(data.team_type_id) : undefined,
        origin_org_unit_id: data.origin_org_unit_id ? Number(data.origin_org_unit_id) : undefined,
      });
      setTeams([...teams, newTeam]);
      setShowCreate(false);
      reset();
    } catch (e: unknown) {
      const err = e as ApiError;
      setFormError(err.errors?.name?.[0] || err.message || 'Failed to create team');
    } finally {
      setIsSubmitting(false);
    }
  };

  if (loading) return <div className="space-y-3">{[...Array(4)].map((_, i) => <Skeleton key={i} className="h-16 rounded-xl" />)}</div>;
  if (error)   return <ErrorBanner message={error} />;

  return (
    <div className="max-w-3xl space-y-4 animate-fade-up">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-400">{teams.length} teams</p>
        <button onClick={() => setShowCreate(true)} className="btn-primary btn-sm">
          <Plus className="w-3.5 h-3.5" /> Create Team
        </button>
      </div>

      {teams.length === 0 && !showCreate && (
        <EmptyState icon={<Users className="w-6 h-6" />} title="No teams yet" />
      )}

      {teams.map(t => (
        <div key={t.id} className="card p-4 flex items-center gap-3">
          <div className="w-8 h-8 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center shrink-0">
            <Users className="w-3.5 h-3.5 text-purple-400" />
          </div>
          <div className="flex-1 min-w-0">
            <p className="font-medium text-slate-100 text-sm">{t.name}</p>
            <p className="text-xs text-slate-500">
              {t.team_type?.label ?? 'General team'} · {t.members_count ?? 0} members
              {t.mission_site_id ? ` · Site #${t.mission_site_id}` : ' · Campaign-wide'}
            </p>
          </div>
        </div>
      ))}

      {showCreate && (
        <Modal open={showCreate} onClose={() => setShowCreate(false)} title="Create Team">
          <form onSubmit={handleSubmit(handleCreateTeam)} className="space-y-4">
            {formError && (
              <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">{formError}</div>
            )}

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Team Name *</label>
              <input
                {...register('name', { required: 'Team name is required' })}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="e.g., Central Uganda Conference Team"
              />
              {errors.name && <p className="text-xs text-red-400 mt-1">{errors.name.message}</p>}
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Description</label>
              <textarea
                {...register('description')}
                rows={2}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="Team purpose and responsibilities..."
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Mission Site (Optional)</label>
              <select
                {...register('mission_site_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">Campaign-wide team</option>
                {sites.map(s => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
              <p className="text-xs text-slate-500 mt-1">Leave empty for a campaign-wide team</p>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Team Type (Optional)</label>
              <select
                {...register('team_type_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">General team</option>
                {teamTypes.map(tt => (
                  <option key={tt.id} value={tt.id}>{tt.label}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Sending Organisation (Optional)</label>
              <select
                {...register('origin_org_unit_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">None specified</option>
                {orgUnits.map(ou => (
                  <option key={ou.id} value={ou.id}>{ou.name}</option>
                ))}
              </select>
            </div>

            <div className="flex justify-end gap-3 pt-2">
              <button type="button" onClick={() => setShowCreate(false)} className="btn-ghost">Cancel</button>
              <button type="submit" disabled={isSubmitting} className="btn-primary">
                {isSubmitting ? <Spinner size={4} /> : 'Create Team'}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Tab: Assignments
// ══════════════════════════════════════════════════════════════════════════════
function AssignmentsTab({ campaignId }: { campaignId: number }) {
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [sites, setSites] = useState<MissionSite[]>([]);
  const [teams, setTeams] = useState<Team[]>([]);
  const [functionalRoles, setFunctionalRoles] = useState<any[]>([]);
  const [systemRoles, setSystemRoles] = useState<any[]>([]);
  const [people, setPeople] = useState<any[]>([]);
  const [filter, setFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showCreate, setShowCreate] = useState(false);
  const [showEdit, setShowEdit] = useState(false);
  const [editingAssignment, setEditingAssignment] = useState<Assignment | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState('');

  const { register, handleSubmit, reset, watch, setValue, formState: { errors } } = useForm<{
    person_id: string;
    mission_site_id: string;
    assigned_team_id: string;
    functional_role_type_id: string;
    system_role_id: string;
    expected_arrival: string;
    notes: string;
    status?: string;
  }>();

  useEffect(() => {
    const params = filter ? { status: filter } : undefined;
    Promise.all([
      assignmentsApi.list(campaignId, params),
      sitesApi.list(campaignId),
      teamsApi.list(campaignId),
      api.get<any[]>('/ref/functional-role-types'),
      refApi.systemRoles(),
      api.get<any[]>('/ref/persons'),
    ])
      .then(([a, s, t, fr, sr, p]) => {
        setAssignments(a);
        setSites(s);
        setTeams(t);
        setFunctionalRoles(fr);
        setSystemRoles(sr);
        setPeople(p);
      })
      .catch((e: ApiError) => setError(e.message))
      .finally(() => setLoading(false));
  }, [campaignId, filter]);

  // ── System role / deployment context interplay ─────────────────────────────
  // A role's authorization scope comes from the role itself (its scope_type),
  // never from the deployment team. The dropdown therefore only offers roles
  // the current deployment can satisfy. The backend revalidates everything, so
  // this filtering is a usability aid, not an authorization boundary.
  const watchedSiteId = watch('mission_site_id');
  const watchedTeamId = watch('assigned_team_id');
  const watchedRoleId = watch('system_role_id');

  // When the site changes, a team that belongs to a different site is no longer
  // valid for this deployment, so it is cleared rather than silently kept.
  useEffect(() => {
    const team = teams.find(t => String(t.id) === String(watchedTeamId));
    if (!team) return;
    if (team.mission_site_id != null && String(team.mission_site_id) !== String(watchedSiteId)) {
      setValue('assigned_team_id', '');
    }
  }, [watchedSiteId, watchedTeamId, teams, setValue]);

  const compatibleSystemRoles = systemRoles.filter((sr) => {
    switch (sr.scope_type) {
      case 'campaign': return true;
      case 'site':     return Boolean(watchedSiteId);
      case 'team':     return Boolean(watchedTeamId);
      default:         return false; // global / org_unit roles are not offered here
    }
  });

  // Keep an already-chosen role visible even if the deployment changed, so the
  // selection is never dropped silently; the backend explains why it is invalid.
  const roleOptions = [...compatibleSystemRoles];
  const selectedRole = systemRoles.find(sr => String(sr.id) === String(watchedRoleId));
  if (selectedRole && !roleOptions.some(r => r.id === selectedRole.id)) {
    roleOptions.unshift(selectedRole);
  }

  const hasDeploymentRoles = systemRoles.some(sr => ['campaign', 'site', 'team'].includes(sr.scope_type));
  const roleHint = !hasDeploymentRoles
    ? 'No system roles are available for campaign deployments.'
    : watchedSiteId || watchedTeamId
      ? 'Only roles this deployment can satisfy are listed.'
      : 'Select a site or team to unlock site- and team-scoped roles.';

  const handleCreateAssignment = async (data: any) => {
    setIsSubmitting(true);
    setFormError('');
    try {
      const newAssignment = await assignmentsApi.create(campaignId, {
        person_id: Number(data.person_id),
        mission_site_id: data.mission_site_id ? Number(data.mission_site_id) : undefined,
        assigned_team_id: data.assigned_team_id ? Number(data.assigned_team_id) : undefined,
        functional_role_type_id: data.functional_role_type_id ? Number(data.functional_role_type_id) : undefined,
        system_role_id: data.system_role_id ? Number(data.system_role_id) : undefined,
        expected_arrival: data.expected_arrival || undefined,
        notes: data.notes || undefined,
      });
      setAssignments([...assignments, newAssignment]);
      setShowCreate(false);
      reset();
    } catch (e: unknown) {
      const err = e as ApiError;
      setFormError(err.errors?.person_id?.[0] || err.message || 'Failed to create assignment');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleUpdateAssignment = async (data: any) => {
    if (!editingAssignment) return;
    setIsSubmitting(true);
    setFormError('');
    try {
      const updated = await assignmentsApi.update(campaignId, editingAssignment.id, {
        mission_site_id: data.mission_site_id ? Number(data.mission_site_id) : null,
        assigned_team_id: data.assigned_team_id ? Number(data.assigned_team_id) : null,
        functional_role_type_id: data.functional_role_type_id ? Number(data.functional_role_type_id) : null,
        system_role_id: data.system_role_id ? Number(data.system_role_id) : null,
        status: data.status || undefined,
        expected_arrival: data.expected_arrival || undefined,
        notes: data.notes || undefined,
      });
      setAssignments(assignments.map(a => a.id === editingAssignment.id ? updated : a));
      setShowEdit(false);
      setEditingAssignment(null);
      reset();
    } catch (e: unknown) {
      const err = e as ApiError;
      setFormError(err.message || 'Failed to update assignment');
    } finally {
      setIsSubmitting(false);
    }
  };

  const openEditModal = (assignment: Assignment) => {
    setEditingAssignment(assignment);
    reset({
      mission_site_id: assignment.mission_site_id?.toString() || '',
      assigned_team_id: assignment.assigned_team_id?.toString() || '',
      functional_role_type_id: assignment.functional_role_type_id?.toString() || '',
      system_role_id: assignment.system_role_id?.toString() || '',
      status: assignment.status || '',
      expected_arrival: assignment.expected_arrival || '',
      notes: assignment.notes || '',
    });
    setShowEdit(true);
  };

  const STATUSES = ['', 'confirmed', 'arrived', 'completed', 'withdrew', 'no_show'];

  if (loading) return <div className="space-y-3">{[...Array(5)].map((_, i) => <Skeleton key={i} className="h-14 rounded-xl" />)}</div>;
  if (error)   return <ErrorBanner message={error} />;

  return (
    <div className="max-w-3xl space-y-4 animate-fade-up">
      <div className="flex items-center justify-between gap-4">
        <div className="flex gap-2 flex-wrap">
          {STATUSES.map(s => (
            <button
              key={s}
              onClick={() => { setFilter(s); setLoading(true); }}
              className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${
                filter === s ? 'bg-brand-600 text-white' : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'
              }`}
            >
              {s === '' ? 'All' : s.replace('_', ' ')}
            </button>
          ))}
        </div>
        <button onClick={() => setShowCreate(true)} className="btn-primary btn-sm">
          <Plus className="w-3.5 h-3.5" /> Assign Missionary
        </button>
      </div>

      {assignments.length === 0 && !showCreate ? (
        <EmptyState icon={<Users className="w-6 h-6" />} title="No assignments" />
      ) : (
        <div className="space-y-2">
          {assignments.map(a => (
            <div key={a.id} className="card p-3.5 flex items-center gap-3">
              <div className="w-8 h-8 rounded-full bg-brand-600/20 border border-brand-600/30 flex items-center justify-center shrink-0 text-xs font-bold text-brand-300">
                {a.person.first_name[0]}{a.person.last_name[0]}
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-slate-100">{a.person.full_name}</p>
                <p className="text-xs text-slate-500">
                  {a.site?.name ?? 'No site assigned'}
                  {a.expected_arrival && ` · Arriving ${a.expected_arrival}`}
                </p>
              </div>
              <StatusBadge status={a.status} />
              <button
                onClick={() => openEditModal(a)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-surface-2 transition-colors"
                title="Edit assignment"
              >
                <Pencil className="w-4 h-4" />
              </button>
            </div>
          ))}
        </div>
      )}

      {showCreate && (
        <Modal open={showCreate} onClose={() => setShowCreate(false)} title="Assign Missionary">
          <form onSubmit={handleSubmit(handleCreateAssignment)} className="space-y-4">
            {formError && (
              <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">{formError}</div>
            )}

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Person *</label>
              <select
                {...register('person_id', { required: 'Person is required' })}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">Select a person...</option>
                {people.map(p => (
                  <option key={p.id} value={p.id}>{p.full_name}</option>
                ))}
              </select>
              {errors.person_id && <p className="text-xs text-red-400 mt-1">{errors.person_id.message}</p>}
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Mission Site (Optional)</label>
              <select
                {...register('mission_site_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">No site assigned</option>
                {sites.map(s => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Team (Optional)</label>
              <select
                {...register('assigned_team_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">No team assigned</option>
                {teams.map(t => (
                  <option key={t.id} value={t.id}>{t.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Functional Role (Optional)</label>
              <select
                {...register('functional_role_type_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">No specific role</option>
                {functionalRoles.map(fr => (
                  <option key={fr.id} value={fr.id}>{fr.label}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">System Role (Optional)</label>
              <select
                {...register('system_role_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">No system role</option>
                {roleOptions.map(sr => (
                  <option key={sr.id} value={sr.id}>{sr.label} ({sr.scope_type})</option>
                ))}
              </select>
              <p className="mt-1 text-xs text-slate-500">{roleHint}</p>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Expected Arrival (Optional)</label>
              <input
                {...register('expected_arrival')}
                type="date"
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Notes (Optional)</label>
              <textarea
                {...register('notes')}
                rows={2}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="Additional notes about this assignment..."
              />
            </div>

            <div className="flex justify-end gap-3 pt-2">
              <button type="button" onClick={() => setShowCreate(false)} className="btn-ghost">Cancel</button>
              <button type="submit" disabled={isSubmitting} className="btn-primary">
                {isSubmitting ? <Spinner size={4} /> : 'Assign Missionary'}
              </button>
            </div>
          </form>
        </Modal>
      )}

      {showEdit && editingAssignment && (
        <Modal open={showEdit} onClose={() => setShowEdit(false)} title="Edit Assignment">
          <form onSubmit={handleSubmit(handleUpdateAssignment)} className="space-y-4">
            {formError && (
              <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">{formError}</div>
            )}

            <div className="p-3 rounded-lg bg-surface-2 border border-surface-border">
              <p className="text-sm font-medium text-slate-100">{editingAssignment.person.full_name}</p>
              <p className="text-xs text-slate-500">Person cannot be changed in an existing assignment</p>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Mission Site (Optional)</label>
              <select
                {...register('mission_site_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">No site assigned</option>
                {sites.map(s => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Team (Optional)</label>
              <select
                {...register('assigned_team_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">No team assigned</option>
                {teams.map(t => (
                  <option key={t.id} value={t.id}>{t.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Functional Role (Optional)</label>
              <select
                {...register('functional_role_type_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">No specific role</option>
                {functionalRoles.map(fr => (
                  <option key={fr.id} value={fr.id}>{fr.label}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">System Role (Optional)</label>
              <select
                {...register('system_role_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">No system role</option>
                {roleOptions.map(sr => (
                  <option key={sr.id} value={sr.id}>{sr.label} ({sr.scope_type})</option>
                ))}
              </select>
              <p className="mt-1 text-xs text-slate-500">{roleHint}</p>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Status</label>
              <select
                {...register('status')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                {STATUSES.filter(s => s !== '').map(s => (
                  <option key={s} value={s}>{s.replace('_', ' ')}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Expected Arrival (Optional)</label>
              <input
                {...register('expected_arrival')}
                type="date"
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Notes (Optional)</label>
              <textarea
                {...register('notes')}
                rows={2}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="Additional notes about this assignment..."
              />
            </div>

            <div className="flex justify-end gap-3 pt-2">
              <button type="button" onClick={() => setShowEdit(false)} className="btn-ghost">Cancel</button>
              <button type="submit" disabled={isSubmitting} className="btn-primary">
                {isSubmitting ? <Spinner size={4} /> : 'Update Assignment'}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Tab: Departments
// ══════════════════════════════════════════════════════════════════════════════
function DepartmentsTab({ campaignId }: { campaignId: number }) {
  const [departments, setDepartments] = useState<Department[]>([]);
  const [sites, setSites] = useState<MissionSite[]>([]);
  const [people, setPeople] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showCreate, setShowCreate] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState('');

  const { register, handleSubmit, reset } = useForm<{
    name: string;
    mission_site_id: string;
    leader_person_id: string;
  }>();

  useEffect(() => {
    const load = async () => {
      setLoading(true);
      try {
        const [depts, sts, ppl] = await Promise.all([
          departmentsApi.listForCampaign(campaignId),
          sitesApi.list(campaignId),
          peopleApi.list({ campaign_id: campaignId }),
        ]);
        setDepartments(depts);
        setSites(sts);
        setPeople((ppl as any)?.data ?? ppl ?? []);
      } catch (e: unknown) {
        const err = e as ApiError;
        setError(err.message || 'Failed to load departments');
      } finally {
        setLoading(false);
      }
    };
    load();
  }, [campaignId]);

  const handleCreate = async (data: any) => {
    setIsSubmitting(true);
    setFormError('');
    try {
      const newDept = await departmentsApi.create(campaignId, {
        name: data.name,
        mission_site_id: data.mission_site_id ? Number(data.mission_site_id) : undefined,
        leader_person_id: data.leader_person_id ? Number(data.leader_person_id) : undefined,
      });
      setDepartments([...departments, newDept]);
      setShowCreate(false);
      reset();
    } catch (e: unknown) {
      const err = e as ApiError;
      setFormError(err.message || 'Failed to create department');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Delete this department?')) return;
    try {
      await departmentsApi.delete(id);
      setDepartments(departments.filter(d => d.id !== id));
    } catch (e: unknown) {
      const err = e as ApiError;
      alert(err.message || 'Failed to delete department');
    }
  };

  if (loading) return <Skeleton className="h-64" />;
  if (error) return <ErrorBanner message={error} />;

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <h2 className="text-lg font-semibold text-slate-100">Departments</h2>
        <button onClick={() => setShowCreate(true)} className="btn-primary text-sm">
          <Plus className="w-4 h-4" /> Add Department
        </button>
      </div>

      {departments.length === 0 ? (
        <EmptyState
          icon={<Users className="w-6 h-6" />}
          title="No departments configured"
          body="Create departments like Youth Ministries, Women's Ministries, etc."
        />
      ) : (
        <div className="grid gap-3">
          {departments.map(dept => (
            <div key={dept.id} className="p-4 rounded-lg bg-surface-2 border border-surface-border flex justify-between items-start">
              <div>
                <h3 className="font-medium text-slate-100">{dept.name}</h3>
                {dept.site && <p className="text-xs text-slate-500 mt-1">{dept.site.name}</p>}
                {dept.leader && <p className="text-xs text-slate-400 mt-1">Leader: {dept.leader.full_name}</p>}
              </div>
              <button onClick={() => handleDelete(dept.id)} className="text-slate-500 hover:text-red-400">
                <X className="w-4 h-4" />
              </button>
            </div>
          ))}
        </div>
      )}

      {showCreate && (
        <Modal open={showCreate} onClose={() => setShowCreate(false)} title="Create Department">
          <form onSubmit={handleSubmit(handleCreate)} className="space-y-4">
            {formError && (
              <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">{formError}</div>
            )}

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Department Name</label>
              <input
                {...register('name', { required: true })}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                placeholder="e.g., Youth Ministries"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Mission Site (Optional)</label>
              <select
                {...register('mission_site_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">Campaign-wide department</option>
                {sites.map(s => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Department Leader (Optional)</label>
              <select
                {...register('leader_person_id')}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">No leader assigned</option>
                {people.map((p: any) => (
                  <option key={p.id} value={p.id}>{p.first_name} {p.last_name}</option>
                ))}
              </select>
            </div>

            <div className="flex justify-end gap-3 pt-2">
              <button type="button" onClick={() => setShowCreate(false)} className="btn-ghost">Cancel</button>
              <button type="submit" disabled={isSubmitting} className="btn-primary">
                {isSubmitting ? <Spinner size={4} /> : 'Create Department'}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Tab: Reports — full submit/review workflow
// ══════════════════════════════════════════════════════════════════════════════
type ReportForm = {
  mission_site_id: number;
  report_period_type: 'daily' | 'weekly' | 'summary';
  period_start: string;
  period_end: string;
  narrative: string;
  stat_meetings_held: number;
  stat_total_attendance: number;
  stat_decisions: number;
  stat_baptisms: number;
  stat_bible_studies_requested: number;
  challenges: string;
  prayer_requests: string;
};

function ReportsTab({ campaignId, campaign }: { campaignId: number; campaign: Campaign }) {
  const [reports,  setReports]  = useState<FieldReport[]>([]);
  const [sites,    setSites]    = useState<MissionSite[]>([]);
  const [filter,   setFilter]   = useState('');
  const [loading,  setLoading]  = useState(true);
  const [showNew,  setShowNew]  = useState(false);
  const [busy,     setBusy]     = useState(false);
  const [apiError, setApiError] = useState('');

  const { register, handleSubmit, reset, formState: { errors } } = useForm<ReportForm>();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [rpts, sts] = await Promise.all([
        reportsApi.list(campaignId, filter ? { status: filter } : undefined),
        sitesApi.list(campaignId),
      ]);
      setReports(rpts); setSites(sts);
    } catch { /* handled */ }
    finally { setLoading(false); }
  }, [campaignId, filter]);

  useEffect(() => { load(); }, [load]);

  async function onSubmitReport(data: ReportForm) {
    setBusy(true); setApiError('');
    try {
      await reportsApi.create(campaignId, data);
      reset(); setShowNew(false); load();
    } catch (e: unknown) { setApiError((e as ApiError).message); }
    finally { setBusy(false); }
  }

  async function submitForReview(report: FieldReport) {
    try {
      await reportsApi.submit(campaignId, report.id);
      load();
    } catch (e: unknown) { alert((e as ApiError).message); }
  }

  async function reviewReport(report: FieldReport, action: 'approve' | 'return') {
    try {
      await reportsApi.review(campaignId, report.id, action);
      load();
    } catch (e: unknown) { alert((e as ApiError).message); }
  }

  const STATUSES = ['', 'draft', 'submitted', 'reviewed', 'approved'];

  return (
    <div className="max-w-3xl space-y-4 animate-fade-up">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <div className="flex gap-2 flex-wrap">
          {STATUSES.map(s => (
            <button key={s} onClick={() => setFilter(s)}
              className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${
                filter === s ? 'bg-brand-600 text-white' : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'
              }`}
            >{s === '' ? 'All' : s}</button>
          ))}
        </div>
        {campaign.status === 'active' && (
          <button onClick={() => setShowNew(true)} className="btn-primary btn-sm">
            <Plus className="w-3.5 h-3.5" /> New Report
          </button>
        )}
      </div>

      {loading ? (
        <div className="space-y-3">{[...Array(4)].map((_, i) => <Skeleton key={i} className="h-24 rounded-xl" />)}</div>
      ) : reports.length === 0 ? (
        <EmptyState icon={<FileText className="w-6 h-6" />} title="No reports" body="Field reports will appear here." />
      ) : (
        <div className="space-y-3">
          {reports.map(r => (
            <div key={r.id} className="card p-4 space-y-3">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <div className="flex items-center gap-2">
                    <p className="font-medium text-slate-100 text-sm">
                      {r.site?.name ?? `Site #${r.mission_site_id}`}
                    </p>
                    <StatusBadge status={r.status} />
                    <span className="text-xs text-slate-500 bg-surface-2 px-2 py-0.5 rounded">
                      {r.report_period_type}
                    </span>
                  </div>
                  <p className="text-xs text-slate-500 mt-0.5">{r.period_start} → {r.period_end}</p>
                </div>
              </div>

              {/* Quick stats row */}
              <div className="grid grid-cols-5 gap-2">
                {[
                  { l: 'Meetings', v: r.stat_meetings_held },
                  { l: 'Attendance', v: r.stat_total_attendance },
                  { l: 'Decisions', v: r.stat_decisions },
                  { l: 'Baptisms', v: r.stat_baptisms },
                  { l: 'Bible studies', v: r.stat_bible_studies_requested },
                ].map(({ l, v }) => (
                  <div key={l} className="text-center bg-surface-2 rounded-lg p-2">
                    <p className="text-sm font-bold text-slate-100 tabular-nums">{v}</p>
                    <p className="text-[10px] text-slate-500">{l}</p>
                  </div>
                ))}
              </div>

              {/* Actions */}
              <div className="flex gap-2">
                {r.status === 'draft' && (
                  <button onClick={() => submitForReview(r)} className="btn-primary btn-sm">
                    <Send className="w-3 h-3" /> Submit for Review
                  </button>
                )}
                {(r.status === 'submitted' || r.status === 'reviewed') && (
                  <>
                    <button onClick={() => reviewReport(r, 'approve')} className="btn-primary btn-sm">
                      <CheckCircle className="w-3 h-3" /> Approve
                    </button>
                    <button onClick={() => reviewReport(r, 'return')} className="btn-ghost btn-sm">
                      <RotateCcw className="w-3 h-3" /> Return to Draft
                    </button>
                  </>
                )}
                {r.status === 'approved' && (
                  <span className="flex items-center gap-1 text-xs text-emerald-400">
                    <CheckCircle className="w-3.5 h-3.5" /> Approved
                  </span>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* New report modal */}
      <Modal open={showNew} onClose={() => { setShowNew(false); reset(); setApiError(''); }} title="New Field Report">
        <form onSubmit={handleSubmit(onSubmitReport)} className="space-y-4">
          {apiError && <ErrorBanner message={apiError} />}

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs text-slate-400 mb-1">Mission Site *</label>
              <select {...register('mission_site_id', { required: true })} className="input">
                <option value="">Select site…</option>
                {sites.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1">Period Type *</label>
              <select {...register('report_period_type', { required: true })} className="input">
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="summary">Summary</option>
              </select>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1">Period Start *</label>
              <input type="date" {...register('period_start', { required: true })} className="input" />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1">Period End *</label>
              <input type="date" {...register('period_end', { required: true })} className="input" />
            </div>
          </div>

          <div className="grid grid-cols-5 gap-2">
            {([
              ['stat_meetings_held', 'Meetings'],
              ['stat_total_attendance', 'Attendance'],
              ['stat_decisions', 'Decisions'],
              ['stat_baptisms', 'Baptisms'],
              ['stat_bible_studies_requested', 'Bible Studies'],
            ] as [keyof ReportForm, string][]).map(([key, label]) => (
              <div key={key}>
                <label className="block text-[10px] text-slate-400 mb-1">{label}</label>
                <input type="number" min={0} defaultValue={0} {...register(key)} className="input text-center px-1" />
              </div>
            ))}
          </div>

          <div>
            <label className="block text-xs text-slate-400 mb-1">Narrative</label>
            <textarea {...register('narrative')} rows={3} className="input resize-none" placeholder="Summary of activities…" />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => { setShowNew(false); reset(); }} className="btn-ghost">Cancel</button>
            <button type="submit" disabled={busy} className="btn-primary">
              {busy ? <Spinner size={4} /> : 'Save Draft'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Tab: Outcomes
// ══════════════════════════════════════════════════════════════════════════════
type OutcomeForm = {
  mission_site_id: number;
  outcome_type: string;
  outcome_date: string;
  visitor_name: string;
  visitor_gender: string;
  visitor_age: number;
  notes: string;
};

function OutcomesTab({ campaignId }: { campaignId: number }) {
  const [outcomes,      setOutcomes]     = useState<MissionOutcome[]>([]);
  const [outcomeTypes,  setOutcomeTypes] = useState<OutcomeType[]>([]);
  const [sites,         setSites]        = useState<MissionSite[]>([]);
  const [filterType,    setFilterType]   = useState('');
  const [loading,       setLoading]      = useState(true);
  const [showNew,       setShowNew]      = useState(false);
  const [busy,          setBusy]         = useState(false);
  const [apiError,      setApiError]     = useState('');

  const { register, handleSubmit, reset } = useForm<OutcomeForm>();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [outs, types, sts] = await Promise.all([
        outcomesApi.list(campaignId, filterType ? { outcome_type: filterType } : undefined),
        refApi.outcomeTypes(),
        sitesApi.list(campaignId),
      ]);
      setOutcomes(outs); setOutcomeTypes(types); setSites(sts);
    } catch { /* handled */ }
    finally { setLoading(false); }
  }, [campaignId, filterType]);

  useEffect(() => { load(); }, [load]);

  async function onRecordOutcome(data: OutcomeForm) {
    setBusy(true); setApiError('');
    try {
      await outcomesApi.create(campaignId, data);
      reset(); setShowNew(false); load();
    } catch (e: unknown) { setApiError((e as ApiError).message); }
    finally { setBusy(false); }
  }

  const FOLLOW_UP_COLORS: Record<string, string> = {
    pending:      'text-amber-400',
    assigned:     'text-blue-400',
    completed:    'text-emerald-400',
    lost_contact: 'text-red-400',
    not_required: 'text-slate-500',
  };

  return (
    <div className="max-w-3xl space-y-4 animate-fade-up">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <div className="flex gap-2 flex-wrap">
          <button onClick={() => setFilterType('')}
            className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${!filterType ? 'bg-brand-600 text-white' : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'}`}>
            All
          </button>
          {outcomeTypes.map(t => (
            <button key={t.code} onClick={() => setFilterType(t.code)}
              className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${filterType === t.code ? 'bg-brand-600 text-white' : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'}`}>
              {t.label}
            </button>
          ))}
        </div>
        <button onClick={() => setShowNew(true)} className="btn-primary btn-sm">
          <Plus className="w-3.5 h-3.5" /> Record Outcome
        </button>
      </div>

      {loading ? (
        <div className="space-y-2">{[...Array(5)].map((_, i) => <Skeleton key={i} className="h-14 rounded-xl" />)}</div>
      ) : outcomes.length === 0 ? (
        <EmptyState icon={<Target className="w-6 h-6" />} title="No outcomes recorded" body="Spiritual outcomes recorded in the field will appear here." />
      ) : (
        <div className="space-y-2">
          {outcomes.map(o => {
            const typeDef = outcomeTypes.find(t => t.code === o.outcome_type);
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

      {/* Record outcome modal — uses DB outcome types from API */}
      <Modal open={showNew} onClose={() => { setShowNew(false); reset(); setApiError(''); }} title="Record Outcome">
        <form onSubmit={handleSubmit(onRecordOutcome)} className="space-y-4">
          {apiError && <ErrorBanner message={apiError} />}

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs text-slate-400 mb-1">Mission Site *</label>
              <select {...register('mission_site_id', { required: true })} className="input">
                <option value="">Select site…</option>
                {sites.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1">Outcome Type *</label>
              <select {...register('outcome_type', { required: true })} className="input">
                <option value="">Select…</option>
                {outcomeTypes.map(t => <option key={t.code} value={t.code}>{t.label}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1">Date *</label>
              <input type="date" {...register('outcome_date', { required: true })} className="input"
                defaultValue={new Date().toISOString().split('T')[0]} />
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1">Gender</label>
              <select {...register('visitor_gender')} className="input">
                <option value="">Unknown</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="unspecified">Unspecified</option>
              </select>
            </div>
          </div>

          <div>
            <label className="block text-xs text-slate-400 mb-1">Visitor Name</label>
            <input {...register('visitor_name')} className="input" placeholder="Full name if known…" />
          </div>

          <div>
            <label className="block text-xs text-slate-400 mb-1">Notes</label>
            <textarea {...register('notes')} rows={2} className="input resize-none" placeholder="Any relevant notes…" />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => { setShowNew(false); reset(); }} className="btn-ghost">Cancel</button>
            <button type="submit" disabled={busy} className="btn-primary">
              {busy ? <Spinner size={4} /> : 'Record Outcome'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
