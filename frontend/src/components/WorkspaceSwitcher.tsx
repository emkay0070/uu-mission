'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useState } from 'react';
import {
  ChevronDown,
  Globe2,
  MapPinned,
  Megaphone,
  ShieldCheck,
  UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useAuthStore } from '@/store/auth';
import { availableWorkspaces } from '@/lib/workspaces';
import type { WorkspaceDescriptor, WorkspaceKey } from '@/lib/api';
import { cn } from './ui';

/**
 * Icon and accent for each workspace. Purely presentational — the workspace
 * itself is decided by the backend.
 */
const WORKSPACE_STYLE: Record<WorkspaceKey, { icon: LucideIcon; accent: string }> = {
  missionary: { icon: UserRound, accent: 'text-sky-300' },
  site_operations: { icon: MapPinned, accent: 'text-emerald-300' },
  campaign_management: { icon: Megaphone, accent: 'text-amber-300' },
  platform_administration: { icon: ShieldCheck, accent: 'text-violet-300' },
};

/**
 * WorkspaceSwitcher — lets a person move between the workspaces they hold.
 *
 * Renders nothing when fewer than two workspaces are available: with a single
 * workspace there is nothing to switch, and a control that cannot change
 * anything is noise.
 *
 * `pathname` is used to mark the workspace the user is currently inside, so
 * the switcher reflects reality instead of a stored preference.
 */
export function WorkspaceSwitcher({ className }: { className?: string }) {
  const { user } = useAuthStore();
  const router = useRouter();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);

  const workspaces = availableWorkspaces(user);

  if (workspaces.length < 2) return null;

  const current = workspaces.find((entry) => pathname.startsWith(entry.home)) ?? workspaces[0];
  const CurrentIcon = WORKSPACE_STYLE[current.key]?.icon ?? Globe2;

  function select(target: WorkspaceDescriptor) {
    setOpen(false);
    if (target.home !== pathname) router.push(target.home);
  }

  return (
    <div className={cn('relative', className)}>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="glass-button flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-white/10 transition-all"
        aria-haspopup="listbox"
        aria-expanded={open}
      >
        <CurrentIcon className={cn('w-4 h-4 shrink-0', WORKSPACE_STYLE[current.key]?.accent)} />
        <span className="flex-1 min-w-0">
          <span className="block text-[10px] uppercase tracking-wider text-slate-500">Workspace</span>
          <span className="block text-xs font-medium text-slate-200 truncate">{current.label}</span>
        </span>
        <ChevronDown className="w-3.5 h-3.5 text-slate-500 shrink-0" />
      </button>

      {open && (
        <div className="absolute bottom-full mb-2 left-0 right-0 min-w-[16rem] glass-card shadow-xl overflow-hidden z-50" role="listbox">
          {workspaces.map((entry) => {
            const Icon = WORKSPACE_STYLE[entry.key]?.icon ?? Globe2;
            const active = entry.key === current.key;
            return (
              <button
                key={entry.key}
                type="button"
                role="option"
                aria-selected={active}
                onClick={() => select(entry)}
                className={cn(
                  'w-full flex items-start gap-3 px-4 py-3 text-left transition-colors',
                  active ? 'bg-white/10' : 'hover:bg-white/5'
                )}
              >
                <Icon className={cn('mt-0.5 w-4 h-4 shrink-0', WORKSPACE_STYLE[entry.key]?.accent)} />
                <span className="min-w-0">
                  <span className="block text-sm font-medium text-slate-100">{entry.label}</span>
                  <span className="block text-[11px] text-slate-500 leading-snug">
                    {entry.description}
                  </span>
                </span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

/**
 * A compact, inline variant for shells that have no room for a full control.
 */
export function WorkspaceSwitcherLinks({ className }: { className?: string }) {
  const { user } = useAuthStore();
  const pathname = usePathname();

  const workspaces = availableWorkspaces(user);
  if (workspaces.length < 2) return null;

  return (
    <div className={cn('flex flex-wrap gap-1.5', className)}>
      {workspaces.map((entry) => {
        const Icon = WORKSPACE_STYLE[entry.key]?.icon ?? Globe2;
        const active = pathname.startsWith(entry.home);
        return (
          <Link
            key={entry.key}
            href={entry.home}
            title={entry.description}
            className={cn(
              'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium transition-colors',
              active
                ? 'bg-brand-600/25 text-brand-200 border border-brand-500/40'
                : 'text-slate-400 border border-white/10 hover:text-slate-200 hover:bg-white/5'
            )}
          >
            <Icon className="w-3 h-3" />
            {entry.label}
          </Link>
        );
      })}
    </div>
  );
}
