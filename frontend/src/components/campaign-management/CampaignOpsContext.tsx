'use client';

import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import type { WorkspaceContext } from '@/lib/api';
import { can, contextsFor, permissionsFor, type ScopeContext } from '@/lib/workspaces';
import { useAuthStore } from '@/store/auth';

/**
 * The active Campaign Management context.
 *
 * A person may be authorised to oversee more than one campaign — either through
 * campaign-scoped leadership appointments, or through an organisational
 * appointment whose jurisdiction leads several campaigns. The backend has
 * already resolved those into concrete campaign contexts (see
 * WorkspaceResolver::buildCampaignContexts); this provider holds EVERY
 * authorised campaign plus the one currently being overseen.
 *
 * Nothing here grants authority. The context is only used to ask the
 * scope-aware `canIn` helper which controls to show, and every module
 * re-authorises its requests on the backend against the real resource scope.
 */
interface CampaignOperationsValue {
  /** Every campaign context the backend authorised for this person. */
  contexts: WorkspaceContext[];
  /** The campaign currently being overseen (null when none is authorised). */
  active: WorkspaceContext | null;
  /** The active context reduced to the identifiers permission checks need. */
  scope: ScopeContext | null;
  /** Switch to another authorised campaign. Unknown ids are ignored. */
  selectCampaign: (campaignId: number) => void;
  /** Scope-aware capability check for the ACTIVE campaign. */
  canIn: (permission: string) => boolean;
  /** The permission codes effective in the ACTIVE campaign. */
  permissions: string[];
}

const CampaignOperationsContext = createContext<CampaignOperationsValue | null>(null);

export function CampaignOperationsProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuthStore();

  const contexts = useMemo(() => contextsFor(user, 'campaign_management'), [user]);

  const [activeCampaignId, setActiveCampaignId] = useState<number | null>(null);

  // Default to the first authorised campaign and fall back to it whenever the
  // selected campaign is no longer authorised (for example after a
  // re-appointment), so the active context can never become stale.
  const active = useMemo(() => {
    if (contexts.length === 0) return null;
    return contexts.find((context) => context.campaign_id === activeCampaignId) ?? contexts[0];
  }, [contexts, activeCampaignId]);

  const scope = useMemo<ScopeContext | null>(() => {
    if (!active) return null;

    // An org_unit context carries its organisational scope (so a scope-aware
    // check matches the appointment that grants campaign oversight); a
    // campaign context carries the campaign itself. Site/team never apply
    // here — campaign oversight is not site authority.
    return {
      org_unit_id: active.scope_type === 'org_unit' ? active.scope_id : null,
      campaign_id: active.campaign_id,
      site_id: null,
      team_id: null,
    };
  }, [active]);

  const selectCampaign = useCallback(
    (campaignId: number) => {
      if (contexts.some((context) => context.campaign_id === campaignId)) {
        setActiveCampaignId(campaignId);
      }
    },
    [contexts]
  );

  const canIn = useCallback(
    (permission: string) => can(user, permission, scope),
    [user, scope]
  );

  const permissions = useMemo(() => permissionsFor(user, scope), [user, scope]);

  const value = useMemo<CampaignOperationsValue>(
    () => ({ contexts, active, scope, selectCampaign, canIn, permissions }),
    [contexts, active, scope, selectCampaign, canIn, permissions]
  );

  return (
    <CampaignOperationsContext.Provider value={value}>
      {children}
    </CampaignOperationsContext.Provider>
  );
}

export function useCampaignOperations(): CampaignOperationsValue {
  const value = useContext(CampaignOperationsContext);
  if (!value) {
    throw new Error('useCampaignOperations must be used inside CampaignOperationsProvider');
  }
  return value;
}
