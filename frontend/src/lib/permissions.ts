/**
 * permissions.ts — client-side capability checks for the missionary workspace.
 *
 * IMPORTANT
 * ---------
 * The permission list carried on the authenticated user (`AuthUser.permissions`)
 * is a union of the codes granted by EVERY active role assignment, flattened
 * across scopes. It is therefore a UI affordance only: it tells us which
 * modules the user has *some* operational access to, never that a specific
 * record may be read or written.
 *
 * All real access control happens on the backend against the actual requested
 * resource scope (AuthorizationService + policies). Hiding a module here is a
 * convenience; the API returns 403 regardless.
 */

import type { AuthUser } from './api';

/** Operational modules that are gated behind an authorised assignment. */
export type WorkspaceModuleKey = 'mission' | 'team' | 'fieldwork' | 'people' | 'outcomes' | 'reports';

/**
 * Every permission code that represents operational (mission work) access.
 *
 * Used for the modules that present the user's OWN mission context — Mission
 * overview and Team — because those two are meaningful to any user who holds
 * an operational assignment, whatever their specific role.
 */
export const OPERATIONAL_PERMISSIONS: readonly string[] = [
  // Organisation / campaign level
  'org.view',
  'campaigns.view',
  'campaigns.edit',
  'sites.view',
  'sites.edit',
  'assignments.view',
  'assignments.manage',
  // Fieldwork
  'activities.view',
  'activities.create',
  'activities.update',
  'activities.delete',
  'attendance.submit',
  'attendance.verify',
  'attendance.view',
  'report.submit',
  'report.view',
  'report.view_team',
  'report.review',
  // Outcomes
  'outcomes.record',
  'outcomes.view',
  'outcomes.manage_followup',
  // People & follow-up
  'people.view',
  'people.create',
  'people.edit',
  'outreach-contacts.view',
  'outreach-contacts.create',
  'outreach-contacts.edit',
];

/**
 * Permission codes required to reveal each gated module.
 *
 * Fieldwork and People mirror the backend policies exactly, so the navigation
 * can never advertise a module whose API would answer 403.
 */
const MODULE_PERMISSIONS: Record<WorkspaceModuleKey, readonly string[]> = {
  mission: OPERATIONAL_PERMISSIONS,
  team: OPERATIONAL_PERMISSIONS,
  // Fieldwork is revealed to anyone who can either open/record a register or
  // see the activities those registers hang off — mirroring ActivityPolicy and
  // AttendanceRegisterPolicy on the backend.
  fieldwork: [
    'activities.view',
    'activities.create',
    'activities.update',
    'activities.delete',
    'attendance.view',
    'attendance.submit',
  ],
  people: ['people.view', 'outreach-contacts.view'],
  // The Reports workspace is revealed to anyone who holds one of the report
  // capabilities — including the narrow, team-scoped `report.view_team`, which
  // is what lets a team member see their own team's developing report.
  reports: ['report.view', 'report.view_team', 'report.submit', 'report.review'],
  // Recording outcomes is a capability, not a role: the module — and the
  // recording interface inside it — is revealed only to users the backend has
  // authorised to record, never on the basis of `primary_role`.
  outcomes: ['outcomes.record'],
};

/**
 * Map a route path to the workspace module it belongs to.
 *
 * Returns null for always-available routes (Home, Translator, Profile,
 * Resources, Media, Communication).
 */
export function moduleForPath(pathname: string): WorkspaceModuleKey | null {
  if (pathname === '/mission' || pathname.startsWith('/mission/')) return 'mission';
  if (pathname === '/team' || pathname.startsWith('/team/')) return 'team';
  if (pathname === '/fieldwork' || pathname.startsWith('/fieldwork/')) return 'fieldwork';
  if (pathname === '/people' || pathname.startsWith('/people/')) return 'people';
  if (pathname === '/outcomes' || pathname.startsWith('/outcomes/')) return 'outcomes';
  if (pathname === '/reports' || pathname.startsWith('/reports/')) return 'reports';

  return null;
}

/** Whether the user holds a specific permission code in any scope. */
export function hasPermission(user: AuthUser | null | undefined, code: string): boolean {
  return user?.permissions?.includes(code) ?? false;
}

/** Whether the user holds any of the supplied permission codes in any scope. */
export function hasAnyPermission(
  user: AuthUser | null | undefined,
  codes: readonly string[]
): boolean {
  if (!user) return false;

  const granted = user.permissions ?? [];

  return codes.some((code) => granted.includes(code));
}

/**
 * Whether the user may see the given workspace module.
 *
 * A null module key means the route is always available to authenticated
 * missionaries, so it returns true.
 */
export function canAccessModule(
  user: AuthUser | null | undefined,
  module: WorkspaceModuleKey | null
): boolean {
  if (module === null) return true;
  if (!user) return false;

  return hasAnyPermission(user, MODULE_PERMISSIONS[module]);
}
