'use client';

import { AuthGuard } from '@/components/AuthGuard';
import { SiteOperationsShell } from '@/components/SiteOperationsShell';

/**
 * Site Operations workspace layout.
 *
 * Every `/site-operations/**` route renders inside the session gate and the
 * Site Operations chrome. The shell publishes the ACTIVE site context (site +
 * campaign) through SiteOpsContext; the pages below it read that context so a
 * coordinator responsible for several sites always operates on the one they
 * have selected, rather than on whatever id a page might guess.
 *
 * Note this is a dedicated layout (not the missionary or /admin shell): Site
 * Operations is a first-class workspace, so it carries its own navigation,
 * site selector and workspace switcher.
 */
export default function SiteOperationsLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthGuard>
      <SiteOperationsShell>{children}</SiteOperationsShell>
    </AuthGuard>
  );
}
