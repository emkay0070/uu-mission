'use client';

import Link from 'next/link';
import { useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { NoSiteContext } from '@/components/SiteOperationsShell';
import { AlertCircle } from 'lucide-react';

/**
 * /site-operations/logistics — Site Readiness.
 *
 * Site readiness is NOT yet computed anywhere in the platform: there are no
 * logistics records (venue, transport, accommodation, materials) to derive it
 * from, and the existing site detail page carries the same honest placeholder.
 * Rather than fabricate a readiness score, this page states plainly what is
 * missing and points to the operational signals that ARE real today.
 */
export default function SiteLogisticsPage() {
  const { active } = useSiteOperations();

  if (!active || active.site_id == null) {
    return <NoSiteContext />;
  }

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-6 animate-fade-up">
      <div>
        <h1 className="text-xl font-bold text-slate-100">Site readiness</h1>
        <p className="text-sm text-slate-400 mt-0.5">{active.site_name ?? 'Mission site'}</p>
      </div>

      <div className="card p-8 text-center">
        <div className="mx-auto w-12 h-12 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center mb-4">
          <AlertCircle className="w-6 h-6 text-amber-300" />
        </div>
        <h2 className="text-lg font-semibold text-slate-200">Readiness is not measured yet</h2>
        <p className="mt-2 text-sm text-slate-400 max-w-md mx-auto">
          No logistics records exist to base a readiness assessment on. Venue, transport,
          accommodation and materials tracking must be captured before a readiness figure can be
          shown — until then no score is displayed, because a number without records would be
          misleading.
        </p>

        <div className="mt-6 rounded-lg border border-surface-border bg-surface-2 p-4 text-left">
          <p className="section-label mb-2">What you can check today</p>
          <ul className="space-y-1.5 text-sm text-slate-300">
            <li>· Personnel assigned and arrived, from the Overview</li>
            <li>· Teams and whether each has a leader appointed, from Teams</li>
            <li>· Activities scheduled for the day, from Activities</li>
            <li>· Attendance awaiting verification, from Attendance</li>
          </ul>
        </div>

        <Link
          href="/site-operations"
          className="mt-6 inline-flex items-center gap-1.5 text-sm font-medium text-emerald-300 hover:text-emerald-200"
        >
          Return to overview
        </Link>
      </div>
    </div>
  );
}
