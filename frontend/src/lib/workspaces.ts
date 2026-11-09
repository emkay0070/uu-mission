/**
 * workspaces.ts — client-side workspace resolution and scope-aware capability checks.
 *
 * IMPORTANT
 * ---------
 * Everything here is derived from the descriptor the BACKEND already resolved
 * (`AuthUser.workspaces`), which is computed from effective, valid, unrevoked
 * role assignments. Nothing in this file invents authority.
 *
 * These helpers exist to answer two UI questions:
 *   1. Which operational experience should this person be in? (routing)
 *   2. May this control be shown, *for this context*? (affordance)
 *
 * They are NEVER security. Every operation is re-authorised by the backend
 * against the real resource scope; hiding a control only avoids a pointless
 * 403.
 */

import type { AuthScope, AuthUser, WorkspaceContext, WorkspaceDescriptor, WorkspaceKey } from './api';

/** Every workspace the user may enter, as resolved by the backend. */
export function availableWorkspaces(user: AuthUser | null | undefined): WorkspaceDescriptor[] {
  return user?.workspaces ?? [];
}

/** Whether the user may enter a specific workspace. */
export function hasWorkspace(
  user: AuthUser | null | undefined,
  key: WorkspaceKey
): boolean {
  return availableWorkspaces(user).some((workspace) => workspace.key === key);
}

/** The descriptor for one workspace, or null when it is not available. */
export function workspace(
  user: AuthUser | null | undefined,
  key: WorkspaceKey
): WorkspaceDescriptor | null {
  return availableWorkspaces(user).find((entry) => entry.key === key) ?? null;
}

/** The contexts authorised inside one workspace. */
export function contextsFor(
  user: AuthUser | null | undefined,
  key: WorkspaceKey
): WorkspaceContext[] {
  return workspace(user, key)?.contexts ?? [];
}

/**
 * The workspace the user should land in, or null when none is available.
 *
 * The backend returns workspaces in descending order of operational
 * precedence (platform → campaign → site → missionary), so the first entry is
 * the intended default.
 */
export function defaultWorkspace(user: AuthUser | null | undefined): WorkspaceDescriptor | null {
  return availableWorkspaces(user)[0] ?? null;
}

/**
 * The route the user should land on.
 *
 * Falls back to `/dashboard` when no operational workspace exists — that route
 * already renders the neutral "your account is ready" state, so a user with no
 * assignment is never forced into an administrative or missionary experience.
 */
export function homeRoute(user: AuthUser | null | undefined): string {
  return defaultWorkspace(user)?.home ?? '/dashboard';
}

/** Whether the user qualifies for any administrative workspace. */
export function hasAdministrativeWorkspace(user: AuthUser | null | undefined): boolean {
  return (
    hasWorkspace(user, 'platform_administration') || hasWorkspace(user, 'campaign_management')
  );
}

/**
 * The resource a capability is being checked against.
 *
 * Deliberately a smaller surface than `WorkspaceContext`: callers pass only
 * the identifiers they actually know.
 */
export interface ScopeContext {
  org_unit_id?: number | null;
  campaign_id?: number | null;
  site_id?: number | null;
  team_id?: number | null;
}

/**
 * Whether one assignment (scope) COVERS the requested context.
 *
 * Widening is never allowed:
 *   global    → covers everything
 *   org_unit  → covers only that exact org unit (the subtree is a backend
 *               concern; the client cannot compute it and must not guess)
 *   campaign  → covers that campaign
 *   site      → covers that site only
 *   team      → covers that team only — never the parent site
 */
function scopeCovers(scope: AuthScope, context: ScopeContext): boolean {
  switch (scope.scope_type) {
    case 'global':
      return true;
    case 'org_unit':
      return context.org_unit_id != null && scope.org_unit_id === context.org_unit_id;
    case 'campaign':
      return context.campaign_id != null && scope.campaign_id === context.campaign_id;
    case 'site':
      return context.site_id != null && scope.site_id === context.site_id;
    case 'team':
      return context.team_id != null && scope.team_id === context.team_id;
    default:
      return false;
  }
}

/**
 * Whether the user holds `permission`.
 *
 *   can(user, 'reports.view')
 *     → does any active assignment grant it? (navigation affordance)
 *
 *   can(user, 'reports.review', { site_id: 12 })
 *     → does any active assignment that COVERS site 12 grant it?
 *
 * The scoped form is the honest one: a flat union would say "yes" for a
 * permission held in a completely different site, campaign or team.
 */
export function can(
  user: AuthUser | null | undefined,
  permission: string,
  context?: ScopeContext | null
): boolean {
  if (!user) return false;

  // No context supplied: fall back to the flattened union, which is all the
  // payload guarantees. Callers that know their context should pass it.
  if (!context) {
    return user.permissions?.includes(permission) ?? false;
  }

  const scopes = user.scopes ?? [];

  return scopes.some(
    (scope) =>
      scopeCovers(scope, context) && (scope.permissions?.includes(permission) ?? false)
  );
}

/**
 * The permissions the user effectively holds FOR A CONTEXT.
 *
 * The union of every assignment covering that context — the useful "what can I
 * do here?" answer that a flat permission list cannot express.
 */
export function permissionsFor(
  user: AuthUser | null | undefined,
  context: ScopeContext | null | undefined
): string[] {
  if (!user) return [];
  if (!context) return user.permissions ?? [];

  const granted = new Set<string>();

  for (const scope of user.scopes ?? []) {
    if (!scopeCovers(scope, context)) continue;
    for (const code of scope.permissions ?? []) {
      granted.add(code);
    }
  }

  return [...granted];
}
