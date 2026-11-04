'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuthStore } from '@/store/auth';
import { workspaceApi, type MyAssignment } from '@/lib/api';
import { Languages, MapPin, Users, BookOpen, FileText, ChevronRight, Calendar, User, AlertCircle, Map, Crown, Shield } from 'lucide-react';
import { StatusBadge } from '@/components/ui';
import GeneralUserHome from '@/components/GeneralUserHome';

export default function WorkspacePage() {
  const { user } = useAuthStore();
  const firstName = user?.person?.first_name ?? user?.email?.split('@')[0] ?? 'there';
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';

  const [assignment, setAssignment] = useState<MyAssignment | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);

  // Check if user has a missionary profile
  const hasMissionaryProfile = user?.person?.missionary_profile !== null && user?.person?.missionary_profile !== undefined;

  useEffect(() => {
    async function fetchAssignment() {
      try {
        setLoading(true);
        setError(null);
        const response = await workspaceApi.myAssignment();
        setAssignment(response.assignment);
      } catch (e) {
        console.error('Failed to fetch assignment:', e);
        setError('Unable to load assignment data');
      } finally {
        setLoading(false);
      }
    }

    // Only fetch assignment if user has missionary profile
    if (hasMissionaryProfile) {
      fetchAssignment();
    } else {
      setLoading(false);
    }
  }, [hasMissionaryProfile, refreshKey]);

  const hasAssignment = assignment !== null;

  // Show general user home if no missionary profile
  if (!hasMissionaryProfile) {
    return <GeneralUserHome onComplete={() => setRefreshKey(prev => prev + 1)} />;
  }

  return (
    <div className="p-4 md:p-6 max-w-5xl mx-auto space-y-6 animate-fade-up">
      {/* Header */}
      <div className="text-center md:text-left">
        <p className="text-slate-500 text-sm">{greeting}</p>
        <h1 className="text-2xl md:text-3xl font-bold text-slate-100 mt-0.5">
          {firstName}
        </h1>
        <p className="text-slate-400 text-sm mt-1">Your missionary workspace</p>
      </div>

      {/* My Mission Section */}
      {loading ? (
        <div className="glass-card p-6 md:p-8">
          <div className="flex items-center justify-center py-8">
            <div className="w-6 h-6 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
          </div>
        </div>
      ) : error ? (
        <div className="bg-red-500/10 backdrop-blur-xl border border-red-500/30 rounded-3xl p-6 md:p-8">
          <div className="flex items-start gap-3">
            <AlertCircle className="w-5 h-5 text-red-400 shrink-0 mt-0.5" />
            <div>
              <p className="text-sm text-red-400">{error}</p>
            </div>
          </div>
        </div>
      ) : !hasAssignment ? (
        <div className="glass-card p-6 md:p-8">
          <div className="flex flex-col items-center text-center gap-4 py-4">
            <div className="w-16 h-16 rounded-2xl glass-input flex items-center justify-center">
              <MapPin className="w-8 h-8 text-slate-500" />
            </div>
            <div>
              <h2 className="text-xl font-semibold text-slate-100 mb-2">No Mission Assignment Yet</h2>
              <p className="text-slate-400 text-sm max-w-md leading-relaxed">
                Your mission assignment is not yet available. Please contact your coordinator or authorised contact for information about your deployment.
              </p>
            </div>
          </div>
        </div>
      ) : (
        <div className="glass-card p-6 md:p-8">
          <div className="flex items-start gap-4 mb-6">
            <div className="w-12 h-12 rounded-2xl bg-brand-600/20 border border-brand-600/30 flex items-center justify-center shrink-0">
              <MapPin className="w-6 h-6 text-brand-400" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-slate-100">My Mission</h2>
              <p className="text-sm text-slate-500">Your current assignment</p>
            </div>
          </div>

          {/* Campaign */}
          <div className="space-y-4">
            <div className="flex items-start gap-3">
              <Map className="w-4 h-4 text-brand-400 shrink-0 mt-0.5" />
              <div className="flex-1 min-w-0">
                <p className="text-xs text-slate-500 mb-0.5">Campaign</p>
                <p className="text-sm font-medium text-slate-100">{assignment.campaign.name}</p>
                {assignment.campaign.description && (
                  <p className="text-xs text-slate-500 mt-1 line-clamp-2">{assignment.campaign.description}</p>
                )}
              </div>
              <StatusBadge status={assignment.campaign.status} />
            </div>

            {/* Site */}
            {assignment.site && (
              <div className="flex items-start gap-3">
                <MapPin className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs text-slate-500 mb-0.5">Mission Site</p>
                  <p className="text-sm font-medium text-slate-100">{assignment.site.name}</p>
                  {assignment.site.location_description && (
                    <p className="text-xs text-slate-500 mt-1">{assignment.site.location_description}</p>
                  )}
                </div>
                <StatusBadge status={assignment.site.status} />
              </div>
            )}

            {/* Team */}
            {assignment.team && (
              <div className="flex items-start gap-3">
                <Users className="w-4 h-4 text-purple-400 shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs text-slate-500 mb-0.5">Team</p>
                  <p className="text-sm font-medium text-slate-100">{assignment.team.name}</p>
                  {assignment.team.team_type && (
                    <p className="text-xs text-slate-500 mt-1">{assignment.team.team_type.label}</p>
                  )}
                </div>
              </div>
            )}

            {/* Functional Role */}
            {assignment.functional_role && (
              <div className="flex items-start gap-3">
                <Shield className="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs text-slate-500 mb-0.5">Role</p>
                  <p className="text-sm font-medium text-slate-100">{assignment.functional_role.label}</p>
                </div>
              </div>
            )}

            {/* Dates */}
            {(assignment.expected_arrival || assignment.actual_arrival) && (
              <div className="flex items-start gap-3">
                <Calendar className="w-4 h-4 text-cyan-400 shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                  <p className="text-xs text-slate-500 mb-0.5">Arrival</p>
                  {assignment.actual_arrival ? (
                    <p className="text-sm text-slate-100">Arrived: {new Date(assignment.actual_arrival).toLocaleDateString()}</p>
                  ) : assignment.expected_arrival ? (
                    <p className="text-sm text-slate-100">Expected: {new Date(assignment.expected_arrival).toLocaleDateString()}</p>
                  ) : null}
                </div>
                <StatusBadge status={assignment.status} />
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
