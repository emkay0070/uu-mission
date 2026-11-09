'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  Bell,
  CheckCircle,
  ChevronRight,
  Clock,
  MessageSquare,
  Plus,
  User,
  X,
} from 'lucide-react';
import { communicationsApi, type Communication } from '@/lib/api';
import { hasPermission } from '@/lib/permissions';
import { useAuthStore } from '@/store/auth';
import {
  EmptyState,
  ErrorBanner,
  Modal,
  Spinner,
  StatCard,
  StatusBadge,
  cn,
} from '@/components/ui';

type Filter = 'all' | 'unread' | 'action';

const TYPE_LABEL: Record<string, { label: string; color: string }> = {
  announcement: { label: 'Announcement', color: '#3b82f6' },
  organisational_update: { label: 'Organisational update', color: '#10b981' },
  instruction: { label: 'Instruction', color: '#f59e0b' },
};

export default function CommunicationPage() {
  const { user } = useAuthStore();
  const canPublish = hasPermission(user, 'communications.publish');

  const [communications, setCommunications] = useState<Communication[]>([]);
  const [meta, setMeta] = useState<{ unreadCount: number; total: number } | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<Filter>('all');
  const [selected, setSelected] = useState<Communication | null>(null);
  const [publishOpen, setPublishOpen] = useState(false);
  const [publishing, setPublishing] = useState(false);

  // Publish target options derived from the user's own held scopes. The
  // backend still enforces publishing authority for the chosen scope.
  const publishTargets = useMemo(() => {
    const opts: Array<{
      label: string;
      scope_type: Communication['scope_type'];
      scope_id: number;
    }> = [];
    const seen = new Set<string>();
    for (const s of user?.scopes ?? []) {
      if (s.scope_type === 'global') continue;
      const id = (s as unknown as Record<string, unknown>)[
        {
          org_unit: 'scoped_org_unit_id',
          campaign: 'scoped_campaign_id',
          site: 'scoped_site_id',
          team: 'scoped_team_id',
        }[s.scope_type]
      ] as number | null;
      if (!id) continue;
      const key = `${s.scope_type}:${id}`;
      if (seen.has(key)) continue;
      seen.add(key);
      opts.push({
        label: `${s.scope_type.replace(/_/g, ' ')} #${id}`,
        scope_type: s.scope_type,
        scope_id: id,
      });
    }
    return opts;
  }, [user?.scopes]);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [list, unread] = await Promise.all([
        communicationsApi.list({ per_page: 50 }),
        communicationsApi.unreadCount(),
      ]);
      setCommunications(list.data);
      setMeta({
        unreadCount: unread.unread_count,
        total: list.meta.total,
      });
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Failed to load communications.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const filtered = useMemo(() => {
    return communications.filter((c) => {
      if (filter === 'unread') return c.is_unread;
      if (filter === 'action') return c.requires_acknowledgement && !c.is_acknowledged;
      return true;
    });
  }, [communications, filter]);

  const handleRead = async (id: number) => {
    try {
      await communicationsApi.read(id);
      setCommunications((comms) =>
        comms.map((c) =>
          c.id === id ? { ...c, is_unread: false, read_at: new Date().toISOString() } : c
        )
      );
      setMeta((m) => (m ? { ...m, unreadCount: Math.max(0, m.unreadCount - 1) } : m));
    } catch {
      // Read failures are non-fatal.
    }
  };

  const handleAcknowledge = async (id: number) => {
    try {
      const res = await communicationsApi.acknowledge(id);
      setCommunications((comms) =>
        comms.map((c) =>
          c.id === id
            ? { ...c, is_acknowledged: true, acknowledged_at: res.acknowledged_at }
            : c
        )
      );
      setSelected((s) =>
        s && s.id === id ? { ...s, is_acknowledged: true, acknowledged_at: res.acknowledged_at } : s
      );
    } catch (e) {
      setError((e as { message?: string }).message ?? 'Unable to acknowledge this communication.');
    }
  };

  const openCommunication = async (comm: Communication) => {
    if (comm.is_unread) {
      await handleRead(comm.id);
    }
    setSelected(comm);
  };

  const handlePublish = (data: {
    title: string;
    body: string;
    communication_type: string;
    scope_type: string;
    target_id: number | null;
    requires_acknowledgement: boolean;
    status: 'draft' | 'published';
  }) => {
    setPublishing(true);
    setError(null);
    const payload: Record<string, unknown> = {
      title: data.title,
      body: data.body,
      communication_type: data.communication_type,
      scope_type: data.scope_type,
      requires_acknowledgement: data.requires_acknowledgement,
      status: data.status,
    };
    if (data.scope_type !== 'global' && data.target_id) {
      const field = {
        org_unit: 'scoped_org_unit_id',
        campaign: 'scoped_campaign_id',
        site: 'scoped_site_id',
        team: 'scoped_team_id',
      }[data.scope_type];
      if (field) payload[field] = data.target_id;
    }
    communicationsApi
      .create(payload as any)
      .then(() => {
        setPublishOpen(false);
        setPublishing(false);
        return fetchData();
      })
      .catch((e) => {
        setPublishing(false);
        setError((e as { message?: string }).message ?? 'Failed to publish communication.');
      });
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-surface p-6">
        <div className="max-w-4xl mx-auto">
          <h1 className="text-3xl font-bold text-slate-100 mb-2">Communication Centre</h1>
          <p className="text-slate-400">Your official mission communications.</p>
          <div className="mt-8 flex items-center justify-center py-20">
            <Spinner size={8} />
          </div>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-surface p-6">
        <div className="max-w-4xl mx-auto">
          <h1 className="text-3xl font-bold text-slate-100 mb-2">Communication Centre</h1>
          <p className="text-slate-400">Your official mission communications.</p>
          <ErrorBanner message={error} />
        </div>
      </div>
    );
  }

  const unreadCount = meta?.unreadCount ?? 0;
  const actionNeeded = communications.filter(
    (c) => c.requires_acknowledgement && !c.is_acknowledged
  ).length;

  return (
    <div className="min-h-screen bg-surface p-6">
      <div className="max-w-4xl mx-auto">
        <div className="mb-8">
          <div className="flex items-center gap-2 mb-2">
            <div className="w-10 h-10 rounded-xl bg-brand-500/20 flex items-center justify-center">
              <MessageSquare className="w-6 h-6 text-brand-400" />
            </div>
            <div>
              <h1 className="text-3xl font-bold text-slate-100">Communication Centre</h1>
              <p className="text-slate-400">Your official mission communications.</p>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
            <StatCard
              label="Total communications"
              value={meta?.total ?? 0}
              sub={meta ? `${meta.total} published communications visible to you` : undefined}
            />
            <StatCard
              label="Unread"
              value={unreadCount}
              sub={
                unreadCount > 0
                  ? 'Communications you have not yet read'
                  : 'All caught up'
              }
              icon={<Bell className="w-5 h-5" />}
            />
            <StatCard
              label="Action needed"
              value={actionNeeded}
              sub={
                actionNeeded > 0
                  ? 'Instructions awaiting your acknowledgement'
                  : 'No pending instructions'
              }
              icon={<AlertTriangle className="w-5 h-5" />}
            />
          </div>

          {canPublish && (
            <div className="flex justify-end mt-6">
              <button
                onClick={() => setPublishOpen(true)}
                className="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium bg-brand-600 text-white hover:bg-brand-500 transition-colors"
              >
                <Plus className="w-4 h-4" /> New communication
              </button>
            </div>
          )}
        </div>

        <div className="flex gap-2 mb-6">
          <button
            onClick={() => setFilter('all')}
            className={cn(
              'px-4 py-2 rounded-xl text-sm font-medium transition-colors',
              filter === 'all'
                ? 'bg-brand-600 text-white'
                : 'bg-surface-2 border border-surface-border text-slate-300 hover:text-slate-100'
            )}
          >
            All {communications.length > 0 && (
              <span className="text-xs bg-white/10 px-2 py-0.5 rounded">
                {communications.length}
              </span>
            )}
          </button>
          <button
            onClick={() => setFilter('unread')}
            className={cn(
              'px-4 py-2 rounded-xl text-sm font-medium transition-colors',
              filter === 'unread'
                ? 'bg-brand-600 text-white'
                : 'bg-surface-2 border border-surface-border text-slate-300 hover:text-slate-100'
            )}
          >
            Unread {communications.filter((c) => c.is_unread).length > 0 && (
              <span className="text-xs bg-white/10 px-2 py-0.5 rounded">
                {communications.filter((c) => c.is_unread).length}
              </span>
            )}
          </button>
          <button
            onClick={() => setFilter('action')}
            className={cn(
              'px-4 py-2 rounded-xl text-sm font-medium transition-colors',
              filter === 'action'
                ? 'bg-brand-600 text-white'
                : 'bg-surface-2 border border-surface-border text-slate-300 hover:text-slate-100'
            )}
          >
            Action needed {actionNeeded > 0 && (
              <span className="text-xs bg-white/10 px-2 py-0.5 rounded">
                {actionNeeded}
              </span>
            )}
          </button>
        </div>

        {error && <ErrorBanner message={error} />}

        <div className="space-y-3">
          {filtered.length === 0 ? (
            <EmptyState
              icon={
                filter === 'unread'
                  ? <CheckCircle className="w-8 h-8 text-slate-500" />
                  : filter === 'action'
                    ? <AlertTriangle className="w-8 h-8 text-slate-500" />
                    : <MessageSquare className="w-8 h-8 text-slate-500" />
              }
              title={
                filter === 'all'
                  ? 'No communications yet'
                  : filter === 'unread'
                    ? 'No unread communications'
                    : 'No instructions awaiting acknowledgement'
              }
              body={
                filter === 'all'
                  ? 'Published communications addressed to you will appear here.'
                  : filter === 'unread'
                    ? 'You have read all visible communications.'
                    : 'You have acknowledged all pending instructions.'
              }
            />
          ) : (
            filtered.map((comm) => (
              <div
                key={comm.id}
                onClick={() => openCommunication(comm)}
                className={cn(
                  'group cursor-pointer bg-surface-2 border border-surface-border rounded-xl p-4 transition-colors',
                  comm.is_unread
                    ? 'border-brand-500/30 bg-brand-500/5'
                    : 'hover:bg-surface-3 hover:border-white/10'
                )}
              >
                <div className="flex items-start justify-between gap-3 mb-2">
                  <div className="flex items-center gap-2">
                    <div
                      className="w-6 h-6 rounded-lg flex items-center justify-center text-xs font-medium"
                      style={{ backgroundColor: TYPE_LABEL[comm.communication_type].color }}
                    >
                      {TYPE_LABEL[comm.communication_type].label}
                    </div>
                    {comm.requires_acknowledgement && !comm.is_acknowledged && (
                      <AlertTriangle className="w-4 h-4 text-amber-400 ml-1" />
                    )}
                  </div>
                  <div className="flex items-center gap-1 text-xs text-slate-500">
                    {comm.is_unread ? (
                      <div className="w-2 h-2 rounded-full bg-brand-400" />
                    ) : (
                      <CheckCircle className="w-3 h-3 text-slate-500" />
                    )}
                    <span>{comm.is_unread ? 'Unread' : 'Read'}</span>
                  </div>
                </div>
                <h3 className="font-semibold text-slate-100 mb-1">{comm.title}</h3>
                <p className="text-sm text-slate-300 mb-3 line-clamp-2">{comm.body}</p>
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                  <div className="flex items-center gap-2 text-xs text-slate-500">
                    <User className="w-3 h-3" />
                    {comm.author?.name ?? comm.author?.email ?? '—'}
                  </div>
                  <div className="flex items-center gap-2 text-xs text-slate-500">
                    <Clock className="w-3 h-3" />
                    {' '}
                    {comm.published_at
                      ? new Date(comm.published_at).toLocaleDateString()
                      : '—'}
                  </div>
                  <ChevronRight className="w-4 h-4 text-slate-500 group-hover:text-slate-300 transition-colors" />
                </div>
                <div className="mt-3 flex items-center gap-4 text-xs font-medium text-slate-400">
                  <span className="flex items-center gap-1">
                    <span
                      className="w-3 h-3 rounded-full"
                      style={{ backgroundColor: TYPE_LABEL[comm.communication_type].color }}
                    />
                    {TYPE_LABEL[comm.communication_type].label}
                  </span>
                  <span>•</span>
                  <span>{comm.scope_label}</span>
                </div>
              </div>
            ))
          )}
        </div>
      </div>

      {selected && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
          <button
            onClick={() => setSelected(null)}
            className="absolute top-4 left-4 z-[70] text-slate-400 hover:text-slate-200"
            aria-label="Close"
          >
            <X className="w-6 h-6" />
          </button>
          <div className="relative w-full max-w-2xl bg-surface border border-white/10 rounded-2xl p-6 shadow-2xl animate-fade-up max-h-[90vh] overflow-y-auto">
            <div className="flex items-start justify-between mb-4">
              <div className="flex items-center gap-2 mb-1">
                <div
                  className="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-medium"
                  style={{ backgroundColor: TYPE_LABEL[selected.communication_type].color }}
                >
                  {TYPE_LABEL[selected.communication_type].label}
                </div>
                {selected.requires_acknowledgement && !selected.is_acknowledged && (
                  <AlertTriangle className="w-4 h-4 text-amber-400 ml-1" />
                )}
              </div>
              <div>
                <h2 className="text-xl font-bold text-slate-100">{selected.title}</h2>
                <p className="text-sm text-slate-400 mt-1">{selected.scope_label}</p>
              </div>
            </div>
            <div className="whitespace-pre-line text-slate-300 mb-6">
              {selected.body}
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs text-slate-500 mb-6">
              <div className="flex items-center gap-2">
                <User className="w-4 h-4" />
                <span>By {selected.author?.name ?? selected.author?.email ?? '—'}</span>
              </div>
              <div className="flex items-center gap-2">
                <Clock className="w-4 h-4" />
                {' '}
                <span>
                  Published{' '}
                  {selected.published_at
                    ? new Date(selected.published_at).toLocaleString()
                    : '—'}
                </span>
              </div>
              {selected.expires_at && (
                <div className="flex items-center gap-2">
                  <Clock className="w-4 h-4" />
                  <span>
                    Expires{new Date(selected.expires_at).toLocaleDateString()}
                  </span>
                </div>
              )}
            </div>
            <div className="pt-4 border-t border-white/10 flex items-center gap-3">
              {selected.requires_acknowledgement && !selected.is_acknowledged && (
                <button
                  onClick={() => handleAcknowledge(selected.id)}
                  disabled={selected.is_acknowledged}
                  className="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium bg-amber-600 text-white hover:bg-amber-500 disabled:opacity-50 transition-colors"
                >
                  <AlertTriangle className="w-4 h-4" /> Acknowledge instruction
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      <Modal
        open={publishOpen}
        onClose={() => setPublishOpen(false)}
        title="New communication"
      >
        <div className="space-y-4">
          <div>
            <label className="block text-xs font-medium text-slate-400 mb-1">Title</label>
            <input
              id="comm-title"
              className="w-full bg-surface-2 border border-surface-border rounded-xl px-3 py-2 text-slate-100 focus:ring-2 focus:ring-brand-500 outline-none"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-400 mb-1">Body</label>
            <textarea
              id="comm-body"
              rows={4}
              className="w-full bg-surface-2 border border-surface-border rounded-xl px-3 py-2 text-slate-100 focus:ring-2 focus:ring-brand-500 outline-none"
            />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1">Type</label>
              <select
                id="comm-type"
                className="w-full bg-surface-2 border border-surface-border rounded-xl px-3 py-2 text-slate-100 focus:ring-2 focus:ring-brand-500 outline-none"
              >
                <option value="announcement">Announcement</option>
                <option value="organisational_update">Organisational update</option>
                <option value="instruction">Instruction</option>
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1">
                Target scope
              </label>
              <select
                id="comm-scope"
                className="w-full bg-surface-2 border border-surface-border rounded-xl px-3 py-2 text-slate-100 focus:ring-2 focus:ring-brand-500 outline-none"
              >
                <option value="global">Global mission</option>
                {publishTargets.map((t) => (
                  <option key={`${t.scope_type}:${t.scope_id}`} value={t.scope_type}>
                    {t.label}
                  </option>
                ))}
              </select>
              <p className="text-xs text-slate-500 mt-1">
                Publishing is authorised by the backend for scopes you hold
                authority over.
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <input id="comm-ack" type="checkbox" className="w-4 h-4 rounded border-surface-border text-brand-600 focus:ring-brand-500" />
            <label className="text-sm text-slate-300">Require acknowledgement</label>
          </div>
          <div className="flex items-center gap-2">
            <input id="comm-draft" type="checkbox" className="w-4 h-4 rounded border-surface-border text-brand-600 focus:ring-brand-500" />
            <label className="text-sm text-slate-300">Save as draft (not visible to recipients)</label>
          </div>
          <div className="flex justify-end gap-3 pt-4">
            <button
              onClick={() => setPublishOpen(false)}
              className="px-4 py-2 rounded-xl text-sm font-medium text-slate-300 hover:text-slate-100"
            >
              Cancel
            </button>
            <button
              onClick={() => {
                const title = (document.getElementById('comm-title') as HTMLInputElement)?.value.trim();
                const body = (document.getElementById('comm-body') as HTMLTextAreaElement)?.value.trim();
                const type = (document.getElementById('comm-type') as HTMLSelectElement)?.value;
                const scope = (document.getElementById('comm-scope') as HTMLSelectElement)?.value;
                const ack = (document.getElementById('comm-ack') as HTMLInputElement)?.checked;
                const draft = (document.getElementById('comm-draft') as HTMLInputElement)?.checked;
                const target = publishTargets.find((t) => t.scope_type === scope);
                if (!title || !body) return;
                handlePublish({
                  title,
                  body,
                  communication_type: type as any,
                  scope_type: scope as any,
                  target_id: scope === 'global' ? null : target?.scope_id ?? null,
                  requires_acknowledgement: ack,
                  status: draft ? 'draft' : 'published',
                });
              }}
              disabled={publishing}
              className="px-4 py-2 rounded-xl text-sm font-medium bg-brand-600 text-white hover:bg-brand-500 disabled:opacity-50 transition-colors"
            >
              {publishing ? (
                <div className="mr-2">
                  <Spinner size={4} />
                </div>
              ) : ''} {publishing ? 'Publishing…' : 'Publish'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
