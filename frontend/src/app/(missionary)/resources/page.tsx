'use client';
import { FileText, BookOpen, Download, Share2 } from 'lucide-react';

export default function ResourcesPage() {
  return (
    <div className="min-h-screen bg-surface p-6">
      <div className="max-w-4xl mx-auto">
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-slate-100 mb-2">Resources</h1>
          <p className="text-slate-400">Your missionary materials and documents</p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-brand-500/20 flex items-center justify-center">
                <FileText className="w-5 h-5 text-brand-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Documents</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Saved documents</p>
          </div>

          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                <BookOpen className="w-5 h-5 text-emerald-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Study Materials</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Study resources</p>
          </div>

          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-purple-500/20 flex items-center justify-center">
                <Download className="w-5 h-5 text-purple-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Downloads</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Downloaded files</p>
          </div>
        </div>

        <div className="mt-8 bg-surface-2 border border-surface-border rounded-2xl p-6">
          <div className="flex items-center gap-3 mb-4">
            <FileText className="w-6 h-6 text-brand-400" />
            <h3 className="font-semibold text-slate-100">Resources Overview</h3>
          </div>
          <p className="text-slate-400">
            Access your study materials, documents, and other resources to support your missionary work.
          </p>
        </div>
      </div>
    </div>
  );
}
