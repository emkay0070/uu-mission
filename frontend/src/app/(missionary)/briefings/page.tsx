'use client';
import Link from 'next/link';
import { FileText, ChevronRight, Bell, Info, AlertTriangle } from 'lucide-react';

export default function BriefingsPage() {
  return (
    <div className="p-4 md:p-6 max-w-5xl mx-auto space-y-6 animate-fade-up">
      {/* Header */}
      <div className="flex items-center gap-3">
        <Link
          href="/dashboard"
          className="text-slate-500 hover:text-slate-300 transition-colors"
        >
          <ChevronRight className="w-5 h-5 -rotate-90" />
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-slate-100">My Briefings</h1>
          <p className="text-sm text-slate-400 mt-0.5">Mission instructions and announcements</p>
        </div>
      </div>

      {/* Coming Soon State */}
      <div className="card p-8 border border-surface-border">
        <div className="flex flex-col items-center text-center gap-4 py-8">
          <div className="w-16 h-16 rounded-2xl bg-surface-2 border border-surface-border flex items-center justify-center">
            <FileText className="w-8 h-8 text-slate-500" />
          </div>
          <div className="max-w-md">
            <h2 className="text-xl font-semibold text-slate-100 mb-2">Briefings Module Coming Soon</h2>
            <p className="text-slate-400 text-sm leading-relaxed">
              The briefings module is under development. This will include mission instructions, announcements, and assignment-related information from your coordinators.
            </p>
          </div>
          <div className="flex flex-wrap justify-center gap-6 mt-4 pt-4 border-t border-surface-border">
            <div className="flex items-center gap-2 text-sm text-slate-500">
              <Bell className="w-4 h-4" />
              <span>Announcements</span>
            </div>
            <div className="flex items-center gap-2 text-sm text-slate-500">
              <Info className="w-4 h-4" />
              <span>Mission Guidelines</span>
            </div>
            <div className="flex items-center gap-2 text-sm text-slate-500">
              <AlertTriangle className="w-4 h-4" />
              <span>Important Updates</span>
            </div>
          </div>
        </div>
      </div>

      {/* Back to Workspace */}
      <div className="text-center">
        <Link
          href="/dashboard"
          className="inline-flex items-center gap-2 text-sm text-brand-400 hover:text-brand-300 transition-colors"
        >
          <ChevronRight className="w-4 h-4 -rotate-90" />
          <span>Return to Workspace</span>
        </Link>
      </div>
    </div>
  );
}
