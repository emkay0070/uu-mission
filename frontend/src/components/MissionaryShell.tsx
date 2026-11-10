'use client';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  Home, Languages, Users, MapPin, Users2,
  FileText, MessageSquare, LogOut, User, MoreHorizontal, Image, ShieldAlert, Target, ClipboardList,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useAuthStore } from '@/store/auth';
import { canAccessModule, moduleForPath, type WorkspaceModuleKey } from '@/lib/permissions';
import { WorkspaceSwitcherLinks } from '@/components/WorkspaceSwitcher';
import { cn } from './ui';

/**
 * A single navigation entry.
 *
 * `module` marks an operational module that is gated behind an authorised
 * assignment. Entries without a `module` are always available to any
 * authenticated missionary (Home, Translate, Media, Resources, Communication,
 * Profile). Visibility is resolved through `canAccessModule`; the backend
 * still authorises every request, so hiding an entry is a convenience only.
 */
type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  featured?: boolean;
  module?: WorkspaceModuleKey;
};

const MAIN_NAV: NavItem[] = [
  { href: '/dashboard', label: 'Home', icon: Home },
  { href: '/translator', label: 'Translate', icon: Languages, featured: true },
  { href: '/team', label: 'Team', icon: Users, module: 'team' },
];

const MORE_NAV: NavItem[] = [
  { href: '/fieldwork', label: 'Fieldwork', icon: MapPin, module: 'fieldwork' },
  { href: '/reports', label: 'Reports', icon: ClipboardList, module: 'reports' },
  { href: '/outcomes', label: 'Mission Outcomes', icon: Target, module: 'outcomes' },
  { href: '/people', label: 'Outreach Contacts', icon: Users2, module: 'people' },
  { href: '/media', label: 'Media', icon: Image },
  { href: '/resources', label: 'Resources', icon: FileText },
  { href: '/communication', label: 'Communication', icon: MessageSquare },
];

const PROFILE_NAV = [
  { label: 'Profile', icon: User, href: '/profile' as const },
  { label: 'Sign out', icon: LogOut, action: 'logout' as const },
];

