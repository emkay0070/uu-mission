'use client';

import { AuthGuard } from '@/components/AuthGuard';
import { CampaignManagementShell } from '@/components/CampaignManagementShell';

/**
 * Campaign Management workspace layout.
 *
 * Every `/campaign-management/**` route renders inside the session gate and the
 * Campaign Management chrome. The shell publishes the ACTIVE campaign context
 * through CampaignOpsContext; the pages below it read that context so a leader
 * responsible for several campaigns always oversees the one they have
 * selected, rather than on whatever id a page might guess.
 *
 * This is a dedicated layout (not the missionary, /admin or Site Operations
 * shell): Campaign Management is a first-class workspace with its own
 * navigation, campaign selector and workspace switcher.
 */
export default function CampaignManagementLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthGuard>
      <CampaignManagementShell>{children}</CampaignManagementShell>
    </AuthGuard>
  );
}
