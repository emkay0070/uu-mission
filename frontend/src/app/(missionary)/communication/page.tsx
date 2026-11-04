'use client';
import { MessageSquare, Mail, Phone, Video } from 'lucide-react';

export default function CommunicationPage() {
  return (
    <div className="min-h-screen bg-surface p-6">
      <div className="max-w-4xl mx-auto">
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-slate-100 mb-2">Communication</h1>
          <p className="text-slate-400">Your communication tools and logs</p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-brand-500/20 flex items-center justify-center">
                <MessageSquare className="w-5 h-5 text-brand-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Messages</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Recent messages</p>
          </div>

          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                <Mail className="w-5 h-5 text-emerald-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Emails</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Email communications</p>
          </div>

          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-purple-500/20 flex items-center justify-center">
                <Phone className="w-5 h-5 text-purple-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Calls</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Call history</p>
          </div>

          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-orange-500/20 flex items-center justify-center">
                <Video className="w-5 h-5 text-orange-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Video Calls</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Video meetings</p>
          </div>
        </div>

        <div className="mt-8 bg-surface-2 border border-surface-border rounded-2xl p-6">
          <div className="flex items-center gap-3 mb-4">
            <MessageSquare className="w-6 h-6 text-brand-400" />
            <h3 className="font-semibold text-slate-100">Communication Overview</h3>
          </div>
          <p className="text-slate-400">
            Track your communications, manage messages, and stay connected with your network.
          </p>
        </div>
      </div>
    </div>
  );
}
