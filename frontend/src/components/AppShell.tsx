'use client';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  LayoutDashboard, Globe, LogOut, Menu, X, ChevronRight,
  MapPin, Users, FileText, Target, User, Image as ImageIcon,
} from 'lucide-react';
import { useState } from 'react';
import { useAuthStore } from '@/store/auth';
import { cn } from './ui';

const NAV = [
  { href: '/admin/campaigns',  label: 'Campaigns',      icon: Globe },
  { href: '/admin/sites',      label: 'Mission Sites',  icon: MapPin },
  { href: '/admin/teams',      label: 'Teams',          icon: Users },
  { href: '/admin/reports',    label: 'Field Reports',  icon: FileText },
  { href: '/admin/outcomes',   label: 'Outcomes',       icon: Target },
  { href: '/admin/media',      label: 'Media',          icon: ImageIcon },
];

export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router   = useRouter();
  const { user, logout } = useAuthStore();
  const [open, setOpen] = useState(false);

  async function handleLogout() {
    await logout();
    router.replace('/login');
  }

  const Sidebar = (
    <nav className="flex flex-col h-full">
      {/* Logo */}
      <div className="px-5 py-5 border-b border-surface-border">
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-lg bg-brand-600 flex items-center justify-center">
            <Globe className="w-4 h-4 text-white" />
          </div>
          <div>
            <p className="text-sm font-bold text-slate-100 leading-none">UU Mission</p>
            <p className="text-[10px] text-slate-500 mt-0.5">Operations Platform</p>
          </div>
        </div>
      </div>

      {/* Nav links */}
      <div className="flex-1 overflow-y-auto p-3 space-y-0.5">
        <p className="section-label px-2 pt-2 pb-1 mb-1">Navigation</p>
        {NAV.map(({ href, label, icon: Icon }) => {
          const active = pathname.startsWith(href);
          return (
            <Link
              key={href}
              href={href}
              onClick={() => setOpen(false)}
              className={cn(
                'flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm transition-all duration-150',
                active
                  ? 'bg-brand-600/20 text-brand-300 border border-brand-600/30'
                  : 'text-slate-400 hover:bg-surface-2 hover:text-slate-200'
              )}
            >
              <Icon className="w-4 h-4 shrink-0" />
              {label}
              {active && <ChevronRight className="w-3 h-3 ml-auto text-brand-400" />}
            </Link>
          );
        })}
      </div>

      {/* User footer */}
      <div className="border-t border-surface-border p-3">
        <div className="flex items-center gap-2.5 px-2 py-2 rounded-lg">
          <div className="w-8 h-8 rounded-full bg-brand-600/30 border border-brand-600/40 flex items-center justify-center shrink-0">
            <User className="w-4 h-4 text-brand-300" />
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
    <div className="flex h-screen overflow-hidden">
      {/* Desktop sidebar */}
      <aside className="hidden lg:flex flex-col w-60 bg-surface-1 border-r border-surface-border shrink-0">
        {Sidebar}
      </aside>

      {/* Mobile drawer */}
      {open && (
        <div className="lg:hidden fixed inset-0 z-40 flex">
          <div className="absolute inset-0 bg-black/60" onClick={() => setOpen(false)} />
          <div className="relative z-10 w-60 bg-surface-1 border-r border-surface-border flex flex-col">
            {Sidebar}
          </div>
        </div>
      )}

      {/* Main content */}
      <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
        {/* Mobile top bar */}
        <header className="lg:hidden flex items-center gap-3 px-4 py-3 border-b border-surface-border bg-surface-1">
          <button
            onClick={() => setOpen(!open)}
            className="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-100"
          >
            {open ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
          </button>
          <div className="flex items-center gap-2">
            <Globe className="w-4 h-4 text-brand-400" />
            <span className="text-sm font-semibold text-slate-200">UU Mission</span>
          </div>
        </header>

        {/* Page scroll area */}
        <main className="flex-1 overflow-y-auto">
          {children}
        </main>
      </div>
    </div>
  );
}
