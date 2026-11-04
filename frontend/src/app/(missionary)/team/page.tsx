'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { workspaceTeamApi, type MyTeam } from '@/lib/api';
import { Users, ChevronRight, Crown, Shield, AlertCircle } from 'lucide-react';
import { EmptyState } from '@/components/ui';

export default function TeamPage() {
  const [team, setTeam] = useState<MyTeam | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    async function fetchTeam() {
      try {
        setLoading(true);
        setError(null);
        const response = await workspaceTeamApi.myTeam();
        setTeam(response.team);
      } catch (e) {
        console.error('Failed to fetch team:', e);
        setError('Unable to load team data');
      } finally {
        setLoading(false);
      }
    }

    fetchTeam();
  }, []);

  const hasTeam = team !== null;

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
          <h1 className="text-2xl font-bold text-slate-100">My Team</h1>
          <p className="text-sm text-slate-400 mt-0.5">Your team members and responsibilities</p>
        </div>
      </div>

      {/* Team Content */}
      {loading ? (
        <div className="glass-card p-8">
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
      ) : !hasTeam ? (
        <div className="glass-card p-8">
          <EmptyState
            icon={<Users className="w-7 h-7" />}
            title="No Team Assignment Yet"
            body="Your team information will appear here once you have been assigned to a mission team."
          />
        </div>
      ) : (
        <div className="space-y-6">
          {/* Team Overview */}
          <div className="glass-card p-6">
            <div className="flex items-start gap-4 mb-6">
              <div className="w-12 h-12 rounded-2xl bg-emerald-600/20 border border-emerald-600/30 flex items-center justify-center shrink-0">
                <Users className="w-6 h-6 text-emerald-400" />
              </div>
              <div className="flex-1">
                <h2 className="text-lg font-semibold text-slate-100">{team.name}</h2>
                {team.description && (
                  <p className="text-sm text-slate-500 mt-1">{team.description}</p>
                )}
                {team.team_type && (
                  <p className="text-xs text-slate-500 mt-2">{team.team_type.label}</p>
                )}
              </div>
            </div>
          </div>

          {/* Team Members */}
          <div>
            <h3 className="text-sm font-semibold text-slate-300 mb-3">Team Members ({team.members.length})</h3>
            <div className="glass-card divide-y divide-white/10">
              {team.members.map((member) => (
                <div key={member.id} className="p-4 flex items-center gap-4">
                  <div className="w-10 h-10 rounded-full glass-input flex items-center justify-center shrink-0">
                    <span className="text-sm font-medium text-slate-300">
                      {member.person.first_name[0]}{member.person.last_name[0]}
                    </span>
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-slate-100">{member.person.full_name}</p>
                    <div className="flex items-center gap-2 mt-0.5">
                      {member.role_in_team === 'leader' && (
                        <span className="flex items-center gap-1 text-xs text-amber-400">
                          <Crown className="w-3 h-3" />
                          Leader
                        </span>
                      )}
                      {member.role_in_team === 'deputy' && (
                        <span className="flex items-center gap-1 text-xs text-purple-400">
                          <Shield className="w-3 h-3" />
                          Deputy
                        </span>
                      )}
                      {member.joined_on && (
                        <span className="text-xs text-slate-500">
                          Joined {new Date(member.joined_on).toLocaleDateString()}
                        </span>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
