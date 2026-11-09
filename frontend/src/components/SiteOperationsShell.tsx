'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useState } from 'react';
import {
  Activity,
  ClipboardList,
  FileText,
  LayoutDashboard,
  LogOut,
  MapPinned,
  MapPin,
  Menu,
  Target,
  Users,
  UserRound,
  Users2,
  X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useAuthStore } from '@/store/auth';
import { WorkspaceSwitcher } from '@/components/WorkspaceSwitcher';
import { SiteOperationsProvider, useSiteOperations } from '@/components/site-operations/SiteOpsContext';
import { cn } from '@/components/ui';

/**
 * A navigation entry inside the Site Operations workspace.
 *
 * `permissions` lists the capabilities that reveal the entry FOR THE ACTIVE
 * SITE. An entry with no `permissions` is always shown. Visibility is an
 * affordance only — every module re-authorises its requests on the backend.
 */
type SiteNavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  permissions?: readonly string[];
};

const SITE_NAV: SiteNavItem[] = [
  { href: '/site-operations', label: 'Overview', icon: LayoutDashboard },
  {
    href: '/site-operations/people',
    label: 'People',
    icon: Users,
    permissions: ['people.view', 'outreach-contacts.view'],
  },
  {
    href: '/site-operations/teams',
    label: 'Teams',
    icon: Users2,
    permissions: ['assignments.view', 'assignments.manage', 'sites.view'],
  },
  {
    href: '/site-operations/activities',
    label: 'Activities',
    icon: Activity,
    permissions: ['activities.view', 'activities.create'],
  },
  {
    href: '/site-operations/attendance',
    label: 'Attendance',
    icon: ClipboardList,
    permissions: ['attendance.view', 'attendance.submit', 'attendance.verify'],
  },
  {
    href: '/site-operations/outreach',
    label: 'Outreach',
    icon: UserRound,
    permissions: ['outreach-contacts.view', 'outreach-contacts.create'],
  },
  {
    href: '/site-operations/reports',
    label: 'Reports',
    icon: FileText,
    permissions: ['report.view', 'report.review', 'report.submit'],
  },
  {
    href: '/site-operations/outcomes',
    label: 'Outcomes',
    icon: Target,
    permissions: ['outcomes.view', 'outcomes.record', 'outcomes.manage_followup'],
  },
  {
    href: '/site-operations/logistics',
    label: 'Site Readiness',
    icon: MapPin,
    permissions: ['sites.view'],
  },
];

/**
 * SiteOperationsShell — the dedicated Site Operations experience.
 *
 * Provides:
 *   - the Site Operations navigation, filtered by the capability the person
 *     actually holds FOR THE ACTIVE SITE (never a flat union);
 *   - a site selector whenever more than one site is authorised, so a person
 *     responsible for several sites cannot silently lose access to the others;
 *   - the workspace switcher, rendered by WorkspaceSwitcher only when more
 *     than one workspace is available.
 */
export function SiteOperationsShell({ children }: { children: React.ReactNode }) {
  return (
    <SiteOperationsProvider>
      <SiteOperationsChrome>{children}</SiteOperationsChrome>
    </SiteOperationsProvider>
  );
}

