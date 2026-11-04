'use client';
import { useAuthStore } from '@/store/auth';
import { AuthGuard } from '@/components/AuthGuard';
import { MissionaryShell } from '@/components/MissionaryShell';

export default function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  const { user } = useAuthStore();
  const hasMissionaryProfile = user?.person?.missionary_profile !== null;

  return (
    <AuthGuard>
      {hasMissionaryProfile ? (
        <MissionaryShell showHeader={false}>{children}</MissionaryShell>
      ) : (
        <div className="min-h-screen bg-surface">
          {children}
        </div>
      )}
    </AuthGuard>
  );
}
