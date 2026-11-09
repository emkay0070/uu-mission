'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuthStore } from '@/store/auth';
import { workspaceApi, type MyAssignment } from '@/lib/api';
import { resourcesApi, communicationsApi } from '@/lib/api';
import { hasPermission, canAccessModule } from '@/lib/permissions';
import {
  Globe, Languages, MapPin, Users, BookOpen, FileText, ChevronRight, Calendar, User, AlertCircle,
  Map, Crown, Shield, Target, ClipboardList, Users2, Image, MessageSquare, Home, Play,
  Bookmark, Send, Eye, Clock, CheckCircle2, Inbox, Sparkles,
} from 'lucide-react';
import { StatusBadge } from '@/components/ui';
import GeneralUserHome from '@/components/GeneralUserHome';

type QuickAction = {
  href: string;
  label: string;
  description: string;
  icon: any;
  module?: string | null;
  visible: boolean;
};

export default function WorkspacePage() {
  const { user } = useAuthStore();
  const firstName = user?.person?.first_name ?? user?.email?.split('@')[0] ?? 'there';
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const today = new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });

  const [assignment, setAssignment] = useState<MyAssignment | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);

  const hasMissionaryProfile = user?.person?.missionary_profile !== null && user?.person?.missionary_profile !== undefined;

  // Activity data
  const [readingHistory, setReadingHistory] = useState<any[]>([]);
  const [savedCount, setSavedCount] = useState<number>(0);
  const [contributions, setContributions] = useState<any[]>([]);
  const [requiringAck, setRequiringAck] = useState<any[]>([]);
  const [unreadCount, setUnreadCount] = useState<number>(0);
  const [activityLoading, setActivityLoading] = useState<boolean>(false);

  useEffect(() => {
    async function fetchAssignment() {
      try {
        setLoading(true);
        setError(null);
        const response = await workspaceApi.myAssignment();
        setAssignment(response.assignment);
      } catch (e) {
        console.error('Failed to fetch assignment:', e);
        setError('Unable to load assignment data');
      } finally {
        setLoading(false);
      }
    }

    if (hasMissionaryProfile) {
      fetchAssignment();
    } else {
      setLoading(false);
    }
  }, [hasMissionaryProfile, refreshKey]);

  useEffect(() => {
    if (!hasMissionaryProfile) return;
    let cancelled = false;
    async function loadActivity() {
      setActivityLoading(true);
      const results = await Promise.allSettled([
        resourcesApi.progress.history({ recent: true, per_page: 5 }),
        resourcesApi.saved.list({ per_page: 1 }),
        resourcesApi.distributionRequests({ per_page: 50 }),
        communicationsApi.requiringAcknowledgement(),
        communicationsApi.unreadCount(),
      ]);
      if (cancelled) return;
      const [historyRes, savedRes, contribRes, ackRes, unreadRes] = results;
      if (historyRes.status === 'fulfilled') {
        const data = (historyRes.value as any)?.data ?? (historyRes.value as any) ?? [];
        setReadingHistory(Array.isArray(data) ? data : []);
      }
      if (savedRes.status === 'fulfilled') {
        const meta = (savedRes.value as any)?.meta ?? (savedRes.value as any);
        const total = meta?.total ?? (savedRes.value as any)?.total ?? 0;
        setSavedCount(typeof total === 'number' ? total : 0);
      }
      if (contribRes.status === 'fulfilled') {
        const all = (contribRes.value as any)?.data ?? [];
        const mine = Array.isArray(all)
          ? all.filter((r: any) => r.requested_by_user_id === user?.id)
          : [];
        setContributions(mine);
      }
      if (ackRes.status === 'fulfilled') {
        const data = (ackRes.value as any)?.data ?? [];
        setRequiringAck(Array.isArray(data) ? data : []);
      }
      if (unreadRes.status === 'fulfilled') {
        setUnreadCount((unreadRes.value as any)?.unread_count ?? 0);
      }
      setActivityLoading(false);
    }
    loadActivity();
    return () => { cancelled = true; };
  }, [hasMissionaryProfile, user?.id, refreshKey]);

  const hasAssignment = assignment !== null;

  if (!hasMissionaryProfile) {
    return <GeneralUserHome onComplete={() => setRefreshKey(prev => prev + 1)} />;
  }

  const canSee = (module: string | null) => (user ? canAccessModule(user, module as any) : true);

  const quickActions: QuickAction[] = [
    { href: '/resources', label: 'Resources', description: 'Discover and read', icon: BookOpen, visible: true },
    { href: '/translator', label: 'Translate', description: 'AI translation', icon: Languages, visible: true },
    { href: '/communication', label: 'Communication', description: 'Messages & notices', icon: MessageSquare, visible: true },
    { href: '/profile', label: 'Profile', description: 'Your account', icon: User, visible: true },
  ];

  const contributionCounts = contributions.reduce(
    (acc: any, r: any) => {
      const status = r.status;
      if (status === 'pending_review') acc.pending += 1;
      else if (status === 'returned') acc.returned += 1;
      else if (status === 'rejected') acc.rejected += 1;
      else if (status === 'approved') acc.approved += 1;
      return acc;
    },
    { pending: 0, returned: 0, rejected: 0, approved: 0 }
  );

  return (
    <div className="p-4 md:p-6 max-w-6xl mx-auto space-y-6 animate-fade-up">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-2">
        <div>
          <p className="text-slate-500 text-sm">{greeting} · {today}</p>
          <h1 className="text-2xl md:text-3xl font-bold text-slate-100 mt-0.5">
            {firstName}
          </h1>
          <p className="text-slate-400 text-sm mt-1">Your missionary workspace</p>
        </div>
        <Link
          href="/profile"
          className="inline-flex items-center gap-2 text-sm text-slate-300 hover:text-white transition-colors"
        >
          <User className="w-4 h-4" />
          View profile
        </Link>
      </div>

      {/* Attention / comms */}
      {(requiringAck.length > 0 || unreadCount > 0) && (
        <div className="glass-card p-4 md:p-5 border border-brand-500/30">
          <div className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-xl bg-brand-600/20 border border-brand-600/30 flex items-center justify-center shrink-0">
              <Inbox className="w-5 h-5 text-brand-400" />
            </div>
            <div className="flex-1 min-w-0">
              <h2 className="text-sm font-semibold text-slate-100">Needs your attention</h2>
              <div className="mt-1 flex flex-wrap gap-3 text-xs text-slate-400">
                {requiringAck.length > 0 && (
                  <span className="inline-flex items-center gap-1">
                    <AlertCircle className="w-3.5 h-3.5 text-amber-400" />
                    {requiringAck.length} message{requiringAck.length === 1 ? '' : 's'} require acknowledgement
                  </span>
                )}
                {unreadCount > 0 && (
                  <span className="inline-flex items-center gap-1">
                    <MessageSquare className="w-3.5 h-3.5 text-brand-400" />
                    {unreadCount} unread
                  </span>
                )}
              </div>
              <div className="mt-3 flex flex-wrap gap-2">
                <Link
                  href="/communication"
                  className="inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600 transition-colors"
                >
                  Open communication
                  <ChevronRight className="w-3.5 h-3.5" />
                </Link>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* My Mission */}
      {loading ? (
        <div className="glass-card p-6 md:p-8">
          <div className="flex items-center justify-center py-8">
            <div className="w-6 h-6 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
          </div>
        </div>
      ) : error ? (
        <div className="bg-red-500/10 backdrop-blur-xl border border-red-500/30 rounded-3xl p-6 md:p-8">
          <div className="flex items-start gap-3">
            <AlertCircle className="w-5 h-5 text-red-400 shrink-0 mt-0.5" />
            <div>
              <p className="text-sm text-red-400">{error}</p>
            </div>
          </div>
        </div>
      ) : !hasAssignment ? (
        <div className="glass-card p-6 md:p-8">
          <div className="flex flex-col items-center text-center gap-4 py-4">
            <div className="w-16 h-16 rounded-2xl glass-input flex items-center justify-center">
              <MapPin className="w-8 h-8 text-slate-500" />
            </div>
            <div>
              <h2 className="text-xl font-semibold text-slate-100 mb-2">No Mission Assignment Yet</h2>
              <p className="text-slate-400 text-sm max-w-md leading-relaxed">
                Your mission assignment is not yet available. Please contact your coordinator or authorised contact for information about your deployment.
              </p>
            </div>
          </div>
        </div>
      ) : (
        <div className="glass-card p-6 md:p-8">
          <div className="flex items-start gap-4 mb-6">
            <div className="w-12 h-12 rounded-2xl bg-brand-600/20 border border-brand-600/30 flex items-center justify-center shrink-0">
              <MapPin className="w-6 h-6 text-brand-400" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-slate-100">My Mission</h2>
              <p className="text-sm text-slate-500">Your current assignment</p>
            </div>
          </div>
          <div className="space-y-4">
            <div className="flex items-start gap-3">
              <Map className="w-4 h-4 text-brand-400 shrink-0 mt-0.5" />
              <div className="flex-1 min-w-0">
                <p className="text-xs text-slate-500 mb-0.5">Campaign</p>
                <p className="text-sm font-medium text-slate-100">{assignment!.campaign.name}</p>
                {assignment!.campaign.description && (
                  <p className="text-xs text-slate-500 mt-1 line-clamp-2">{assignment!.campaign.description}</p>
                )}
              </div>
              <StatusBadge status={assignment!.campaign.status} />
            </div>
            {assignment!.site && (
              <div className="flex items-start gap-3">
                <MapPin className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs text-slate-500 mb-0.5">Mission Site</p>
                  <p className="text-sm font-medium text-slate-100">{assignment!.site.name}</p>
                  {assignment!.site.location_description && (
                    <p className="text-xs text-slate-500 mt-1">{assignment!.site.location_description}</p>
                  )}
                </div>
                <StatusBadge status={assignment!.site.status} />
              </div>
            )}
            {assignment!.team && (
              <div className="flex items-start gap-3">
                <Users className="w-4 h-4 text-purple-400 shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs text-slate-500 mb-0.5">Team</p>
                  <p className="text-sm font-medium text-slate-100">{assignment!.team.name}</p>
                  {assignment!.team.team_type && (
                    <p className="text-xs text-slate-500 mt-1">{assignment!.team.team_type.label}</p>
                  )}
                </div>
              </div>
            )}
            {assignment!.functional_role && (
              <div className="flex items-start gap-3">
                <Shield className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs text-slate-500 mb-0.5">Role</p>
                  <p className="text-sm font-medium text-slate-100">{assignment!.functional_role.label}</p>
                </div>
              </div>
            )}
            {(assignment!.expected_arrival || assignment!.actual_arrival) && (
              <div className="flex items-start gap-3">
                <Calendar className="w-4 h-4 text-cyan-400 shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs text-slate-500 mb-0.5">Arrival</p>
                  {assignment!.actual_arrival ? (
                    <p className="text-sm text-slate-100">Arrived: {new Date(assignment!.actual_arrival).toLocaleDateString()}</p>
                  ) : assignment!.expected_arrival ? (
                    <p className="text-sm text-slate-100">Expected: {new Date(assignment!.expected_arrival).toLocaleDateString()}</p>
                  ) : null}
                </div>
                <StatusBadge status={assignment!.status} />
              </div>
            )}
          </div>
        </div>
      )}

      {/* Quick actions */}
      <section>
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-lg font-semibold text-slate-100">Quick actions</h2>
          <span className="text-xs text-slate-500">{quickActions.length} available</span>
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
          {quickActions.map((action) => {
            const Icon = action.icon;
            return (
              <Link
                key={action.href}
                href={action.href}
                className="glass-card p-4 hover:border-brand-500/40 transition-colors group"
              >
                <div className="flex items-center gap-3">
                  <div className="w-9 h-9 rounded-lg bg-brand-600/20 border border-brand-600/30 flex items-center justify-center shrink-0 group-hover:bg-brand-600/30 transition-colors">
                    <Icon className="w-4 h-4 text-brand-400" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-100 truncate">{action.label}</p>
                    <p className="text-xs text-slate-500 truncate">{action.description}</p>
                  </div>
                </div>
              </Link>
            );
          })}
        </div>
      </section>

      {/* Activity row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Reading progress */}
        <section className="glass-card p-5 md:p-6">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <BookOpen className="w-4 h-4 text-brand-400" />
              <h2 className="text-base font-semibold text-slate-100">Continue reading</h2>
            </div>
            <Link href="/resources/my-resources" className="text-xs text-brand-400 hover:text-brand-300 inline-flex items-center gap-1">
              My library <ChevronRight className="w-3.5 h-3.5" />
            </Link>
          </div>
          {activityLoading ? (
            <div className="flex items-center justify-center py-6">
              <div className="w-5 h-5 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
            </div>
          ) : readingHistory.length === 0 ? (
            <div className="text-center py-6">
              <BookOpen className="w-8 h-8 text-slate-600 mx-auto mb-2" />
              <p className="text-sm text-slate-400">No reading progress yet</p>
              <p className="text-xs text-slate-500 mt-1">Open a published resource to start reading. Drafts you read are reached from My Contributions.</p>
              <Link
                href="/resources"
                className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600 transition-colors"
              >
                Browse resources
              </Link>
            </div>
          ) : (
            <ul className="space-y-3">
              {readingHistory.slice(0, 5).map((item: any, idx: number) => {
                const resource = item.resource ?? item;
                const title = resource?.title ?? 'Untitled resource';
                const progress = item.completion_percentage ?? item.progress?.completion_percentage ?? 0;
                const id = resource?.id ?? item.resource_id;
                return (
                  <li key={id ?? idx} className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-lg bg-surface-3 flex items-center justify-center shrink-0">
                      <Play className="w-4 h-4 text-brand-400" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <Link href={id ? `/resources/reader/${id}` : '/resources'} className="text-sm font-medium text-slate-100 hover:text-brand-300 truncate block">
                        {title}
                      </Link>
                      <div className="mt-1 h-1.5 w-full rounded-full bg-surface-3 overflow-hidden">
                        <div
                          className="h-full rounded-full bg-brand-500 transition-all"
                          style={{ width: `${Math.min(100, Math.max(0, progress))}%` }}
                        />
                      </div>
                    </div>
                    <span className="text-xs text-slate-500 shrink-0">{Math.round(progress)}%</span>
                  </li>
                );
              })}
            </ul>
          )}
        </section>

        {/* Contributions & saved */}
        <section className="glass-card p-5 md:p-6">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <Send className="w-4 h-4 text-brand-400" />
              <h2 className="text-base font-semibold text-slate-100">My contributions</h2>
            </div>
            {hasPermission(user, 'resources.create') && (
              <Link href="/resources/my-resources" className="text-xs text-brand-400 hover:text-brand-300 inline-flex items-center gap-1">
                Manage <ChevronRight className="w-3.5 h-3.5" />
              </Link>
            )}
          </div>

          {!hasPermission(user, 'resources.create') ? (
            <div className="text-center py-6">
              <FileText className="w-8 h-8 text-slate-600 mx-auto mb-2" />
              <p className="text-sm text-slate-400">You don't have contributor access yet.</p>
              <p className="text-xs text-slate-500 mt-1">Ask an administrator to grant resources.create.</p>
            </div>
          ) : activityLoading ? (
            <div className="flex items-center justify-center py-6">
              <div className="w-5 h-5 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
            </div>
          ) : contributions.length === 0 ? (
            <div className="text-center py-6">
              <FileText className="w-8 h-8 text-slate-600 mx-auto mb-2" />
              <p className="text-sm text-slate-400">No contributions yet</p>
              <p className="text-xs text-slate-500 mt-1">Upload a resource to create a private draft.</p>
              <Link
                href="/resources/my-resources"
                className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600 transition-colors"
              >
                Create a draft
              </Link>
            </div>
          ) : (
            <div className="space-y-4">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <div className="rounded-lg bg-surface-3/60 p-3 text-center">
                  <p className="text-lg font-semibold text-slate-100">{contributionCounts.pending}</p>
                  <p className="text-[11px] text-slate-500">Pending</p>
                </div>
                <div className="rounded-lg bg-surface-3/60 p-3 text-center">
                  <p className="text-lg font-semibold text-slate-100">{contributionCounts.returned}</p>
                  <p className="text-[11px] text-slate-500">Changes</p>
                </div>
                <div className="rounded-lg bg-surface-3/60 p-3 text-center">
                  <p className="text-lg font-semibold text-slate-100">{contributionCounts.rejected}</p>
                  <p className="text-[11px] text-slate-500">Rejected</p>
                </div>
                <div className="rounded-lg bg-surface-3/60 p-3 text-center">
                  <p className="text-lg font-semibold text-slate-100">{contributionCounts.approved}</p>
                  <p className="text-[11px] text-slate-500">Approved</p>
                </div>
              </div>
              {contributionCounts.returned > 0 && (
                <div className="flex items-center gap-2 rounded-lg border border-amber-500/25 bg-amber-500/10 p-3 text-xs text-amber-200">
                  <AlertCircle className="w-4 h-4 shrink-0" />
                  You have {contributionCounts.returned} submission{contributionCounts.returned === 1 ? '' : 's'} needing changes.
                </div>
              )}
              <div className="flex items-center justify-between text-xs text-slate-400">
                <span className="inline-flex items-center gap-1.5">
                  <Bookmark className="w-3.5 h-3.5" />
                  {savedCount} saved resource{savedCount === 1 ? '' : 's'}
                </span>
                <Link href="/resources/store" className="text-brand-400 hover:text-brand-300 inline-flex items-center gap-1">
                  Open library <ChevronRight className="w-3.5 h-3.5" />
                </Link>
              </div>
            </div>
          )}
        </section>
      </div>

      {/* Footer hint */}
      <p className="text-center text-xs text-slate-600 pt-2">
        Backend-authoritative permissions apply to every action. Hidden modules are simply not available to your account.
      </p>
    </div>
  );
}
