'use client';

import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { refApi, teamsApi, type TeamDetail, type TeamMember } from '@/lib/api';
import { EmptyState, ErrorBanner, Modal, Skeleton, SuccessBanner } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { SiteOpsBackLink } from '@/components/site-operations/SiteOpsBackLink';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import { Crown, Loader2, Pencil, Trash2, UserPlus, Users2 } from 'lucide-react';

/**
 * /site-operations/teams/[id] — team detail and management for the ACTIVE site.
 *
 * A coordinator opens a team from the Teams list and manages it here:
 *   - read the team's details and its CURRENT roster (soft-ended members are
 *     already excluded by the backend, so "left the team" never shows as
 *     "on the team");
 *   - edit the permitted details (name, description, type);
 *   - appoint or change the team leader — leadership is the `leader` value of an
 *     existing membership, not a separate leadership record, so transferring it
 *     is a role change on that member and the sitting leader is demoted;
 *   - add eligible people to the team and remove members (a soft end, not a
 *     delete).
 *
 * AUTHORIZATION. The site is fixed to the active context and every call is
 * re-authorised by the backend against the team's REAL site. The capability
 * checks here only decide which controls to render — they never stand in for
 * the backend decision, and a 403 from the server is surfaced as an error
 * rather than swallowed.
 *
 * Team membership is a ROSTER FACT only: it never grants a system role, so
 * appointing a leader cannot make anyone a Site Coordinator (or any other
 * officer). Authority is conferred solely through role assignments, which are
 * managed elsewhere.
 */
export default function SiteTeamDetailPage() {
  const { active, canIn } = useSiteOperations();
  const params = useParams<{ id: string }>();
  const teamId = Number(params?.id);

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  if (!Number.isFinite(teamId) || teamId <= 0) {
    return (
      <div className="p-6 max-w-4xl mx-auto">
        <ErrorBanner message="This team could not be identified." />
      </div>
    );
  }

  return (
    <TeamDetailBoard
      // Remount when the site or team changes so no stale roster survives a
      // switch — the same pattern the other Site Operations boards use.
      key={`${active.site_id}-${teamId}`}
      siteId={active.site_id}
      campaignId={active.campaign_id}
      teamId={teamId}
      canManage={canIn('assignments.manage')}
    />
  );
}

