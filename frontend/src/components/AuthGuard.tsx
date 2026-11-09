'use client';
import { useEffect } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/auth';
import { hasAdministrativeWorkspace, hasWorkspace, homeRoute } from '@/lib/workspaces';

/**
 * Routes reachable without an authenticated session.
 */
const PUBLIC_ROUTES = [
  '/login',
  '/signup',
  '/verify-email',
  '/forgot-password',
  '/reset-password',
];

/**
 * AuthGuard — session gate plus workspace-aware routing.
 *
 * Routing is no longer derived from the binary `primary_role`. It is derived
 * from the workspaces the BACKEND resolved for this person, so a Site
 * Coordinator is sent to Site Operations and is still allowed into the
 * operational pages under `/admin` that they legitimately use.
 *
 * The rules are deliberately permissive in ONE direction only: a user is
 * stopped from entering a surface they hold no workspace for. No rule opens a
 * surface — the backend authorises every request regardless.
 *
 * A user with NO operational workspace is sent to `/dashboard`, which already
 * renders the neutral "your account is ready" state. They are never forced
 * into an administrative or missionary experience they do not hold.
 */
export function AuthGuard({ children }: { children: React.ReactNode }) {
  const { user, initialized, initialize } = useAuthStore();
  const router = useRouter();
  const pathname = usePathname();

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { initialize(); }, []);

  useEffect(() => {
    if (!initialized) return;

    if (!user) {
      if (!PUBLIC_ROUTES.includes(pathname)) router.replace('/login');
      return;
    }

    if (PUBLIC_ROUTES.includes(pathname)) return;

    const administrative = hasAdministrativeWorkspace(user);
    const siteOperations = hasWorkspace(user, 'site_operations');
    const campaignManagement = hasWorkspace(user, 'campaign_management');
    const missionary = hasWorkspace(user, 'missionary');

    const onAdminRoute = pathname.startsWith('/admin');
    const onSiteOpsRoute =
      pathname === '/site-operations' || pathname.startsWith('/site-operations/');
    const onCampaignRoute =
      pathname === '/campaign-management' || pathname.startsWith('/campaign-management/');

    const home = homeRoute(user);

    let target: string | null = null;

    if (onAdminRoute) {
      // Administrative surfaces need an administrative workspace. Site
      // Operations users are also admitted, because the operational modules
      // they are responsible for still live under /admin/* in this milestone.
      if (!administrative && !siteOperations) target = home;
    } else if (onSiteOpsRoute) {
      if (!siteOperations) target = home;
    } else if (onCampaignRoute) {
      // Campaign Management is a first-class workspace of its own: it is
      // gated by the campaign_management workspace, never by missionary or
      // site authority.
      if (!campaignManagement) target = home;
    } else if (!missionary && pathname !== home) {
      // Everything else is the personal/missionary surface.
      target = home;
    }

    if (target && target !== pathname) router.replace(target);
  }, [initialized, user, router, pathname]);

  if (!initialized) {
    return (
      <div className="min-h-screen bg-surface flex items-center justify-center">
        <div className="flex flex-col items-center gap-4">
          <div className="w-10 h-10 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
          <p className="text-slate-400 text-sm">Loading workspace…</p>
        </div>
      </div>
    );
  }

  if (!user) return null;
  return <>{children}</>;
}