function SiteOperationsChrome({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const { user, logout } = useAuthStore();
  const { active, contexts, selectSite, canIn } = useSiteOperations();
  const [menuOpen, setMenuOpen] = useState(false);

  async function handleLogout() {
    await logout();
    router.replace('/login');
  }

  // Adaptive navigation for the ACTIVE site: an entry is shown only when the
  // person holds one of its capabilities in that site's scope.
  const nav = SITE_NAV.filter(
    (item) => !item.permissions || item.permissions.some((permission) => canIn(permission))
  );

  const isActive = (href: string) =>
    href === '/site-operations' ? pathname === href : pathname.startsWith(href);

  const sidebar = (
    <nav className="flex h-full flex-col">
      <div className="px-5 py-5 border-b border-surface-border">
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-lg bg-emerald-600/80 flex items-center justify-center">
            <MapPinned className="w-4 h-4 text-white" />
          </div>
          <div>
            <p className="text-sm font-bold text-slate-100 leading-none">Site Operations</p>
            <p className="text-[10px] text-slate-500 mt-0.5">
              {active?.site_name ?? 'No site assigned'}
            </p>
          </div>
        </div>

        {/* Multi-site operators must be able to reach every site they hold. */}
        {contexts.length > 1 && (
          <div className="mt-3">
            <label className="section-label px-1 pb-1 block">Mission site</label>
            <select
              value={active?.site_id ?? ''}
              onChange={(event) => selectSite(Number(event.target.value))}
              className="w-full rounded-lg bg-surface-2 border border-surface-border px-2.5 py-1.5 text-xs text-slate-200"
            >
              {contexts.map((context) => (
                <option key={context.site_id ?? context.label} value={context.site_id ?? ''}>
                  {context.label}
                  {context.campaign_name ? ` — ${context.campaign_name}` : ''}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>

      <div className="flex-1 overflow-y-auto p-3 space-y-0.5">
        {nav.map(({ href, label, icon: Icon }) => (
          <Link
            key={href}
            href={href}
            onClick={() => setMenuOpen(false)}
            className={cn(
              'flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm transition-all duration-150',
              isActive(href)
                ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-600/30'
                : 'text-slate-400 hover:bg-surface-2 hover:text-slate-200'
            )}
          >
            <Icon className="w-4 h-4 shrink-0" />
            {label}
          </Link>
        ))}
      </div>

      <div className="border-t border-surface-border p-3 space-y-2">
        <WorkspaceSwitcher />
        <div className="flex items-center gap-2.5 px-2 py-2">
          <div className="w-8 h-8 rounded-full bg-emerald-600/25 border border-emerald-600/40 flex items-center justify-center shrink-0">
            <UserRound className="w-4 h-4 text-emerald-300" />
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium text-slate-200 truncate">
              {user?.person?.full_name ?? user?.email}
            </p>
            <p className="text-[11px] text-slate-500 truncate">{user?.email}</p>
          </div>
          <button
            onClick={handleLogout}
            className="w-8 h-8 flex items-center justify-center rounded-lg text-slate-500 hover:text-red-400 hover:bg-red-500/10 transition-all"
            title="Sign out"
          >
            <LogOut className="w-3.5 h-3.5" />
          </button>
        </div>
      </div>
    </nav>
  );

  return (
    <div className="flex h-screen overflow-hidden bg-surface">
      {/* Desktop sidebar */}
      <aside className="hidden lg:flex w-64 shrink-0 border-r border-surface-border bg-surface-1">
        {sidebar}
      </aside>

      {/* Mobile drawer */}
      {menuOpen && (
        <div className="lg:hidden fixed inset-0 z-40 flex">
          <div className="w-64 bg-surface-1 border-r border-surface-border">{sidebar}</div>
          <button
            className="flex-1 bg-black/50"
            aria-label="Close navigation"
            onClick={() => setMenuOpen(false)}
          />
        </div>
      )}

      <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header className="lg:hidden flex items-center justify-between px-4 py-3 border-b border-surface-border bg-surface-1">
          <div className="flex items-center gap-2">
            <MapPinned className="w-4 h-4 text-emerald-300" />
            <span className="text-sm font-semibold text-slate-100">Site Operations</span>
          </div>
          <button
            onClick={() => setMenuOpen((value) => !value)}
            className="w-9 h-9 flex items-center justify-center rounded-lg text-slate-400 hover:bg-white/10"
            aria-label="Toggle navigation"
          >
            {menuOpen ? <X className="w-4 h-4" /> : <Menu className="w-4 h-4" />}
          </button>
        </header>

        <main className="flex-1 overflow-y-auto">{children}</main>
      </div>
    </div>
  );
}

/**
 * Shown when a person holds the Site Operations workspace but no site context
 * is available (for example every assignment has expired). Honest and
 * actionable rather than a fabricated dashboard.
 */
export function NoSiteContext() {
  return (
    <div className="flex items-center justify-center min-h-[60vh] px-6">
      <div className="glass-card max-w-md w-full p-8 text-center">
        <div className="mx-auto w-12 h-12 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center mb-4">
          <ClipboardList className="w-6 h-6 text-emerald-300" />
        </div>
        <h2 className="text-lg font-semibold text-slate-100">No site assigned</h2>
        <p className="mt-2 text-sm text-slate-400">
          You hold Site Operations responsibilities but no mission site is currently assigned to
          you. Ask your campaign administrator to review your appointment.
        </p>
      </div>
    </div>
  );
}
