'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { refApi, teamsApi, type Team } from '@/lib/api';
import { EmptyState, ErrorBanner, Skeleton } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import { ChevronRight, Plus, Users2 } from 'lucide-react';

/**
 * /site-operations/teams — teams operating at the active site.
 *
 * Shows the site's teams and lets a coordinator who holds `assignments.manage`
 * AT THIS SITE create a new one. The site is fixed to the active context, so a
 * coordinator can never stand up a team at a sibling site from here — the
 * backend re-checks the same boundary through TeamPolicy.
 */
export default function SiteTeamsPage() {
  const { active, canIn } = useSiteOperations();

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  return (
    <TeamsBoard
      key={active.site_id}
      siteId={active.site_id}
      campaignId={active.campaign_id}
      canCreate={canIn('assignments.manage')}
    />
  );
}

function TeamsBoard({
  siteId,
  campaignId,
  canCreate,
}: {
  siteId: number;
  campaignId: number;
  canCreate: boolean;
}) {
  const [teams, setTeams] = useState<Team[]>([]);
  const [teamTypes, setTeamTypes] = useState<{ id: number; label: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [showCreate, setShowCreate] = useState(false);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [teamTypeId, setTeamTypeId] = useState<number | ''>('');
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState('');

  async function load() {
    try {
      setLoading(true);
      setError('');
      const [list, types] = await Promise.all([
        teamsApi.list(campaignId, { site_id: siteId }),
        refApi.teamTypes(),
      ]);
      setTeams(list);
      setTeamTypes(types);
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load teams.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [campaignId, siteId]);

  async function handleCreate(event: React.FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setFormError('A team name is required.');
      return;
    }
    setSaving(true);
    setFormError('');
    try {
      await teamsApi.create(campaignId, {
        name: name.trim(),
        description: description.trim() || undefined,
        team_type_id: teamTypeId === '' ? undefined : Number(teamTypeId),
        // Bound to the active site — never a free choice.
        mission_site_id: siteId,
      });
      setName('');
      setDescription('');
      setTeamTypeId('');
      setShowCreate(false);
      await load();
    } catch (e) {
      setFormError((e as { message?: string }).message ?? 'Failed to create the team.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-xl font-bold text-slate-100">Teams</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {teams.length} {teams.length === 1 ? 'team' : 'teams'} operating at this site
          </p>
        </div>
        {canCreate && !showCreate && (
          <button
            onClick={() => setShowCreate(true)}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors"
          >
            <Plus className="w-4 h-4" /> Create team
          </button>
        )}
      </div>

      {error && <ErrorBanner message={error} />}

      {showCreate && (
        <form onSubmit={handleCreate} className="card p-5 space-y-4">
          <h2 className="text-sm font-semibold text-slate-200">New team at this site</h2>
          {formError && <ErrorBanner message={formError} />}
          <div className="grid sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Name</label>
              <input
                value={name}
                onChange={(event) => setName(event.target.value)}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                placeholder="e.g. Village outreach team"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Team type</label>
              <select
                value={teamTypeId}
                onChange={(event) =>
                  setTeamTypeId(event.target.value ? Number(event.target.value) : '')
                }
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
              >
                <option value="">Not specified</option>
                {teamTypes.map((type) => (
                  <option key={type.id} value={type.id}>
                    {type.label}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-1.5">
              Description (optional)
            </label>
            <textarea
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              rows={2}
              className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
          </div>
          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => setShowCreate(false)}
              className="px-3.5 py-2 rounded-lg text-sm text-slate-300 hover:bg-surface-2"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              className="px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-sm font-medium"
            >
              {saving ? 'Creating…' : 'Create team'}
            </button>
          </div>
        </form>
      )}

      {loading ? (
        <div className="space-y-2">
          {[...Array(5)].map((_, i) => (
            <Skeleton key={i} className="h-16 rounded-xl" />
          ))}
        </div>
      ) : teams.length === 0 ? (
        <EmptyState
          icon={<Users2 className="w-6 h-6" />}
          title="No teams at this site"
          body={
            canCreate
              ? 'Create the first team for this mission site.'
              : 'Teams created for this site will appear here.'
          }
        />
      ) : (
        <div className="grid sm:grid-cols-2 gap-3">
          {teams.map((team) => (
            <Link
              key={team.id}
              href={`/site-operations/teams/${team.id}`}
              className="card p-4 flex items-center gap-3 hover:border-emerald-600/40 transition-colors"
            >
              <div className="w-9 h-9 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center shrink-0">
                <Users2 className="w-4 h-4 text-purple-300" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-slate-100 truncate">{team.name}</p>
                <p className="text-xs text-slate-500">
                  {team.team_type?.label ?? 'General team'} · {team.members_count ?? 0} members
                </p>
              </div>
              <ChevronRight className="w-4 h-4 text-slate-600 shrink-0" />
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