function TeamDetailBoard({
  siteId,
  campaignId,
  teamId,
  canManage,
}: {
  siteId: number;
  campaignId: number;
  teamId: number;
  canManage: boolean;
}) {
  const [team, setTeam] = useState<TeamDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busyMemberId, setBusyMemberId] = useState<number | null>(null);

  const [editOpen, setEditOpen] = useState(false);
  const [addOpen, setAddOpen] = useState(false);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      setError('');
      setTeam(await teamsApi.get(campaignId, teamId));
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load this team.');
    } finally {
      setLoading(false);
    }
  }, [campaignId, teamId]);

  useEffect(() => {
    load();
  }, [load]);

  // Every mutation funnels through here so busy/notice/error behave the same
  // way and the roster is always re-read from the server afterwards.
  async function run(action: () => Promise<void>, successMessage: string, memberId: number | null = null) {
    setBusyMemberId(memberId);
    setError('');
    setNotice('');
    try {
      await action();
      await load();
      setNotice(successMessage);
    } catch (e) {
      setError((e as { message?: string }).message ?? 'The change could not be saved.');
    } finally {
      setBusyMemberId(null);
    }
  }

  async function makeLeader(member: TeamMember) {
    await run(
      () => teamsApi.updateMember(campaignId, teamId, member.id, { role_in_team: 'leader' }).then(() => undefined),
      `${member.person.full_name} is now the team leader.`,
      member.id
    );
  }

  async function remove(member: TeamMember) {
    await run(
      () => teamsApi.removeMember(campaignId, teamId, member.id).then(() => undefined),
      `${member.person.full_name} was removed from the team.`,
      member.id
    );
  }

  if (loading) {
    return (
      <div className="p-6 max-w-4xl mx-auto space-y-4">
        <Skeleton className="h-6 w-40 rounded-lg" />
        <Skeleton className="h-32 rounded-xl" />
        <Skeleton className="h-48 rounded-xl" />
      </div>
    );
  }

  if (error && !team) {
    return (
      <div className="p-6 max-w-4xl mx-auto space-y-4">
        <SiteOpsBackLink href="/site-operations/teams" label="Back to teams" />
        <ErrorBanner message={error} />
      </div>
    );
  }

  if (!team) {
    return (
      <div className="p-6 max-w-4xl mx-auto space-y-4">
        <SiteOpsBackLink href="/site-operations/teams" label="Back to teams" />
        <EmptyState icon={<Users2 className="w-6 h-6" />} title="Team not found" />
      </div>
    );
  }

  // A team from another site may be readable (a campaign leader can see it),
  // but it must NEVER be managed from here: management belongs to the site
  // that owns the team.
  const foreignSite = team.mission_site_id != null && team.mission_site_id !== siteId;

  const members = [...(team.members ?? [])].sort(byRank);
  const leader = members.find((member) => member.role_in_team === 'leader') ?? null;
  const canManageHere = canManage && !foreignSite;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <SiteOpsBackLink href="/site-operations/teams" label="Back to teams" />

      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div className="min-w-0">
          <h1 className="text-xl font-bold text-slate-100 truncate">{team.name}</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {team.team_type?.label ?? 'General team'}
            {team.site ? ` · ${team.site.name}` : ' · Campaign-wide'}
            {team.origin_org_unit ? ` · ${team.origin_org_unit.name}` : ''}
          </p>
        </div>
        {canManageHere && (
          <button
            onClick={() => setEditOpen(true)}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-surface-2 border border-surface-border hover:bg-surface-3 text-slate-200 text-sm font-medium transition-colors"
          >
            <Pencil className="w-4 h-4" /> Edit details
          </button>
        )}
      </div>

      {foreignSite && (
        <ErrorBanner
          message={`This team belongs to a different site. Switch to that site to manage it — teams can only be managed from their own site.`}
        />
      )}

      {error && <ErrorBanner message={error} />}
      {notice && <SuccessBanner message={notice} />}

      {team.description && (
        <div className="card p-4">
          <p className="section-label pb-1.5">About this team</p>
          <p className="text-sm text-slate-300 whitespace-pre-line">{team.description}</p>
        </div>
      )}

      <div className="card p-5 space-y-4">
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <div>
            <p className="section-label pb-1">Team leader</p>
            {leader ? (
              <p className="text-sm font-medium text-slate-100 flex items-center gap-2">
                <Crown className="w-4 h-4 text-amber-300" />
                {leader.person.full_name}
              </p>
            ) : (
              <p className="text-sm text-slate-500">No leader appointed yet.</p>
            )}
          </div>
          <p className="text-xs text-slate-500">
            {members.length} {members.length === 1 ? 'member' : 'members'}
          </p>
        </div>

        {members.length === 0 ? (
          <EmptyState
            icon={<Users2 className="w-6 h-6" />}
            title="No one is on this team yet"
            body={canManageHere ? 'Add the first member to get started.' : undefined}
          />
        ) : (
          <ul className="divide-y divide-surface-border">
            {members.map((member) => (
              <li key={member.id} className="flex items-center gap-3 py-3">
                <div className="w-9 h-9 rounded-full bg-emerald-600/20 border border-emerald-600/30 flex items-center justify-center shrink-0 text-xs font-semibold text-emerald-300">
                  {initials(member.person.full_name)}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100 truncate">
                    {member.person.full_name}
                  </p>
                  <p className="text-xs text-slate-500">
                    {roleLabel(member.role_in_team)}
                    {member.joined_on ? ` · joined ${member.joined_on}` : ''}
                  </p>
                </div>

                {canManageHere && (
                  <div className="flex items-center gap-1.5 shrink-0">
                    {member.role_in_team !== 'leader' && (
                      <button
                        onClick={() => makeLeader(member)}
                        disabled={busyMemberId === member.id}
                        className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-amber-500/10 border border-amber-500/25 hover:bg-amber-500/20 disabled:opacity-50 text-amber-300 text-xs font-medium transition-colors"
                        title={leader ? 'Make this person the team leader' : 'Appoint as team leader'}
                      >
                        <Crown className="w-3.5 h-3.5" />
                        {leader ? 'Make leader' : 'Appoint'}
                      </button>
                    )}
                    <button
                      onClick={() => remove(member)}
                      disabled={busyMemberId === member.id}
                      className="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:text-red-400 hover:bg-red-500/10 disabled:opacity-50 transition-colors"
                      title="Remove from team"
                    >
                      {busyMemberId === member.id ? (
                        <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      ) : (
                        <Trash2 className="w-3.5 h-3.5" />
                      )}
                    </button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}

        {canManageHere && (
          <button
            onClick={() => setAddOpen(true)}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors"
          >
            <UserPlus className="w-4 h-4" /> Add member
          </button>
        )}
      </div>

      {canManageHere && (
        <EditTeamModal
          open={editOpen}
          onClose={() => setEditOpen(false)}
          campaignId={campaignId}
          team={team}
          onSaved={(message) => {
            setEditOpen(false);
            setNotice(message);
            load();
          }}
        />
      )}

      {canManageHere && (
        <AddMemberModal
          open={addOpen}
          onClose={() => setAddOpen(false)}
          campaignId={campaignId}
          teamId={teamId}
          existingPersonIds={members.map((member) => member.person.id)}
          onSaved={(message) => {
            setAddOpen(false);
            setNotice(message);
            load();
          }}
        />
      )}
    </div>
  );
}

// ── Edit details ─────────────────────────────────────────────────────────────

function EditTeamModal({
  open,
  onClose,
  campaignId,
  team,
  onSaved,
}: {
  open: boolean;
  onClose: () => void;
  campaignId: number;
  team: TeamDetail;
  onSaved: (message: string) => void;
}) {
  const [name, setName] = useState(team.name);
  const [description, setDescription] = useState(team.description ?? '');
  const [teamTypeId, setTeamTypeId] = useState<number | ''>(team.team_type?.id ?? '');
  const [teamTypes, setTeamTypes] = useState<{ id: number; label: string }[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setName(team.name);
    setDescription(team.description ?? '');
    setTeamTypeId(team.team_type?.id ?? '');
    setError('');
    refApi.teamTypes().then(setTeamTypes).catch(() => setTeamTypes([]));
  }, [open, team]);

  async function save(event: React.FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setError('A team name is required.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      await teamsApi.update(campaignId, team.id, {
        name: name.trim(),
        description: description.trim() || null,
        team_type_id: teamTypeId === '' ? null : Number(teamTypeId),
      });
      onSaved('Team details updated.');
    } catch (e) {
      setError((e as { message?: string }).message ?? 'The team could not be updated.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Edit team details">
      <form onSubmit={save} className="space-y-4">
        {error && <ErrorBanner message={error} />}
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Name</label>
          <input
            value={name}
            onChange={(event) => setName(event.target.value)}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Team type</label>
          <select
            value={teamTypeId}
            onChange={(event) => setTeamTypeId(event.target.value ? Number(event.target.value) : '')}
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
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">
            Description (optional)
          </label>
          <textarea
            value={description}
            onChange={(event) => setDescription(event.target.value)}
            rows={3}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
          />
        </div>
        <div className="flex justify-end gap-2">
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
            {saving ? 'Saving…' : 'Save changes'}
          </button>
        </div>
      </form>
    </Modal>
  );
}

// ── Add member ───────────────────────────────────────────────────────────────

function AddMemberModal({
  open,
  onClose,
  campaignId,
  teamId,
  existingPersonIds,
  onSaved,
}: {
  open: boolean;
  onClose: () => void;
  campaignId: number;
  teamId: number;
  existingPersonIds: number[];
  onSaved: (message: string) => void;
}) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<{ id: number; full_name: string }[]>([]);
  const [searching, setSearching] = useState(false);
  const [selected, setSelected] = useState<{ id: number; full_name: string } | null>(null);
  const [role, setRole] = useState<'member' | 'deputy' | 'leader'>('member');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setQuery('');
    setSelected(null);
    setRole('member');
    setError('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  // Search the existing person reference used across the app rather than
  // inventing a second directory. Server-side search with a short debounce.
  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    setSearching(true);
    const timer = setTimeout(async () => {
      try {
        const people = await refApi.persons(query.trim() || undefined);
        if (!cancelled) {
          setResults(people.filter((person) => !existingPersonIds.includes(person.id)));
        }
      } catch {
        if (!cancelled) setResults([]);
      } finally {
        if (!cancelled) setSearching(false);
      }
    }, 300);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, query]);

  async function save(event: React.FormEvent) {
    event.preventDefault();
    if (!selected) {
      setError('Choose someone to add to the team.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      await teamsApi.addMember(campaignId, teamId, {
        person_id: selected.id,
        role_in_team: role,
      });
      onSaved(`${selected.full_name} was added to the team.`);
    } catch (e) {
      setError((e as { message?: string }).message ?? 'This person could not be added.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Add a team member">
      <form onSubmit={save} className="space-y-4">
        {error && <ErrorBanner message={error} />}

        {selected ? (
          <div className="flex items-center justify-between gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-2.5">
            <span className="text-sm text-emerald-200">{selected.full_name}</span>
            <button
              type="button"
              onClick={() => setSelected(null)}
              className="text-xs text-slate-300 hover:text-slate-100"
            >
              Change
            </button>
          </div>
        ) : (
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-1.5">Find a person</label>
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search by name…"
              className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
            <div className="mt-2 max-h-52 overflow-y-auto rounded-lg border border-surface-border divide-y divide-surface-border">
              {searching ? (
                <div className="p-3 text-xs text-slate-500">Searching…</div>
              ) : results.length === 0 ? (
                <div className="p-3 text-xs text-slate-500">No matching people found.</div>
              ) : (
                results.map((person) => (
                  <button
                    key={person.id}
                    type="button"
                    onClick={() => setSelected(person)}
                    className="w-full text-left px-3 py-2 text-sm text-slate-200 hover:bg-surface-2 transition-colors"
                  >
                    {person.full_name}
                  </button>
                ))
              )}
            </div>
          </div>
        )}

        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Role in team</label>
          <select
            value={role}
            onChange={(event) => setRole(event.target.value as 'member' | 'deputy' | 'leader')}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500"
          >
            <option value="member">Member</option>
            <option value="deputy">Deputy</option>
            <option value="leader">Leader</option>
          </select>
          <p className="text-xs text-slate-500 mt-1.5">
            Leadership is a membership role. Appointing a leader demotes the sitting leader.
          </p>
        </div>

        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            className="px-3.5 py-2 rounded-lg text-sm text-slate-300 hover:bg-surface-2"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={saving || !selected}
            className="px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-sm font-medium"
          >
            {saving ? 'Adding…' : 'Add to team'}
          </button>
        </div>
      </form>
    </Modal>
  );
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const RANK: Record<TeamMember['role_in_team'], number> = { leader: 0, deputy: 1, member: 2 };

function byRank(a: TeamMember, b: TeamMember): number {
  return RANK[a.role_in_team] - RANK[b.role_in_team];
}

function roleLabel(role: TeamMember['role_in_team']): string {
  return role.charAt(0).toUpperCase() + role.slice(1);
}

function initials(fullName: string): string {
  return fullName
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');
}
