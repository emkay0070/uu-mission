'use client';

import Link from 'next/link';
import {
  BookOpen,
  Bookmark,
  ChevronRight,
  ClipboardCheck,
  Download,
  FolderOpen,
  ShieldCheck,
  Store,
} from 'lucide-react';
import { useAuthStore } from '@/store/auth';
import { hasPermission } from '@/lib/permissions';

export default function ResourcesPage() {
  const { user } = useAuthStore();
  const canContribute = hasPermission(user, 'resources.create');
  const canReview = hasPermission(user, 'resources.approve_distribute');

  return (
    <div className="min-h-screen bg-surface p-4 sm:p-6">
      <div className="mx-auto max-w-6xl">
        <div className="mb-8">
          <h1 className="mb-2 text-2xl font-bold text-slate-100 sm:text-3xl">Resources</h1>
          <p className="text-slate-400">Missionary Learning Centre</p>
        </div>

        {/* Main Navigation Cards */}
        <div className="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
          {/* Store Card */}
          <Link
            href="/resources/store"
            className="group rounded-2xl border border-surface-border bg-surface-2 p-6 transition-all hover:border-brand-500"
          >
            <div className="flex items-start gap-4">
              <div className="rounded-xl bg-brand-500/20 p-4 transition-colors group-hover:bg-brand-500/30">
                <Store className="h-8 w-8 text-brand-400" />
              </div>
              <div className="min-w-0 flex-1">
                <div className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-brand-400">
                  Discover
                </div>
                <h2 className="mb-2 text-xl font-bold text-slate-100 transition-colors group-hover:text-brand-400">
                  Resource Store
                </h2>
                <p className="mb-4 text-sm text-slate-400">
                  Browse published resources you are authorised to read. Search, filter by category,
                  and save materials to your personal library.
                </p>
                <div className="flex items-center text-sm font-medium text-brand-400">
                  Explore Store
                  <ChevronRight className="ml-1 h-4 w-4 transition-transform group-hover:translate-x-1" />
                </div>
              </div>
            </div>
          </Link>

          {/* My Resources / Contribution Card */}
          <Link
            href="/resources/my-resources"
            className="group rounded-2xl border border-surface-border bg-surface-2 p-6 transition-all hover:border-amber-500/70"
          >
            <div className="flex items-start gap-4">
              <div className="rounded-xl bg-amber-500/15 p-4 transition-colors group-hover:bg-amber-500/25">
                <FolderOpen className="h-8 w-8 text-amber-400" />
              </div>
              <div className="min-w-0 flex-1">
                <div className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-amber-400">
                  {canContribute ? 'Contribute & manage' : 'My library'}
                </div>
                <h2 className="mb-2 text-xl font-bold text-slate-100 transition-colors group-hover:text-amber-300">
                  My Resources
                </h2>
                <p className="mb-4 text-sm text-slate-400">
                  {canContribute
                    ? 'Create private drafts, track pending reviews, read reviewer feedback, and submit finished resources for distribution.'
                    : 'Continue reading, revisit saved items, access offline downloads, and review your reading history.'}
                </p>
                <div className="flex items-center text-sm font-medium text-amber-400">
                  {canContribute ? 'Manage contributions' : 'Open library'}
                  <ChevronRight className="ml-1 h-4 w-4 transition-transform group-hover:translate-x-1" />
                </div>
              </div>
            </div>
          </Link>

          {/* Reviewer Card — only for users holding approval authority. */}
          {canReview ? (
            <Link
              href="/resources/reviews"
              className="group rounded-2xl border border-emerald-500/25 bg-surface-2 p-6 transition-all hover:border-emerald-500/70"
            >
              <div className="flex items-start gap-4">
                <div className="rounded-xl bg-emerald-500/15 p-4 transition-colors group-hover:bg-emerald-500/25">
                  <ShieldCheck className="h-8 w-8 text-emerald-400" />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-emerald-400">
                    Review
                  </div>
                  <h2 className="mb-2 text-xl font-bold text-slate-100 transition-colors group-hover:text-emerald-300">
                    Distribution Reviews
                  </h2>
                  <p className="mb-4 text-sm text-slate-400">
                    Inspect distribution requests within your approval scope, confirm the proposed
                    audience, and approve, return, or reject them with feedback.
                  </p>
                  <div className="flex items-center text-sm font-medium text-emerald-400">
                    Open reviewer queue
                    <ChevronRight className="ml-1 h-4 w-4 transition-transform group-hover:translate-x-1" />
                  </div>
                </div>
              </div>
            </Link>
          ) : (
            <div className="rounded-2xl border border-dashed border-surface-border bg-surface-2/50 p-6">
              <div className="flex items-start gap-4">
                <div className="rounded-xl bg-surface-3 p-4">
                  <ClipboardCheck className="h-8 w-8 text-slate-500" />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                    Review
                  </div>
                  <h2 className="mb-2 text-xl font-bold text-slate-400">Distribution Reviews</h2>
                  <p className="text-sm text-slate-500">
                    Distribution review is reserved for users with approval authority for a
                    requested audience. Your account does not currently hold{' '}
                    <code className="rounded bg-surface-3 px-1 py-0.5 text-xs text-slate-400">
                      resources.approve_distribute
                    </code>
                    .
                  </p>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Quick Actions */}
        <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
          <Link
            href="/resources/store"
            className="flex items-center gap-3 rounded-xl border border-surface-border bg-surface-2 p-4 transition-colors hover:border-brand-500/60"
          >
            <Bookmark className="h-5 w-5 text-slate-400" />
            <span className="text-slate-300">Browse Categories</span>
          </Link>
          <Link
            href="/resources/my-resources"
            className="flex items-center gap-3 rounded-xl border border-surface-border bg-surface-2 p-4 transition-colors hover:border-brand-500/60"
          >
            <Download className="h-5 w-5 text-slate-400" />
            <span className="text-slate-300">Offline Downloads</span>
          </Link>
          <Link
            href="/resources/my-resources"
            className="flex items-center gap-3 rounded-xl border border-surface-border bg-surface-2 p-4 transition-colors hover:border-brand-500/60"
          >
            <BookOpen className="h-5 w-5 text-slate-400" />
            <span className="text-slate-300">Continue Reading</span>
          </Link>
        </div>

        <p className="mt-8 max-w-3xl text-xs leading-5 text-slate-500">
          Publishing is a two-stage process: contributors create private drafts and submit them for
          review, then an authorised reviewer decides whether to release the resource to the proposed
          audience. Saving a draft never publishes anything, and the backend remains authoritative
          for every permission and scope check.
        </p>
      </div>
    </div>
  );
}
