'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import {
  activitiesApi,
  assignmentsApi,
  attendanceApi,
  outcomesApi,
  outreachContactsApi,
  reportsApi,
  teamsApi,
  type Activity,
  type Assignment,
  type AttendanceRegister,
  type FieldReport,
  type MissionOutcome,
  type OutreachContact,
  type Team,
} from '@/lib/api';
import { EmptyState, Skeleton } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import {
  Activity as ActivityIcon,
  CalendarClock,
  ClipboardCheck,
  ClipboardList,
  FileText,
  Heart,
  MapPinned,
  Target,
  Users,
  Users2,
} from 'lucide-react';

/**
 * Everything the overview reads, all scoped to the ACTIVE site.
 *
 * Each value comes from an existing, already-authorised endpoint — the overview
 * introduces no new backend surface and invents no metric. Where a module
 * cannot yet answer a question (site readiness), the page says so plainly
 * instead of fabricating a score.
 */
interface OverviewData {
  siteName: string;
  teams: Team[];
  assignments: Assignment[];
  activities: Activity[];
  attendance: AttendanceRegister[];
  reportsAwaitingReview: FieldReport[];
  outcomes: MissionOutcome[];
  contacts: OutreachContact[];
  /** team id → leader's full name, when one is appointed. */
  teamLeaders: Record<number, string>;
}

/** Today as a local `YYYY-MM-DD` string, matching the ISO dates the API returns. */
function today(): string {
  const now = new Date();
  const offset = now.getTimezoneOffset() * 60_000;
  return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}

/**
 * /site-operations — Site Coordinator overview.
 *
 * Answers the three operational questions for the active site:
 *   - What needs my attention?  (attendance to verify, reports to review,
 *     outcomes awaiting follow-up)
 *   - What is happening today?  (today's scheduled activities)
 *   - Is my site operating effectively?  (personnel, teams and their leaders)
 */
export default function SiteOperationsOverviewPage() {
  const { active } = useSiteOperations();

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  // `key` remounts the board when the coordinator switches site, so the data
  // can never linger from the site they were previously looking at.
  return (
    <OverviewBoard
      key={active.site_id}
      siteId={active.site_id}
      campaignId={active.campaign_id}
      siteName={active.site_name ?? 'Mission site'}
    />
  );
}

