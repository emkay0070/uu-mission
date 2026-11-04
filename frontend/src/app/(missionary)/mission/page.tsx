'use client';
import { Globe, MapPin, Calendar, Users, Target } from 'lucide-react';

export default function MissionPage() {
  return (
    <div className="min-h-screen bg-surface p-6">
      <div className="max-w-4xl mx-auto">
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-slate-100 mb-2">Mission</h1>
          <p className="text-slate-400">Your missionary work overview</p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-brand-500/20 flex items-center justify-center">
                <MapPin className="w-5 h-5 text-brand-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Locations</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Active mission sites</p>
          </div>

          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                <Users className="w-5 h-5 text-emerald-400" />
              </div>
              <h3 className="font-semibold text-slate-100">People</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">People reached</p>
          </div>

          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-purple-500/20 flex items-center justify-center">
                <Target className="w-5 h-5 text-purple-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Goals</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Mission objectives</p>
          </div>

          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-lg bg-orange-500/20 flex items-center justify-center">
                <Calendar className="w-5 h-5 text-orange-400" />
              </div>
              <h3 className="font-semibold text-slate-100">Events</h3>
            </div>
            <p className="text-2xl font-bold text-slate-100 mb-1">0</p>
            <p className="text-sm text-slate-400">Upcoming events</p>
          </div>
        </div>

        <div className="mt-8 bg-surface-2 border border-surface-border rounded-2xl p-6">
          <div className="flex items-center gap-3 mb-4">
            <Globe className="w-6 h-6 text-brand-400" />
            <h3 className="font-semibold text-slate-100">Mission Overview</h3>
          </div>
          <p className="text-slate-400">
            Track your missionary work, manage locations, and monitor your progress in reaching people with your message.
          </p>
        </div>
      </div>
    </div>
  );
}