export function MissionaryShell({ children, showHeader = true }: { children: React.ReactNode; showHeader?: boolean }) {
  const pathname = usePathname();
  const router = useRouter();
  const { user, logout } = useAuthStore();
  const [moreOpen, setMoreOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);

  // Dismiss any open mobile popover as soon as the route changes. This covers
  // navigation from the More drawer, the main bottom-nav items, browser
  // back/forward, and redirects — not just clicks on the drawer's own links.
  useEffect(() => {
    setMoreOpen(false);
    setProfileOpen(false);
  }, [pathname]);

  const closeMenus = () => {
    setMoreOpen(false);
    setProfileOpen(false);
  };

  async function handleLogout() {
    await logout();
    router.replace('/login');
  }

  // Adaptive navigation: only advertise modules the missionary is authorised
  // to use. Entries without a module (Home, Translate, Media, Resources,
  // Communication) are always shown. `user` may be null for a brief moment
  // before the auth store finishes initialising — during that window we keep
  // the full navigation rather than flash an access-denied state.
  const canSee = (item: NavItem) =>
    user ? canAccessModule(user, item.module ?? null) : true;

  const mainNav = MAIN_NAV.filter(canSee);
  const moreNav = MORE_NAV.filter(canSee);
  const allNavItems = [...mainNav, ...moreNav];
  const isActive = (href: string) => pathname.startsWith(href);

  // Page-level guard for direct navigation. Hiding a nav entry does not stop
  // someone typing the URL, so the shell refuses to render a gated module the
  // user cannot access — avoiding pointless API calls that would only 403.
  const activeModule = moduleForPath(pathname);
  const moduleAllowed = !user || canAccessModule(user, activeModule);

  return (
    <div className="flex h-screen overflow-hidden atmospheric-bg relative">
      {/* Atmospheric background effects */}
      <div className="fixed inset-0 pointer-events-none">
        <div className="absolute top-0 left-1/4 w-96 h-96 frost-glass bg-brand-500/10 rounded-full blur-3xl" />
        <div className="absolute bottom-1/4 right-1/4 w-80 h-80 frost-glass bg-brand-600/8 rounded-full blur-3xl" />
        <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] frost-glass bg-slate-800/20 rounded-full blur-3xl" />
      </div>

      {/* Main content */}
      <div className="relative z-10 flex-1 flex flex-col min-w-0 overflow-hidden">
        {/* Desktop header */}
      {showHeader && (
        <header className="hidden lg:flex items-center justify-end px-4 py-2 glass-nav">
          <div className="flex items-center gap-2 relative">
            <button
              onClick={() => setProfileOpen(!profileOpen)}
              className="glass-button flex items-center gap-2 px-3 py-1.5 hover:bg-white/10 transition-all"
            >
              <div className="w-7 h-7 rounded-full bg-brand-600/30 border border-brand-600/40 flex items-center justify-center shrink-0 overflow-hidden">
                {user?.person?.photo_path ? (
                  <img
                    src={user.person.photo_path}
                    alt={user.person.full_name || 'Profile'}
                    className="w-full h-full object-cover"
                  />
                ) : (
                  <User className="w-3.5 h-3.5 text-brand-300" />
                )}
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-xs font-medium text-slate-200 truncate">
                  {user?.person?.full_name ?? user?.email}
                </p>
              </div>
            </button>
            
            {/* Profile dropdown */}
            {profileOpen && (
              <div className="absolute top-full right-0 mt-2 w-48 glass-card shadow-xl overflow-hidden z-50">
                <div className="px-4 py-3 border-b border-white/10">
                  <p className="text-sm font-medium text-slate-100 truncate">
                    {user?.person?.full_name ?? user?.email}
                  </p>
                  <p className="text-xs text-slate-400 truncate">{user?.email}</p>
                </div>
                <button
                  onClick={handleLogout}
                  className="w-full flex items-center gap-3 px-4 py-3 text-sm text-red-400 hover:bg-red-500/10 transition-colors"
                >
                  <LogOut className="w-4 h-4" />
                  <span>Sign out</span>
                </button>
              </div>
            )}
          </div>
        </header>
      )}

        {/* Mobile header */}
        {showHeader && (
          <header className="lg:hidden relative z-30 flex items-center justify-end px-3 py-2 glass-nav">
            <div className="relative">
              <button
                onClick={() => setProfileOpen(!profileOpen)}
                className="glass-button w-8 h-8 flex items-center justify-center text-slate-400 rounded-full overflow-hidden"
              >
                {user?.person?.photo_path ? (
                  <img
                    src={user.person.photo_path}
                    alt={user.person.full_name || 'Profile'}
                    className="w-full h-full object-cover"
                  />
                ) : (
                  <User className="w-4 h-4" />
                )}
              </button>
              
              {/* Profile dropdown */}
              {profileOpen && (
                <div className="absolute top-full right-0 mt-2 w-48 glass-card shadow-xl overflow-hidden z-50">
                  <div className="px-4 py-3 border-b border-white/10">
                    <p className="text-sm font-medium text-slate-100 truncate">
                      {user?.person?.full_name ?? user?.email}
                    </p>
                    <p className="text-xs text-slate-400 truncate">{user?.email}</p>
                  </div>
                  <button
                    onClick={handleLogout}
                    className="w-full flex items-center gap-3 px-4 py-3 text-sm text-red-400 hover:bg-red-500/10 transition-colors"
                  >
                    <LogOut className="w-4 h-4" />
                    <span>Sign out</span>
                  </button>
                </div>
              )}
            </div>
        </header>
        )}

        {/* Workspace switcher.
            This workspace is rendered without a header (both layouts pass
            showHeader={false}), so the switcher sits at the top of the content
            column instead — always reachable, on every screen size. It renders
            nothing unless the backend advertised a second authorised
            workspace, so a single-workspace missionary sees no change. */}
        <WorkspaceSwitcherLinks className="px-4 py-3 lg:px-6" />

        {/* Page content */}
        <main className="flex-1 overflow-y-auto pb-20 lg:pb-0">
          {moduleAllowed ? children : <AccessDenied />}
        </main>
      </div>

      {/* Desktop right capsule nav */}
      <aside className="hidden lg:flex fixed right-8 top-1/2 -translate-y-1/2 flex-col items-center justify-center px-4 gap-2 glass-nav rounded-3xl shadow-2xl shrink-0 w-16 py-6 z-20">
        {allNavItems.map(({ href, label, icon: Icon, featured }) => {
          const active = isActive(href);
          return (
            <Link
              key={href}
              href={href}
              className={cn(
                'relative group flex flex-col items-center justify-center w-10 h-10 rounded-2xl transition-all duration-200',
                active
                  ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/50'
                  : featured
                  ? 'bg-brand-500/30 text-brand-200 hover:bg-brand-500/40'
                  : 'text-slate-400 hover:bg-white/10 hover:text-slate-200'
              )}
              title={label}
            >
              <Icon className="w-4 h-4" />
              {featured && (
                <span className="absolute -top-0.5 -right-0.5 w-1.5 h-1.5 bg-brand-400 rounded-full shadow-lg shadow-brand-400/50" />
              )}
            </Link>
          );
        })}
        
        {/* Divider */}
        <div className="w-8 h-px bg-white/10 my-1" />
        
        {/* Profile/Logout */}
        {PROFILE_NAV.map(({ label, icon: Icon, href, action }) => {
          if (href) {
            return (
              <Link
                key={href}
                href={href}
                className={cn(
                  'relative group flex flex-col items-center justify-center w-10 h-10 rounded-2xl transition-all duration-200 overflow-hidden',
                  isActive(href)
                    ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/50'
                    : 'text-slate-400 hover:bg-white/10 hover:text-slate-200'
                )}
                title={label}
              >
                {user?.person?.photo_path && href === '/profile' ? (
                  <img
                    src={user.person.photo_path}
                    alt={user.person.full_name || 'Profile'}
                    className="w-full h-full object-cover"
                  />
                ) : (
                  <Icon className="w-4 h-4" />
                )}
              </Link>
            );
          }
          return (
            <button
              key={action}
              onClick={action === 'logout' ? handleLogout : () => setProfileOpen(!profileOpen)}
              className={cn(
                'relative group flex flex-col items-center justify-center w-10 h-10 rounded-2xl transition-all duration-200',
                action === 'logout'
                  ? 'text-slate-400 hover:text-red-400 hover:bg-red-500/10'
                  : 'text-slate-400 hover:bg-white/10 hover:text-slate-200'
              )}
              title={label}
            >
              <Icon className="w-4 h-4" />
            </button>
          );
        })}
      </aside>

      {/* Mobile popover backdrop — tapping the page dismisses the More or
          Profile drawer before it can navigate anywhere else. */}
      {(moreOpen || profileOpen) && (
        <div
          className="lg:hidden fixed inset-0 z-10"
          aria-hidden="true"
          onClick={closeMenus}
        />
      )}

      {/* Mobile bottom nav */}
      <nav className="lg:hidden fixed bottom-0 left-0 right-0 glass-nav px-2 py-3 safe-area-bottom z-20">
        <div className="flex items-center justify-around">
          {mainNav.map(({ href, label, icon: Icon, featured }) => {
            const active = isActive(href);
            return (
              <Link
                key={href}
                href={href}
                onClick={closeMenus}
                className={cn(
                  'relative flex flex-col items-center justify-center w-14 h-14 rounded-2xl transition-all duration-200',
                  active
                    ? 'bg-brand-600/90 text-white'
                    : featured
                    ? 'bg-brand-600/20 text-brand-300'
                    : 'text-slate-500'
                )}
              >
                <Icon className="w-5 h-5" />
                {featured && (
                  <span className="absolute top-2 right-2 w-1.5 h-1.5 bg-brand-400 rounded-full shadow-lg shadow-brand-400/50" />
                )}
              </Link>
            );
          })}
          <button
            onClick={() => {
              setMoreOpen((open) => !open);
              setProfileOpen(false);
            }}
            aria-expanded={moreOpen}
            className={cn(
              'relative flex flex-col items-center justify-center w-14 h-14 rounded-2xl transition-all duration-200',
              moreOpen ? 'bg-brand-600/90 text-white' : 'text-slate-500'
            )}
          >
            <MoreHorizontal className="w-5 h-5" />
          </button>
          <button
            onClick={() => {
              setProfileOpen((open) => !open);
              setMoreOpen(false);
            }}
            aria-expanded={profileOpen}
            className={cn(
              'relative flex flex-col items-center justify-center w-14 h-14 rounded-2xl transition-all duration-200 overflow-hidden',
              profileOpen ? 'bg-brand-600/90 text-white' : 'text-slate-500'
            )}
          >
            {user?.person?.photo_path ? (
              <img
                src={user.person.photo_path}
                alt={user.person.full_name || 'Profile'}
                className="w-full h-full object-cover"
              />
            ) : (
              <User className="w-5 h-5" />
            )}
          </button>
        </div>

        {/* More menu */}
        {moreOpen && (
          <div className="absolute bottom-full left-0 right-0 mb-2 px-2">
            <div className="glass-card p-2 shadow-xl">
              {moreNav.map(({ href, label, icon: Icon }) => {
                const active = isActive(href);
                return (
                  <Link
                    key={href}
                    href={href}
                    onClick={closeMenus}
                    className={cn(
                      'flex items-center gap-3 px-4 py-3 rounded-2xl transition-all',
                      active
                        ? 'bg-brand-600/20 text-brand-300'
                        : 'text-slate-400 hover:bg-white/10 hover:text-slate-200'
                    )}
                  >
                    <Icon className="w-5 h-5" />
                    <span className="text-sm">{label}</span>
                  </Link>
                );
              })}
            </div>
          </div>
        )}

        {/* Profile menu */}
        {profileOpen && (
          <div className="absolute bottom-full right-2 mb-2 w-48">
            <div className="glass-card shadow-xl overflow-hidden">
              <div className="px-4 py-3 border-b border-white/10">
                <p className="text-sm font-medium text-slate-100 truncate">
                  {user?.person?.full_name ?? user?.email}
                </p>
                <p className="text-xs text-slate-400 truncate">{user?.email}</p>
              </div>
              <Link
                href="/profile"
                onClick={closeMenus}
                className="w-full flex items-center gap-3 px-4 py-3 text-sm text-slate-300 hover:bg-white/10 transition-colors"
              >
                <User className="w-4 h-4" />
                <span>Profile</span>
              </Link>
              <button
                onClick={handleLogout}
                className="w-full flex items-center gap-3 px-4 py-3 text-sm text-red-400 hover:bg-red-500/10 transition-colors"
              >
                <LogOut className="w-4 h-4" />
                <span>Sign out</span>
              </button>
            </div>
          </div>
        )}
      </nav>
    </div>
  );
}

/**
 * Shown when a missionary navigates directly to an operational module they are
 * not authorised for. This mirrors the backend's 403 without firing a request
 * that would only fail, and keeps the workspace honest about what is available.
 */
function AccessDenied() {
  return (
    <div className="flex items-center justify-center min-h-[60vh] px-6">
      <div className="glass-card max-w-sm w-full p-8 text-center">
        <div className="mx-auto w-12 h-12 rounded-2xl bg-amber-500/15 border border-amber-500/30 flex items-center justify-center mb-4">
          <ShieldAlert className="w-6 h-6 text-amber-300" />
        </div>
        <h2 className="text-lg font-semibold text-slate-100">Not available</h2>
        <p className="mt-2 text-sm text-slate-400">
          This module is not part of your current mission assignment. If you
          believe you should have access, ask your mission administrator to
          review your appointment.
        </p>
        <Link
          href="/dashboard"
          className="mt-6 inline-flex items-center justify-center px-4 py-2 rounded-2xl text-sm font-medium bg-brand-600 text-white hover:bg-brand-500 transition-colors"
        >
          Back to Home
        </Link>
      </div>
    </div>
  );
}
