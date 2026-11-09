'use client';
import { type ReactNode } from 'react';
import { twMerge } from 'tailwind-merge';
import { clsx } from 'clsx';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
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

// ── Success Banner ────────────────────────────────────────────────────────────
export function SuccessBanner({ message }: { message: string }) {
  return (
    <div
      role="status"
      className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300"
    >
      {message}
    </div>
  );
}

// ── Pagination ────────────────────────────────────────────────────────────────
/**
 * Pagination — server-side page controls for a paginated list.
 *
 * The CALLER owns the `page` state and re-fetches when it changes; this control
 * only reports the operator's intent. `total` comes straight from the paginator
 * envelope, so the count shown is the real number of records rather than the
 * size of the current page.
 *
 * Renders nothing for a single page, so a list that already fit on one page
 * looks exactly as it did before.
 */
export function Pagination({
  page,
  lastPage,
  total,
  onPageChange,
  disabled = false,
}: {
  page: number;
  lastPage: number;
  total: number;
  onPageChange: (page: number) => void;
  disabled?: boolean;
}) {
  if (lastPage <= 1) return null;

  const button =
    'inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-surface-border text-xs font-medium text-slate-300 transition-colors hover:bg-surface-2 disabled:opacity-40 disabled:hover:bg-transparent';

  return (
    <div className="flex items-center justify-between gap-3 flex-wrap">
      <p className="text-xs text-slate-500">
        Page {page} of {lastPage} · {total} {total === 1 ? 'record' : 'records'}
      </p>
      <div className="flex items-center gap-2">
        <button
          type="button"
          className={button}
          disabled={disabled || page <= 1}
          onClick={() => onPageChange(page - 1)}
        >
          <ChevronLeft className="w-3.5 h-3.5" /> Previous
        </button>
        <button
          type="button"
          className={button}
          disabled={disabled || page >= lastPage}
          onClick={() => onPageChange(page + 1)}
        >
          Next <ChevronRight className="w-3.5 h-3.5" />
        </button>
      </div>
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