function OverviewBoard({
  siteId,
  campaignId,
  siteName,
}: {
  siteId: number;
  campaignId: number;
  siteName: string;
}) {
  const [data, setData] = useState<OverviewData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        setLoading(true);
        setError('');

        const [teams, assignments, activities, attendance, reports, outcomes, contacts] =
          await Promise.all([
            teamsApi.list(campaignId, { site_id: siteId }),
            assignmentsApi.list(campaignId, { site_id: siteId }),
            activitiesApi.list(campaignId, { site_id: siteId }),
            attendanceApi.list({ campaign_id: campaignId, mission_site_id: siteId }),
            reportsApi.list(campaignId, { site_id: siteId, pending_review: true }),
            outcomesApi.list(campaignId, { site_id: siteId }),
            outreachContactsApi.list({ campaign_id: campaignId, mission_site_id: siteId }),
          ]);

        if (cancelled) return;

        // Leaders are a property of a team's membership, so they are resolved
        // from each team's roster. A team without an appointed leader is left
        // out of the map and surfaced as "No leader appointed".
        const details = await Promise.all(
          teams.map((team) => teamsApi.get(campaignId, team.id).catch(() => null))
        );
        if (cancelled) return;

        const teamLeaders: Record<number, string> = {};
        for (const detail of details) {
          if (!detail) continue;
          const leader = detail.members?.find((member) => member.role_in_team === 'leader');
          if (leader) teamLeaders[detail.id] = leader.person.full_name;
        }

        setData({
          siteName,
          teams,
          assignments,
          activities,
          attendance: attendance.data ?? [],
          reportsAwaitingReview: reports,
          outcomes,
          contacts: contacts.data ?? [],
          teamLeaders,
        });
      } catch (e) {
        if (!cancelled) {
          setError((e as { message?: string }).message ?? 'Failed to load this site.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [campaignId, siteId, siteName]);

  if (loading) {
    return (
      <div className="p-6 max-w-5xl mx-auto space-y-4">
        <Skeleton className="h-8 w-64" />
        <div className="grid sm:grid-cols-2 gap-3">
          {[...Array(4)].map((_, i) => (
            <Skeleton key={i} className="h-24 rounded-xl" />
          ))}
        </div>
        <Skeleton className="h-56 rounded-xl" />
      </div>
    );
  }

  if (error || !data) {
    return (
      <div className="p-6 max-w-5xl mx-auto">
        <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
          {error || 'Failed to load this site.'}
        </div>
      </div>
    );
  }

  const now = today();
  const todayActivities = data.activities.filter(
    (activity) => activity.scheduled_at?.slice(0, 10) === now
  );
  const attendanceToVerify = data.attendance.filter(
    (register) => register.status === 'submitted'
  );
  const outcomesAwaitingFollowUp = data.outcomes.filter(
    (outcome) =>
      outcome.follow_up_status === 'pending' || outcome.follow_up_status === 'assigned'
  );

  // Each attention row is a real, actionable fact for THIS site. Rows with
  // nothing outstanding are omitted, so the list is genuinely "what needs me"
  // rather than a fixed set of decorative cards.
  const attention = [
    attendanceToVerify.length > 0 && {
      href: '/site-operations/attendance',
      icon: ClipboardCheck,
      tone: 'amber' as const,
      title: `${attendanceToVerify.length} attendance ${
        attendanceToVerify.length === 1 ? 'register' : 'registers'
      } awaiting verification`,
      body: 'Submitted registers are waiting for a site verification.',
    },
    data.reportsAwaitingReview.length > 0 && {
      href: '/site-operations/reports',
      icon: FileText,
      tone: 'blue' as const,
      title: `${data.reportsAwaitingReview.length} ${
        data.reportsAwaitingReview.length === 1 ? 'report' : 'reports'
      } awaiting review`,
      body: 'Reports forwarded to this site still need a review decision.',
    },
    outcomesAwaitingFollowUp.length > 0 && {
      href: '/site-operations/outcomes',
      icon: Heart,
      tone: 'rose' as const,
      title: `${outcomesAwaitingFollowUp.length} ${
        outcomesAwaitingFollowUp.length === 1 ? 'outcome' : 'outcomes'
      } awaiting follow-up`,
      body: 'Recorded outcomes still need someone to close the loop.',
    },
  ].filter(Boolean) as Array<{
    href: string;
    icon: typeof ClipboardCheck;
    tone: 'amber' | 'blue' | 'rose';
    title: string;
    body: string;
  }>;

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-8 animate-fade-up">
      {/* Header */}
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div className="flex items-center gap-2 text-emerald-300">
            <MapPinned className="w-4 h-4" />
            <span className="text-xs font-medium uppercase tracking-wider">Site Operations</span>
          </div>
          <h1 className="text-2xl font-bold text-slate-100 mt-1">{data.siteName}</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {data.assignments.length} personnel · {data.teams.length} teams ·{' '}
            {todayActivities.length} activities today
          </p>
        </div>
      </div>

      {/* What needs my attention */}
      <section className="space-y-3">
        <h2 className="section-label">What needs your attention</h2>
        {attention.length === 0 ? (
          <div className="card p-5 flex items-center gap-3">
            <div className="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0">
              <ClipboardList className="w-4 h-4 text-emerald-300" />
            </div>
            <p className="text-sm text-slate-300">
              Nothing is waiting on you at this site right now.
            </p>
          </div>
        ) : (
          <div className="grid sm:grid-cols-2 gap-3">
            {attention.map((item) => {
              const Icon = item.icon;
              const tone =
                item.tone === 'amber'
                  ? 'bg-amber-500/10 border-amber-500/20 text-amber-300'
                  : item.tone === 'blue'
                    ? 'bg-blue-500/10 border-blue-500/20 text-blue-300'
                    : 'bg-rose-500/10 border-rose-500/20 text-rose-300';
              return (
                <Link key={item.href} href={item.href} className="card p-4 flex items-start gap-3 hover:bg-surface-2 transition-colors">
                  <div className={`w-9 h-9 rounded-lg border flex items-center justify-center shrink-0 ${tone}`}>
                    <Icon className="w-4 h-4" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-100">{item.title}</p>
                    <p className="text-xs text-slate-500 mt-0.5">{item.body}</p>
                  </div>
                </Link>
              );
            })}
          </div>
        )}
      </section>

      {/* Today */}
      <section className="space-y-3">
        <h2 className="section-label flex items-center gap-2">
          <CalendarClock className="w-3.5 h-3.5" /> Today at this site
        </h2>
        {todayActivities.length === 0 ? (
          <EmptyState
            icon={<CalendarClock className="w-6 h-6" />}
            title="No activities scheduled today"
            body="Activities planned for this site will appear here on the day they are due."
          />
        ) : (
          <div className="space-y-2">
            {todayActivities.map((activity) => (
              <div key={activity.id} className="card p-4 flex items-center gap-3">
                <div className="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0">
                  <ActivityIcon className="w-4 h-4 text-emerald-300" />
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-slate-100 truncate">{activity.title}</p>
                  <p className="text-xs text-slate-500">
                    {activity.activity_type?.label ?? 'Activity'}
                    {activity.location_note ? ` · ${activity.location_note}` : ''}
                    {activity.estimated_attendance != null
                      ? ` · ~${activity.estimated_attendance} expected`
                      : ''}
                  </p>
                </div>
                <span className="text-xs text-slate-400 capitalize shrink-0">
                  {activity.status.replace('_', ' ')}
                </span>
              </div>
            ))}
          </div>
        )}
      </section>

      {/* Coverage */}
      <section className="space-y-3">
        <h2 className="section-label flex items-center gap-2">
          <Users2 className="w-3.5 h-3.5" /> Teams and coverage
        </h2>
        {data.teams.length === 0 ? (
          <EmptyState
            icon={<Users2 className="w-6 h-6" />}
            title="No teams at this site"
            body="Teams created for this mission site will appear here with their leader and size."
          />
        ) : (
          <div className="grid sm:grid-cols-2 gap-3">
            {data.teams.map((team) => (
              <div key={team.id} className="card p-4 space-y-2">
                <div className="flex items-center gap-2.5">
                  <div className="w-8 h-8 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center shrink-0">
                    <Users2 className="w-3.5 h-3.5 text-purple-300" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-100 truncate">{team.name}</p>
                    <p className="text-xs text-slate-500">
                      {team.team_type?.label ?? 'General team'} · {team.members_count ?? 0} members
                    </p>
                  </div>
                </div>
                <p className="text-xs text-slate-400">
                  {data.teamLeaders[team.id]
                    ? `Leader: ${data.teamLeaders[team.id]}`
                    : 'No leader appointed'}
                </p>
              </div>
            ))}
          </div>
        )}

        <div className="card p-4 flex items-center gap-3">
          <div className="w-9 h-9 rounded-lg bg-brand-600/10 border border-brand-600/20 flex items-center justify-center shrink-0">
            <Users className="w-4 h-4 text-brand-300" />
          </div>
          <div>
            <p className="text-sm font-medium text-slate-100">
              {data.assignments.length} personnel assigned
            </p>
            <p className="text-xs text-slate-500">
              {data.assignments.filter((a) => a.status === 'arrived').length} have arrived ·{' '}
              {data.assignments.filter((a) => a.status === 'confirmed').length} confirmed
            </p>
          </div>
        </div>
      </section>

      {/* Honest note: readiness is not computed yet. */}
      <section className="space-y-3">
        <h2 className="section-label flex items-center gap-2">
          <Target className="w-3.5 h-3.5" /> Site readiness
        </h2>
        <div className="card p-5 border-l-4 border-amber-500/60">
          <p className="text-sm text-slate-300">
            Site readiness scoring is not available yet. Operational coverage can be judged today
            from the personnel, teams and activities shown above; a single readiness figure will
            only be introduced once it can be derived from real logistics records.
          </p>
          <Link
            href="/site-operations/logistics"
            className="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-emerald-300 hover:text-emerald-200"
          >
            Open site readiness
          </Link>
        </div>
      </section>
    </div>
  );
}
