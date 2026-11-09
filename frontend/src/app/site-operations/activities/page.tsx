'use client';

import { useEffect, useState } from 'react';
import { activitiesApi, type Activity } from '@/lib/api';
import { EmptyState, Skeleton } from '@/components/ui';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import { Activity as ActivityIcon, CalendarClock } from 'lucide-react';

const STATUS_FILTERS = [
  { value: '', label: 'All' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
] as const;

/**
 * /site-operations/activities — planned and ad-hoc activities at the active site.
 *
 * Reads the existing campaign activities endpoint filtered by this site. It
 * shows the facts a coordinator needs (what, when, whether it was planned) and
 * links nothing it cannot back with data.
 */
export default function SiteActivitiesPage() {
  const { active } = useSiteOperations();

  if (!active || active.site_id == null || active.campaign_id == null) {
    return <NoSiteContext />;
  }

  return <ActivitiesBoard key={active.site_id} siteId={active.site_id} campaignId={active.campaign_id} />;
}

function ActivitiesBoard({ siteId, campaignId }: { siteId: number; campaignId: number }) {
  const [activities, setActivities] = useState<Activity[]>([]);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        setLoading(true);
        setError('');
        const list = await activitiesApi.list(campaignId, {
          site_id: siteId,
          status: status || undefined,
        });
        if (!cancelled) setActivities(list);
      } catch (e) {
        if (!cancelled) setError((e as { message?: string }).message ?? 'Failed to load activities.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [campaignId, siteId, status]);

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-xl font-bold text-slate-100">Activities</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            {activities.length} {activities.length === 1 ? 'activity' : 'activities'} at this site
          </p>
        </div>
        <div className="flex gap-2 flex-wrap">
          {STATUS_FILTERS.map((filter) => (
            <button
              key={filter.value}
              onClick={() => setStatus(filter.value)}
              className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${
                status === filter.value
                  ? 'bg-emerald-600 text-white'
                  : 'bg-surface-2 text-slate-400 border border-surface-border hover:text-slate-200'
              }`}
            >
              {filter.label}
            </button>
          ))}
        </div>
      </div>

      {error && (
        <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
          {error}
        </div>
      )}

      {loading ? (
        <div className="space-y-2">
          {[...Array(6)].map((_, i) => (
            <Skeleton key={i} className="h-16 rounded-xl" />
          ))}
        </div>
      ) : activities.length === 0 ? (
        <EmptyState
          icon={<ActivityIcon className="w-6 h-6" />}
          title="No activities"
          body="Activities scheduled for this site will appear here."
        />
      ) : (
        <div className="space-y-2">
          {activities.map((activity) => (
            <div key={activity.id} className="card p-4 flex items-center gap-3">
              <div className="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center shrink-0">
                <ActivityIcon className="w-4 h-4 text-emerald-300" />
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <p className="text-sm font-medium text-slate-100 truncate">{activity.title}</p>
                  <span className="text-[10px] uppercase tracking-wide text-slate-500 bg-surface-2 px-1.5 py-0.5 rounded">
                    {activity.is_planned ? 'Planned' : 'On the day'}
                  </span>
                </div>
                <p className="text-xs text-slate-500 mt-0.5">
                  {activity.activity_type?.label ?? 'Activity'}
                  {activity.scheduled_at
                    ? ` · ${new Date(activity.scheduled_at).toLocaleString()}`
                    : ''}
                  {activity.location_note ? ` · ${activity.location_note}` : ''}
                </p>
              </div>
              <span className="text-xs text-slate-400 capitalize shrink-0">
                {activity.status.replace('_', ' ')}
              </span>
            </div>
          ))}
        </div>
      )}

      <p className="text-xs text-slate-500 flex items-center gap-1.5">
        <CalendarClock className="w-3.5 h-3.5" />
        Activities are planned in the campaign workspace; attendance is captured in the field.
      </p>
    </div>
  );
}
