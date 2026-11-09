'use client';

import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import type { WorkspaceContext } from '@/lib/api';
import { can, contextsFor, permissionsFor, type ScopeContext } from '@/lib/workspaces';
import { useAuthStore } from '@/store/auth';

/**
 * The active Site Operations context.
 *
 * A person may be responsible for more than one mission site. The context
 * holds EVERY authorised site (so none is silently lost) plus the one the
 * operator is currently looking at. Nothing here grants authority: the scope
 * object is only used to ask the scope-aware `canIn` helper which controls to
 * show, and the backend authorises each request independently.
 */
interface SiteOperationsValue {
  /** Every site context the backend authorised for this person. */
  contexts: WorkspaceContext[];
  /** The site currently being operated on (null when none is authorised). */
  active: WorkspaceContext | null;
  /** The active context reduced to the identifiers permission checks need. */
  scope: ScopeContext | null;
  /** Switch to another authorised site. Unknown ids are ignored. */
  selectSite: (siteId: number) => void;
  /** Scope-aware capability check for the ACTIVE site. */
  canIn: (permission: string) => boolean;
  /** The permission codes effective in the ACTIVE site. */
  permissions: string[];
}

const SiteOperationsContext = createContext<SiteOperationsValue | null>(null);

export function SiteOperationsProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuthStore();

  const contexts = useMemo(() => contextsFor(user, 'site_operations'), [user]);

  const [activeSiteId, setActiveSiteId] = useState<number | null>(null);

  // Default to the first authorised site and fall back to it whenever the
  // selected site is no longer authorised (for example after a re-assignment).
  const active = useMemo(() => {
    if (contexts.length === 0) return null;
    return contexts.find((context) => context.site_id === activeSiteId) ?? contexts[0];
  }, [contexts, activeSiteId]);

  const scope = useMemo<ScopeContext | null>(() => {
    if (!active) return null;
    return {
      campaign_id: active.campaign_id,
      site_id: active.site_id,
      team_id: active.team_id,
      org_unit_id: null,
    };
  }, [active]);

  const selectSite = useCallback(
    (siteId: number) => {
      if (contexts.some((context) => context.site_id === siteId)) setActiveSiteId(siteId);
    },
    [contexts]
  );

  const canIn = useCallback(
    (permission: string) => can(user, permission, scope),
    [user, scope]
  );

  const permissions = useMemo(() => permissionsFor(user, scope), [user, scope]);

  const value = useMemo<SiteOperationsValue>(
    () => ({ contexts, active, scope, selectSite, canIn, permissions }),
    [contexts, active, scope, selectSite, canIn, permissions]
  );

  return (
    <SiteOperationsContext.Provider value={value}>{children}</SiteOperationsContext.Provider>
  );
}

export function useSiteOperations(): SiteOperationsValue {
  const value = useContext(SiteOperationsContext);
  if (!value) {
    throw new Error('useSiteOperations must be used inside SiteOperationsProvider');
  }
  return value;
}
