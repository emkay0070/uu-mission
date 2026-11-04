'use client';
import { type ReactNode } from 'react';
import { twMerge } from 'tailwind-merge';
import { clsx } from 'clsx';
import { X } from 'lucide-react';
import { createPortal } from 'react-dom';

export function cn(...inputs: (string | undefined | null | false)[]) {
  return twMerge(clsx(inputs));
}

// ── Skeleton ──────────────────────────────────────────────────────────────────
export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('skeleton', className)} />;
}

// ── Status Badge ──────────────────────────────────────────────────────────────
const STATUS_MAP: Record<string, string> = {
  planning:  'badge-planning',
  active:    'badge-active',
  completed: 'badge-completed',
  archived:  'badge-archived',
  draft:     'badge-draft',
  submitted: 'badge-submitted',
  reviewed:  'badge-reviewed',
  approved:  'badge-approved',
  confirmed: 'badge-confirmed',
  arrived:   'badge-arrived',
  pending:   'badge-pending',
};

export function StatusBadge({ status }: { status: string }) {
  const cls = STATUS_MAP[status] ?? 'badge bg-slate-500/20 text-slate-300';
  return <span className={cls}>{status.replace('_', ' ')}</span>;
}

// ── Empty State ───────────────────────────────────────────────────────────────
export function EmptyState({ icon, title, body }: { icon: ReactNode; title: string; body?: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-20 gap-3 text-center">
      <div className="w-14 h-14 rounded-2xl bg-surface-2 border border-surface-border flex items-center justify-center text-slate-500">
        {icon}
      </div>
      <p className="font-semibold text-slate-300">{title}</p>
      {body && <p className="text-sm text-slate-500 max-w-xs">{body}</p>}
    </div>
  );
}

// ── Error Banner ──────────────────────────────────────────────────────────────
export function ErrorBanner({ message }: { message: string }) {
  return (
    <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
      {message}
    </div>
  );
}

// ── Spinner ────────────────────────────────────────────────────────────────────
export function Spinner({ size = 5 }: { size?: number }) {
  return (
    <div
      className={`w-${size} h-${size} border-2 border-brand-500 border-t-transparent rounded-full animate-spin`}
    />
  );
}

// ── Modal ─────────────────────────────────────────────────────────────────────
export function Modal({
  open, onClose, title, children,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
}) {
  if (!open) return null;
  return createPortal(
    <div className="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
      <div className="relative w-full max-w-lg card p-6 animate-fade-up">
        <button onClick={onClose} className="absolute top-4 right-4 text-slate-400 hover:text-slate-200">
          <X className="w-5 h-5" />
        </button>
        <h2 className="text-lg font-semibold text-slate-100 mb-4 pr-8">{title}</h2>
        {children}
      </div>
    </div>,
    document.body
  );
}

// ── Stat Card ─────────────────────────────────────────────────────────────────
export function StatCard({
  label, value, sub, icon,
}: {
  label: string;
  value: string | number;
  sub?: string;
  icon?: ReactNode;
}) {
  return (
    <div className="stat-card gap-2">
      <div className="flex items-start justify-between">
        <p className="section-label">{label}</p>
        {icon && <div className="text-brand-400">{icon}</div>}
      </div>
      <p className="text-3xl font-bold text-slate-100 tabular-nums">{value}</p>
      {sub && <p className="text-xs text-slate-500">{sub}</p>}
    </div>
  );
}
