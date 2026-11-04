'use client';
import { AuthGuard } from '@/components/AuthGuard';
import { MissionaryShell } from '@/components/MissionaryShell';

export default function TranslatorLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthGuard>
      <MissionaryShell showHeader={false}>{children}</MissionaryShell>
    </AuthGuard>
  );
}
