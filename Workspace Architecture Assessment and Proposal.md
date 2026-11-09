# UU Mission — Role-Based Operational Workspaces

**Architecture Assessment and Implementation Proposal**

| | |
|---|---|
| Date | 2026-11-08 |
| Status | **Proposal only. No code, migration, permission or seed changes were made.** |
| Scope | `backend/` (Laravel) and `frontend/` (Next.js App Router, TypeScript) |
| Author | Architecture review |
| Related prior work | System-role scope derivation fix; Site Coordinator outreach + team-management fix |

---

## 0. Executive summary

UU Mission already has a **strong, scope-aware authorization engine** on the backend. The gap is almost entirely in the **frontend presentation layer**, which still selects a workspace with a *binary* switch (`primary_role === 'admin' | 'missionary'`) that predates the five-level scope model.

Concretely:

- The backend resolves authority by `scope_type` (`global → org_unit → campaign → site → team`) and is complete and consistent. **No authorization changes are required to introduce workspaces.**
- The frontend **already receives** everything a workspace resolver needs — `AuthUser.scopes: AuthScope[]` with `scope_type` and the concrete `campaign_id` / `site_id` / `team_id` — but **no code reads it**. Workspace selection instead uses the binary `primary_role`.
- Because of that binary, `campaign_leader`, `site_secretary`, `site_coordinator`, `team_leader` and `team_secretary` are all classified as `primary_role = 'missionary'` and are therefore **routed into the missionary workspace and blocked from `/admin` entirely** — including campaign leaders, who cannot reach campaign administration at all.
- `/admin` today contains **zero platform-administration pages**. All six admin pages (campaigns, people, sites, teams, reports, outcomes) are *field-operational*. There is no frontend at all for accounts, roles, organisation hierarchy or reference data.
- The recommended approach is therefore **not** "hide menu items". It is: introduce a first-class **workspace** concept derived from effective role assignments + scope, generalise the two existing shells into one context-driven shell, and re-home each module into the workspace that operationally owns it.

The implementation is **proportionate**: it changes presentation/routing and adds a small resolver, reusing every existing module, API, policy and test.

---

## 1. Current workspace architecture

### 1.1 How a workspace is selected today (the decisive finding)

Workspace selection is a **binary**, computed on the backend and enforced on the frontend.

**Backend** — `backend/app/Http/Controllers/Auth/AuthController.php` (lines ~580–595):

```php
$primaryRole = null;
foreach ($roleAssignments as $ra) {
    if (in_array($ra->role->code, ['platform_admin', 'admin', 'union_director', 'conference_director'])) {
        $primaryRole = 'admin';
        break;
    }
}
if (! $primaryRole && $roleAssignments->isNotEmpty() && $person && $person->missionaryProfile) {
    $primaryRole = 'missionary';
}
```

**Frontend** — `frontend/src/components/AuthGuard.tsx` (lines 22–37):

```ts
const isAdmin = user.primary_role === 'admin';
const isAdminRoute = pathname.startsWith('/admin');

// Admin trying to access missionary routes -> forced to /admin/campaigns
if (isAdmin && !isAdminRoute && !isPublicRoute) router.replace('/admin/campaigns');

// Non-admin (including null primary_role) trying to access admin routes -> /dashboard
if (!isAdmin && isAdminRoute) router.replace('/dashboard');
```

Consequences:

| Holder of role | `primary_role` value | Landing workspace | Can reach `/admin`? |
|---|---|---|---|
| `platform_admin`, `union_director` | `admin` | `/admin/campaigns` | Yes (forced) |
| `campaign_leader` | `missionary` | `/dashboard` | **No** (redirected) |
| `site_coordinator` | `missionary` | `/dashboard` | **No** |
| `site_secretary` | `missionary` | `/dashboard` | **No** |
| `team_leader`, `team_secretary` | `missionary` | `/dashboard` | **No** |
| `missionary` | `missionary` | `/dashboard` | No |

So the current architecture has effectively **two experiences** (missionary workspace + a generic admin console), not "role-based operational workspaces". A Site Coordinator's experience is the missionary experience — which is exactly the symptom described.

### 1.2 Route groups and layouts

| Group | Path prefix | Layout | Guard | Permission-aware? |
|---|---|---|---|---|
| Missionary | `/` (`(missionary)` route group) | `(missionary)/layout.tsx` → `MissionaryShell` | `AuthGuard` (auth only) | **Yes** (nav + page guard via `canAccessModule`) |
| Admin | `/admin/*` | `admin/layout.tsx` → `AppShell` | `AuthGuard` (auth only) | **No** (static nav, no per-page guard) |
| Public/auth | `/login`, `/signup`, `/register`, `/verify-email`, `/forgot-password`, `/reset-password`, `/accept-invite`, `/welcome`, `/offline` | root layout | none | n/a |

**Missionary shell** — `frontend/src/components/MissionaryShell.tsx`:
- `MAIN_NAV` (lines 31–36) and `MORE_NAV` (lines 38–46); entries with a `module` key are gated.
- `canSee = (item) => user ? canAccessModule(user, item.module ?? null) : true` (lines 70–71).
- **Page-level guard** (lines 81–82): `moduleAllowed = !user || canAccessModule(user, moduleForPath(pathname))` — the shell refuses to render a gated module the user cannot access. This is the correct pattern to generalise.

**Admin shell** — `frontend/src/components/AppShell.tsx` (lines 12–19):

```ts
const NAV = [
  { href: '/admin/campaigns', label: 'Campaigns',     icon: Globe },
  { href: '/admin/people',    label: 'People',        icon: Users },
  { href: '/admin/sites',     label: 'Mission Sites', icon: MapPin },
  { href: '/admin/teams',     label: 'Teams',         icon: Users },
  { href: '/admin/reports',   label: 'Field Reports', icon: FileText },
  { href: '/admin/outcomes',  label: 'Outcomes',      icon: Target },
];
```

Static, unfiltered. Combined with `AuthGuard` blocking all non-`admin` users, the practical effect is: the *only* people who see this console are platform/union level — while campaign leaders and site coordinators, the actual daily operators, cannot see it at all.

### 1.3 The user context the frontend already has

`frontend/src/lib/api.ts` — `AuthUser` (lines ~231–261) and `AuthScope` (lines ~219–229):

```ts
export interface AuthScope {
  role: string | null;
  role_label: string | null;
  scope_type: 'global' | 'org_unit' | 'campaign' | 'site' | 'team';
  org_unit_id: number | null;
  campaign_id: number | null;
  site_id: number | null;
  team_id: number | null;
  valid_from: string | null;
  valid_until: string | null;
}

export interface AuthUser {
  id: number;
  email: string;
  status: string;
  timezone: string;
  last_login_at: string;
  primary_role: 'admin' | 'missionary' | null;
  roles: Array<{ code: string; label: string; scope_type: string }>;
  /** Effective permission codes — the union across all active scopes. UI affordances ONLY. */
  permissions: string[];
  /** Active role assignments with their scopes. */
  scopes: AuthScope[];
  person: { id: number; first_name: string; last_name: string; full_name: string; gender: string; phone: string | null; photo_path: string | null; missionary_profile: any | null } | null;
}
```

**`scopes` is the missing key.** It is populated by `AuthorizationService::getEffectiveScopes()` and delivered by `/auth/me` and `/auth/login`. It already carries everything needed to answer "which workspaces does this person have, and at which campaign/site/team". Nothing consumes it today.

### 1.4 Answers to the five "determine" questions

1. **How is the current workspace selected?** By the binary `primary_role`, enforced by `AuthGuard` redirects. Not by role, permission or scope.
2. **Can a user hold multiple operational roles?** **Yes, on the backend** — `UserRoleAssignment` is many-per-user, and `AuthUser.roles[]` / `AuthUser.scopes[]` are arrays. The frontend flattens this into one binary and cannot represent it.
3. **Can the frontend distinguish campaign, site and team scopes?** **Structurally yes, behaviourally no.** `AuthScope` carries `scope_type` + ids, but no component reads them; `permissions` is a flat union with no scope dimension, so `hasPermission(user, 'assignments.manage')` cannot tell a site-scoped coordinator from a campaign-level manager.
4. **Does the architecture support multiple workspaces without duplicating layouts?** **Not yet.** There are two independent shells (`AppShell`, `MissionaryShell`) that share only the primitives in `frontend/src/components/ui.tsx`. Adding two more by copying would create four near-duplicate shells. A single context-driven shell is required.
5. **Which routes mix platform administration with field operations?** `/admin/*` — entirely. All six admin pages are operational; **none** are platform administration. Platform administration (accounts, RBAC, org hierarchy, reference data) has **no frontend at all**, despite the backend exposing `/rbac/*`, `/role-assignments/*`, `/invitations/*`, `/org-hierarchy`, `/ref/*`.

---

## 2. Current role and scope resolution mechanism

### 2.1 Scope model

Five scope types, ordered from broadest to narrowest:

`global` → `org_unit` → `campaign` → `site` → `team`

An assignment is a row in `user_role_assignments`:

| Column | Purpose |
|---|---|
| `user_id` | The account exercising the role |
| `system_role_id` | The role (see §2.2) |
| `scope_type` | One of the five values above |
| `scoped_org_unit_id`, `scoped_campaign_id`, `scoped_site_id`, `scoped_team_id` | Exactly one is populated, matching `scope_type` |
| `valid_from`, `valid_until` | Optional validity window |
| `role_permission_version_id` | The **immutable** permission snapshot this assignment is pinned to |
| `status` | `active` / `revoked` / `suspended` |
| `revoked_at`, `revoked_by_user_id`, `revocation_reason` | Revocation audit |
| `suspended_at`, `suspended_by_user_id`, `suspension_reason` | Suspension audit |

**Active determination** — `backend/app/Models/UserRoleAssignment.php`:

```php
public function isActive(): bool
{
    $today = now()->toDateString();
    $fromOk  = $this->valid_from  === null || $this->valid_from->toDateString()  <= $today;
    $untilOk = $this->valid_until === null || $this->valid_until->toDateString() >= $today;
    return $this->status === 'active' && $this->role?->is_active === true && $fromOk && $untilOk;
}
```

This single predicate is what makes "expired or revoked removes operational access without deleting history" true today. Workspace derivation must use exactly this predicate — never a raw `status` check.

### 2.2 Roles and their authoritative scope type

From `backend/database/seeders/DatabaseSeeder.php`:

| Role code | Label | `scope_type` | Workspace implication |
|---|---|---|---|
| `platform_admin` | Platform Administrator | `global` | Platform Administration |
| `union_director` | Union Mission Director | `org_unit` | Platform Administration / Campaign Mgmt |
| `campaign_leader` | Campaign Leader | `campaign` | Campaign Management |
| `site_coordinator` | Site Coordinator | `site` | Site Operations |
| `site_secretary` | Site Secretary | `site` | Site Operations |
| `missionary` | Missionary / Field Worker | `site` | Missionary |
| `team_leader` | Team Leader | `team` | Site Operations (team context) |
| `team_secretary` | Team Secretary | `team` | Site Operations (team context) |

Key principle already enforced: **a role's authority comes from the role's `scope_type`, not from the deployment context.** This was the fix applied in the immediately preceding task (`AssignmentController::resolveRoleScope`), and it is the foundation the workspace model must build on.

### 2.3 The matching engine

`AuthorizationService::resolveEffectiveAssignments($user, $campaign, $site = null, $team = null, $includeTeamScopedAssignments = true)` is the core. Matching rules:

| Assignment scope | Matches when |
|---|---|
| `global` | always |
| `org_unit` | the assignment's org unit is an ancestor of `$campaign->lead_org_unit_id` |
| `campaign` | `scoped_campaign_id === $campaign->id` **and** a site is allowed to be supplied |
| `site` | **`$site !== null`** and `scoped_site_id === $site->id` |
| `team` | `$includeTeamScopedAssignments` and the team matches (or the team belongs to the supplied site) |

**Critical consequence for workspaces:** a `site`-scoped assignment can only ever satisfy a check that supplies a site. `hasPermissionForCampaign()` therefore **never** succeeds for a site-scoped holder. This is why site-level roles must be routed to a *site-contextual* workspace, where a concrete site is always in hand — not to a campaign-wide console.

### 2.4 Permission versioning, compatibilities and delegations

- `role_permission_versions` are **immutable once published** (`RolePermissionVersion` throws `LogicException` on update/delete of a published row). Each assignment pins `role_permission_version_id` captured at assignment time; the authorization engine reads that pinned version. Practical effect: **changing a role's permissions does not silently change existing holders** — they must be re-assigned (or the version explicitly updated). This matters for rollout: after any permission change, existing assignments keep their old version.
- `role_scope_compatibilities` restricts which scope types a role may be assigned at.
- `permission_delegations` must contain a row for a permission at the target scope before the assignment can be created (`UserRoleAssignmentPolicy::create`).
- `user_role_assignment_overrides` allows per-assignment `grant`/`deny` on individual permissions; **deny wins** in `effectivePermissionDecision()`.

### 2.5 Effective permissions vs effective scopes

Two distinct outputs feed the frontend:

| Service method | Output | Contract |
|---|---|---|
| `getEffectivePermissions($user)` | flat, de-duplicated permission code list | **UI affordances only.** No scope dimension. |
| `getEffectiveScopes($user)` | active assignments with scope + ids | Describes the contexts in which the person operates |

The code comment in `AuthController` is explicit that `permissions` is *not* an authorization grant and the backend always re-authorizes per resource. **The workspace resolver should be built on `scopes` (and `isActive()` semantics), not on the flat `permissions` union** — otherwise a site-scoped coordinator would be offered a campaign-wide console because they happen to hold `assignments.manage` *somewhere*.

### 2.6 Context resolution that already exists

`backend/app/Http/Controllers/WorkspaceController.php`:

- `myAssignment()` — source 1: an active `CampaignAssignment` (`status ∈ {confirmed, arrived}`); source 2 (**fallback**): `resolveAssignmentContextFromRoles()`, which derives context from active role assignments in priority order **team → site → campaign → org_unit**. This is what makes the workspace usable for someone appointed organisationally without a campaign deployment.
- `mySites()` — returns authorised sites via `scopeQuery(..., getAuthorisedScopes($user, 'outcomes.record'), ...)`. **Note:** the scope key is `outcomes.record`, an unrelated permission; a site holder without `outcomes.record` gets zero rows.

Two limitations to design around:
1. `myAssignment()` returns only the **first** matching context — there is no multi-site selection.
2. `mySites()` is keyed on an incidental permission rather than a neutral "site membership" notion.

---

## 3. Current module-to-route map

### 3.1 Backend API surface (all under `/api/v1`, `auth:sanctum` unless noted)

| Module | Routes | Controller |
|---|---|---|
| Auth | `POST /auth/login`, `/auth/register`, `/auth/verify-email`, `/auth/forgot-password`, `/auth/reset-password` (public); `GET /auth/me`, `POST /auth/logout`, `/auth/logout-all`, `/auth/change-password` | `Auth\AuthController` |
| Campaigns | `GET/POST /campaigns`, `GET/PATCH /campaigns/{campaign}`, `GET /campaigns/{campaign}/stats`, `POST /campaigns/{campaign}/transition`, media attach/detach | `CampaignController` |
| Campaign objectives | `GET/POST /campaigns/{campaign}/objectives`, `PATCH/DELETE .../{objective}` | `CampaignObjectiveController` |
| Sites | `GET/POST /campaigns/{campaign}/sites`, `GET/PATCH/DELETE .../{site}`, `GET .../{site}/readiness`, `GET/PUT .../{site}/logistics`, media | `MissionSiteController`, `SiteLogisticsController` |
| Teams | `GET/POST /campaigns/{campaign}/teams`, `GET/PATCH .../{team}`, `POST/DELETE .../{team}/members[/{member}]`, media | `TeamController` |
| Activities | `GET/POST /campaigns/{campaign}/activities`, `GET/PATCH/DELETE .../{activity}`, media | `ActivityController` |
| Activity participants | `GET/POST .../activities/{activity}/participants`, `DELETE .../{participant}` | `ActivityParticipantController` |
| Activity objectives | `GET/POST .../activities/{activity}/objectives`, `PATCH/DELETE .../{objective}` | `ActivityObjectiveController` |
| Assignments (deployments) | `GET/POST /campaigns/{campaign}/assignments`, `GET/PATCH/DELETE .../{assignment}` | `AssignmentController` |
| Role assignments | `GET /campaigns/{campaign}/role-assignments`, `.../sites/{site}/role-assignments`, `.../teams/{team}/role-assignments`, `GET /org-units/{orgUnit}/role-assignments`; `POST /role-assignments`, `GET/PATCH/DELETE /role-assignments/{assignment}`, `PUT/DELETE .../permission-overrides[/{permission}]` | `UserRoleAssignmentController` |
| Field reports | `GET/POST .../reports`, `GET/PATCH .../{report}`, `GET .../{report}/preparation`, `POST .../prepare`, `/submit`, `/review`, `/forward`, `/consolidate`, `/recover`, `GET .../events` | `FieldReportController` |
| Outcomes | `GET/POST .../outcomes`, `GET .../{outcome}`, `POST .../{outcome}/follow-up`, `/complete-follow-up` | `MissionOutcomeController` |
| Attendance | `GET/POST /attendance-registers`, full workflow (submit/verify/reopen), entries | `AttendanceController` |
| Outreach contacts | `GET/POST /outreach-contacts`, `GET/PATCH/DELETE /outreach-contacts/{contact}` | `OutreachContactController` |
| Outreach encounters | `GET/POST /outreach-encounters`, `GET/PATCH/DELETE .../{encounter}`, `/complete`, `/reschedule` | `OutreachEncounterController` |
| Baptism records | `POST /baptism-records`, `POST /baptism-records/{record}/verify` | `BaptismRecordController` |
| People | `GET/POST /people`, `GET/PATCH/DELETE /people/{person}`, photo upload, duplicate check | `PersonController` |
| Workspace (self) | `GET /workspace/my-assignment`, `/my-team`, `/my-sites`, `/my-profile`, `PATCH /my-profile`, `/my-person`, languages/skills/experience/training/photo endpoints | `WorkspaceController` |
| Communications | `GET /communications`, `/unread-count`, `/requiring-acknowledgement`, `POST /communications`, `GET/PATCH/DELETE /{communication}`, `/read`, `/acknowledge` | `CommunicationController` |
| Resources | extensive `/resources/*` (categories, versions, files, downloads, distribution, saved, bookmarks, highlights, notes, reading progress, AI, conversations) | many |
| Org | `GET /org-hierarchy`, `/org-units/search`, `/org-units/{orgUnit}`, `/org-memberships/*` | `OrgHierarchyController`, `OrgMembershipController` |
| Offices / departments | `/offices/*`, `/office-types`, `/departments/*`, `/campaigns/{campaign}/departments` | `OfficeController`, `DepartmentController` |
| RBAC administration | `GET /rbac/roles`, `/rbac/roles/{role}/permission-versions`, `/rbac/permission-delegations`, `PUT /rbac/roles/{role}/permission-versions`, `PUT .../compatible-scopes`, `POST/DELETE /rbac/permission-delegations[/{delegation}]` | `SystemRoleAdministrationController` |
| Invitations | `/invitations/*` (admin) | `InvitationController` |
| Reference data | `GET /ref/skill-categories`, `/languages`, `/programme-types`, `/org-unit-types`, `/team-types`, `/activity-types`, `/functional-role-types`, `/system-roles`, `/office-types`, `/org-units`, `/outcome-types`, `/programmes`, `/persons`, `/encounter-methods`, `/engagement-stages` | inline / various |
| AI | `/ai/*`, `/translate/*`, `/transcribe`, `/synthesize` | `ResourceAIController`, `TranslationController` |

### 3.2 Frontend route map

| Group | Routes |
|---|---|
| `(missionary)` | `/dashboard`, `/mission`, `/team`, `/fieldwork`, `/people`, `/outcomes`, `/reports`, `/resources` (+`/my-resources`, `/store`, `/reviews`, `/reader/[id]`), `/communication`, `/media`, `/profile`, `/briefings`, `/translator` |
| `admin` | `/admin` (→ `/admin/campaigns`), `/admin/campaigns`, `/admin/campaigns/[id]`, `/admin/campaigns/[id]/sites/[siteId]`, `/admin/campaigns/new`, `/admin/people`, `/admin/sites`, `/admin/teams`, `/admin/reports`, `/admin/outcomes` |
| public | `/`, `/login`, `/register`, `/signup`, `/verify-email`, `/forgot-password`, `/reset-password`, `/accept-invite`, `/welcome`, `/offline` |

### 3.3 Observations

- The backend API is **already scope-correct at every endpoint** (verified: every controller authorizes through a policy; every policy resolves the resource's real scope through `AuthorizationService`). There is no policy gap and no controller authorizing against a missing policy.
- The frontend is the only place where scope is lost.
- Platform-administration capability exists **only** as API (`/rbac/*`, `/invitations/*`, `/org-hierarchy`, `/ref/*`) — there is no UI for it. Conversely the `/admin` UI contains **only** field-operational capability. The naming and the content are inverted.

---

## 4. Proposed workspace architecture

### 4.1 The four workspaces

| # | Workspace | Derivable from | Purpose |
|---|---|---|---|
| A | **Missionary** | any active assignment, or an onboarded missionary profile | Help an individual perform their mission work |
| B | **Site Operations** | ≥1 active `site`-scoped assignment (or `team`-scoped, resolved to its site) | Coordinate and monitor one assigned mission site |
| C | **Campaign Management** | ≥1 active `campaign`-scoped assignment (or `org_unit` covering a campaign) | Coordinate multiple sites and campaign-level operations |
| D | **Platform Administration** | `global`-scoped admin permission, or `org_unit` oversight | Administer the platform itself |

**Access is derived only from effective, active role assignments and their scopes** — never from organisational affiliation alone, never from a deployment record alone, never from a binary role label.

### 4.2 Deriving workspaces (the resolver)

Introduce a single pure function on the frontend, `resolveWorkspaces(user): WorkspaceDescriptor[]`, computed from `user.scopes` (already delivered) using the same active-window semantics as `UserRoleAssignment::isActive()`.

```
for each scope in user.scopes:
  skip if scope.valid_from > today or (scope.valid_until < today)   // mirror isActive()
  match scope.scope_type:
    'global'   -> Platform Administration (global)
    'org_unit' -> Platform Administration (oversight) + Campaign Management (if the unit leads a campaign)
    'campaign' -> Campaign Management  (context = scope.campaign_id)
    'site'     -> Site Operations      (context = scope.site_id)
    'team'     -> Site Operations      (context = team's site; team scoped for team-level screens)

always: Missionary (if the user has an onboarded missionary profile)
```

Each descriptor = `{ workspace, scopeType, campaignId, siteId, teamId, roleLabel }`. Multiple descriptors of the same workspace (e.g. two sites) collapse into one workspace with **multiple contexts** — this is what enables the site selector without separate accounts.

**Recommended:** have the **backend** compute and return this list (`workspaces: WorkspaceDescriptor[]`) inside `/auth/me` and `/auth/login`, so the rule lives in one place and cannot drift from the authorization engine. The frontend then renders whatever the backend declares. This is a small, additive change to `AuthController` (reuse `getEffectiveScopes()`), needs no migration, and keeps `AuthorizationService` authoritative.

### 4.3 Route structure (proposed)

| Workspace | Routes | Notes |
|---|---|---|
| Missionary | keep `(missionary)` group: `/dashboard`, `/mission`, `/team`, `/fieldwork`, `/people`, `/outcomes`, `/reports`, `/resources`, `/communication`, `/media`, `/profile` | unchanged |
| Site Operations | `/site/[siteId]/overview`, `/site/[siteId]/teams`, `/site/[siteId]/people`, `/site/[siteId]/activities`, `/site/[siteId]/attendance`, `/site/[siteId]/outreach`, `/site/[siteId]/reports`, `/site/[siteId]/outcomes`, `/site/[siteId]/logistics` | **new group** |
| Campaign Management | `/campaign/[campaignId]/overview`, `/campaign/[campaignId]/sites`, `/campaign/[campaignId]/teams`, `/campaign/[campaignId]/deployments`, `/campaign/[campaignId]/activities`, `/campaign/[campaignId]/reports`, `/campaign/[campaignId]/outcomes`, `/campaign/[campaignId]/communications` | **new group** |
| Platform Administration | `/admin/*` **narrowed** to `/admin/accounts`, `/admin/roles`, `/admin/organisation`, `/admin/reference` | repurposed |

The existing `/admin/campaigns*`, `/admin/sites`, `/admin/teams`, `/admin/reports`, `/admin/outcomes` pages are **moved** into Campaign Management (and the site-scoped slices into Site Operations). Recommendation: keep the old paths as thin redirects for one release to avoid dead bookmarks.

### 4.4 Workspace UX (§7 of the brief)

**One shell, four configurations.** Generalise `AppShell`/`MissionaryShell` into a single `WorkspaceShell` driven by a `WorkspaceDefinition`:

```ts
type WorkspaceDefinition = {
  key: 'missionary' | 'site_operations' | 'campaign_management' | 'platform_admin';
  label: string;
  nav: Array<{ href: string; label: string; icon: LucideIcon; module?: WorkspaceModuleKey; permission?: string }>;
  contextSelector: 'none' | 'site' | 'campaign';
  accent: string; // theme token
};
```

Visit order, hierarchy and behaviour:

- **Shell header:** active role + scope chip (e.g. `Site Coordinator · Lira Town Mission Site`), workspace switcher (only when >1), context selector (site/campaign) when >1, profile menu.
- **Each workspace has its own dashboard**, with a different question at the top:
  - Missionary dashboard answers *"What do I need to do today?"* — my assignment, my activities, my follow-ups, my report deadlines, required acknowledgements.
  - Site Operations dashboard answers *"Is my site healthy and on plan?"* — personnel on site, teams, today's activities, attendance status (submitted / verified / overdue), outreach funnel and follow-up backlog, report review queue, readiness/logistics gaps.
  - Campaign Management dashboard answers *"How are my sites performing?"* — per-site operational health, cross-site attendance/outcome aggregates, deployment pipeline, resource distribution status, reports awaiting consolidation.
  - Platform Administration dashboard answers *"Is the platform configured and sound?"* — accounts, role/permission drift, organisation integrity, reference-data completeness.
- **Quick actions** are workspace-specific and permission-gated: Site Ops → *Add team*, *Take attendance*, *Verify register*, *Register outreach contact*; Campaign Mgmt → *Add site*, *Deploy personnel*, *Consolidate reports*, *Distribute resources*; Platform → *Invite user*, *Publish role permissions*.
- **Mobile:** the existing `MissionaryShell` pattern (primary nav + "More" sheet + profile sheet) becomes the shared mobile pattern; context selector collapses into a header chip that opens a bottom sheet.
- **Empty / error states:** distinguish (a) *no operational assignment* → "You are not currently deployed; contact your coordinator", (b) *expired/revoked while browsing* → re-fetch `me`, drop the workspace, redirect to the next available one, (c) *403 from the API* → render the same `ErrorBanner` used today, but ideally such states should be unreachable because visibility is scope-derived.
- **Not just renamed dashboards:** each workspace surfaces a different information hierarchy and different quick actions; shared modules (Resources, Communications) appear as a shared rail rather than being re-implemented per workspace.

---

## 5. Workspace access and switching rules

### 5.1 Access rules

1. Workspace access is computed from **`isActive()`-qualifying assignments only**. Expired or revoked assignments grant nothing and remove the workspace on the next `me` refresh.
2. The **backend remains authoritative** at every endpoint. Workspace visibility is an affordance; the API still authorizes per resource.
3. A **site-scoped holder accesses Site Operations only for their assigned site(s)**. Because `hasPermissionForCampaign` can never be satisfied by a `site` assignment (§2.3), site roles must never be routed to a campaign-wide console.
4. A person may hold **several site contexts** → one Site Operations workspace with a site selector. **Never separate accounts.**
5. **Deployment facts stay separate from system-role authority.** A `CampaignAssignment` describes *where someone is deployed*; `UserRoleAssignment` describes *what they are authorised to do*. The workspace resolver reads authority from role assignments; the deployment supplies display context only.
6. **Organisational membership alone grants no workspace.** `org_memberships` must not imply operational access (the existing "a missionary account must not gain administrative access because it belongs to an organisation" principle).
7. A person may legitimately hold **zero operational workspaces** → show a neutral "no current assignment" state, not a broken dashboard.

### 5.2 Landing and switching

| Situation | Behaviour |
|---|---|
| Exactly one workspace | Land directly in it. **No switcher, no selection screen.** |
| Several workspaces | Land in the highest-priority one; show a switcher listing the rest. Suggested priority: Platform Administration → Campaign Management → Site Operations → Missionary. |
| Several site contexts | Site selector inside Site Operations; remember the last chosen site per user. |
| Campaign-level officer with several campaigns | Campaign selector inside Campaign Management. |
| Last-used workspace | Persist per user id in `localStorage`; honour it on return if still valid. |
| Role revoked/expired mid-session | On `me` refresh, drop the descriptor; redirect to the next available workspace; if none, the neutral state. |
| Only missionary access | Missionary workspace; no switcher. |

Never create separate accounts or duplicate identities for different roles (constraint honoured).

---

## 6. Module ownership and access matrix

Legend: **M** = manage/approve, **E** = edit, **C** = create, **R** = read/review, **—** = not applicable.

| Module | Primary workspace | Other workspaces | Roles | Access level | Authorisation scope | Backend | Frontend |
|---|---|---|---|---|---|---|---|
| Campaigns | Campaign Management | Missionary (R), Platform (R) | campaign_leader, union_director, platform_admin | M | `campaign` / `org_unit` / `global` | `CampaignController`, `CampaignPolicy` | `admin/campaigns*` → move |
| Sites | Site Operations | Campaign Mgmt (M), Platform (R) | site_coordinator, site_secretary, campaign_leader | M | `site` / `campaign` | `MissionSiteController`, `SiteLogisticsController`, `MissionSitePolicy` | `admin/sites`, `admin/campaigns/[id]/sites/[siteId]` |
| Teams | Site Operations | Campaign Mgmt (M campaign-wide), Missionary (R own team) | site_coordinator, campaign_leader, team_leader | M | `site` / `campaign` / `team` | `TeamController`, `TeamPolicy` | `admin/teams`, `admin/campaigns/[id]` |
| People | Missionary | Site Ops (E), Campaign Mgmt (E), Platform (M accounts) | any with `people.*` | C/E/R | `site` / `campaign` | `PersonController`, `PersonPolicy` | `admin/people`, `(missionary)/people` |
| Outreach contacts | Missionary | Site Ops (R/E oversight), Campaign Mgmt (R) | missionary, site_coordinator, site_secretary | C/E/R | `site` / `campaign` | `OutreachContactController`, `OutreachEncounterController`, policies | `(missionary)/people`, `AddOutreachContactModal` |
| Activities | Missionary | Site Ops (M), Campaign Mgmt (M) | missionary, site_coordinator, team_leader | C/E/R | `site` / `team` | `ActivityController`, `ActivityParticipantController`, `ActivityObjectiveController`, `ActivityPolicy` | `(missionary)/fieldwork` |
| Attendance | Missionary (submit) | Site Ops (verify/monitor), Campaign Mgmt (R) | site_secretary, site_coordinator | C/R/verify | `site` | `AttendanceController`, `AttendanceRegisterPolicy` | `(missionary)/fieldwork` |
| Field reports | Missionary (submit) | Site Ops (review own site), Campaign Mgmt (consolidate) | missionary, site_secretary, campaign_leader | C/R/M | `site` / `campaign` / `team` | `FieldReportController`, `ReportPreparationService`, `FieldReportPolicy` | `(missionary)/reports`, `admin/reports` |
| Outcomes | Missionary | Site Ops (monitor), Campaign Mgmt (R) | missionary, site_coordinator | C/E/R | `site` / `campaign` | `MissionOutcomeController`, `MissionOutcomePolicy` | `(missionary)/outcomes`, `admin/outcomes` |
| Resources | Shared (all workspaces) | Platform (publish/archive) | all | R; C/E for `resources.create/manage`; approve for `resources.approve_distribute` | `global` / platform | `Resource*Controller(s)`, `ResourcePolicy` | `(missionary)/resources*` |
| Communication Centre | Shared (all workspaces) | Platform (revoke) | all; publish for authorised | R; publish; acknowledge; revoke | `global` / platform | `CommunicationController`, `CommunicationPolicy` | `(missionary)/communication` |
| Learning Centre | Missionary | shared | all | R | n/a | resource APIs | `(missionary)/resources/*` |
| Profile & missionary history | Missionary | — | all | R/E | self | `WorkspaceController`, `PersonController` | `(missionary)/profile` |
| Logistics & Site readiness | Site Operations | Campaign Mgmt (R) | site_coordinator, site_secretary | E/R | `site` | `SiteLogisticsController`, `MissionSiteController::readiness` | `admin/campaigns/[id]/sites/[siteId]` |
| Role & permission administration | Platform Administration | — | platform_admin | M | `global` | `SystemRoleAdministrationController`, `UserRoleAssignmentController`, policies | **none exists** |
| Accounts & invitations | Platform Administration | — | platform_admin | M | `global` | `InvitationController`, `AuthController` | **none exists** |
| Organisation hierarchy | Platform Administration | Campaign Mgmt (R) | platform_admin, union_director | M/R | `global` / `org_unit` | `OrgHierarchyController`, `OrgMembershipController` | **none exists** |
| Reference data | Platform Administration | read-only everywhere | platform_admin | M | `global` | `/ref/*` | partial (dropdown loaders) |

**Note on sharing:** modules listed in "Other workspaces" are **the same implementation surfaced with a different context and permission gate** — not duplicated functionality. This satisfies the constraint that a module may be reachable from multiple workspaces without duplication.

---

## 7. Site Coordinator workspace proposal (§1B)

### 7.1 Primary role

`site_coordinator` (scope `site`). Also hosts `site_secretary` (`site`) and, in team context, `team_leader` / `team_secretary` (`team`, resolved to their site). The workspace **coordinates and monitors**; it does **not** duplicate Team Leader or Site Secretary duties — it supervises them.

### 7.2 Navigation

```
Overview | People | Teams | Activities | Attendance | Outreach | Reports | Outcomes | Logistics
```

Every entry is gated by a permission resolved **at the active site**. The site is fixed by the workspace context and carried in the route (`/site/[siteId]/...`), so no screen can be reached without a real, authorised site in hand.

### 7.3 Dashboard (information hierarchy)

1. **Operational indicators** row: personnel on site / expected, teams active, today's activities, attendance registers (submitted / verified / overdue), outreach contacts this period, report review queue.
2. **Attention list** (actionable): registers awaiting verification, reports awaiting review, follow-ups overdue, activities with no attendance recorded, logistics gaps from readiness.
3. **Site roster & teams** summary with links into Teams/People.
4. **Recent outreach activity** (contacts + encounter outcome mix).
5. **Mission progress** (site outcomes vs objectives).

### 7.4 Capabilities and the permissions that back them

| Capability | Permission (at site scope) |
|---|---|
| Site overview / edit site details | `sites.view`, `sites.edit` |
| Personnel & team management; deploy within own site | `assignments.view`, `assignments.manage` |
| Site activities & scheduling | `activities.view/create/update/delete` |
| Attendance monitoring (submit + verify) | `attendance.submit`, `attendance.verify`, `attendance.view` |
| Outreach & follow-up oversight | `outreach-contacts.view/create/edit`, `outcomes.manage_followup` |
| Team report review (own site) | `report.view`, `report.review`, `report.submit` |
| Site outcomes & mission progress | `outcomes.record`, `outcomes.view` |
| Site logistics & readiness | `sites.view`, `sites.edit` |

**Deliberately withheld** (belongs to Campaign Management / Platform): `sites.create`, `campaigns.create/edit/archive`, `resources.publish`, `resources.archive`, `communications.revoke`, `report.consolidate` at campaign level.

### 7.5 Explicit non-goals

Must **not** duplicate Team Leader tasks (leading a specific team's day-to-day) or Site Secretary tasks (secretarial administration of the site register). The coordinator's screens aggregate and supervise those.

---

## 8. Campaign Management workspace proposal (§1C)

### 8.1 Primary roles

`campaign_leader` (scope `campaign`), `union_director` (scope `org_unit`, overseeing campaigns led by their unit).

### 8.2 Navigation

```
Overview | Sites | Teams | Deployments | Activities | Reports | Outcomes | Resources | Communications
```

### 8.3 Dashboard

1. **Multi-site operational health** table: per site — personnel/expected, attendance verified %, outreach contacts, outcomes, open reports.
2. **Deployment pipeline** (confirmed / arrived / pending), with role coverage gaps per site.
3. **Cross-site reporting** status: reports awaiting consolidation, overdue reviews.
4. **Resource distribution** status across sites.
5. **Campaign objective progress**.
6. **Quick actions:** add site, deploy personnel, create campaign-wide team, consolidate reports, distribute resources, publish campaign communication.

### 8.4 Distinction from Site Operations

| Concern | Site Operations | Campaign Management |
|---|---|---|
| View | one site | all sites in the campaign |
| Team creation | site-scoped only | site-scoped **and** campaign-wide |
| Site lifecycle | edit own site | create / archive sites |
| Reports | review own site | consolidate across sites |
| Resources | distribute | oversight + publish |
| Communications | receive / publish at site | campaign-level publish |

---

## 9. Platform Administration workspace proposal (§1D)

### 9.1 Purpose

Administer the platform itself — **not** field operations. This is the workspace that today exists only as API.

### 9.2 Navigation

```
Overview | Accounts | Roles & Permissions | Organisation | Reference Data
```

### 9.3 Ownership

| Module | Contents | Existing backend |
|---|---|---|
| Accounts | user accounts, invitations, account status | `/invitations/*`, `AuthController` |
| Roles & Permissions | system roles, permission versions, compatible scopes, delegations, per-assignment overrides | `/rbac/*`, `/role-assignments/*` |
| Organisation | org hierarchy, org memberships/approvals, offices | `/org-hierarchy`, `/org-memberships/*`, `/offices/*` |
| Reference Data | activity types, team types, functional role types, office types, outcome types, engagement stages, encounter methods, programmes | `/ref/*` |

### 9.4 Dashboard

Accounts without a linked Person, role permission-version drift (holders on stale versions), delegations expiring, org units without a lead, incomplete reference data.

### 9.5 The `/admin` re-homing (§1D "do not assume every current `/admin` page belongs here")

| Current `/admin` page | True owner | Destination |
|---|---|---|
| `/admin/campaigns`, `/admin/campaigns/[id]`, `/admin/campaigns/new` | Campaign Management | `/campaign/[campaignId]/*` |
| `/admin/sites` | Site Operations (site) + Campaign Mgmt (create) | `/site/[siteId]/overview`, `/campaign/[campaignId]/sites` |
| `/admin/teams` | Site Operations + Campaign Management | `/site/[siteId]/teams`, `/campaign/[campaignId]/teams` |
| `/admin/reports` | Site Operations (review) + Campaign Mgmt (consolidate) | site/campaign report screens |
| `/admin/outcomes` | Site Operations + Campaign Mgmt | site/campaign outcome screens |
| `/admin/people` | Missionary (neutral identity) + Site Ops + Platform (accounts) | split: `people` in missionary/site; accounts in Platform |
| *(none)* | accounts, roles, org, reference | **new** Platform Administration pages |

---

## 10. Resolution of the four known UI gaps

These are resolved **by the architecture**, not patched independently.

### Gap 1 — Admin navigation is static and not permission-filtered

**Resolution:** replace the static `NAV` array with **per-workspace navigation definitions**, each entry carrying either a `module` (resolved by `canAccessModule`) or an explicit `permission` (resolved by a new scope-aware `can(code, context)` helper). The single `WorkspaceShell` renders only the nav of the active workspace, and only the entries the user is authorised for **in that workspace's scope**. The Platform Administration nav is gated on `admin.*`; the operational navs are gated on module permissions resolved at the workspace's site/campaign. This generalises the pattern `MissionaryShell` already proves works, and removes the need for `AuthGuard`'s binary redirect (§1.1).

### Gap 2 — Create Team not gated and defaults to campaign-wide

**Resolution:** team management is **re-homed by workspace**:
- In **Site Operations**, the site is fixed by the route (`/site/[siteId]/teams`); the form has **no site dropdown and no campaign-wide option** — a site-scoped coordinator *cannot* submit a campaign-wide team because the payload is built from the workspace context, not a free choice. The control is gated on `assignments.manage` resolved **at that site**.
- In **Campaign Management**, both site-scoped and campaign-wide team creation are offered, gated on `assignments.manage` at campaign scope.
- The backend already rejects the invalid case (403 + the rewritten `TeamPolicy`/`TeamController`); this change makes it **unreachable and unadvertised** in the UI.

### Gap 3 — Some permission codes are not used as frontend action gates

**Resolution:** introduce one scope-aware helper and use it for every action control, instead of ad-hoc `hasPermission(user, 'x')`:

```ts
// conceptual
can(user, permission, ctx?: { campaignId?, siteId?, teamId? }): boolean
```

It mirrors the backend precedence (site assignment only matches when a site is supplied; campaign assignment matches broadly; global matches always) using `user.scopes`. Every create / edit / verify / approve / publish control is then driven by `can(...)` with the **workspace's own context**. This closes the gap where e.g. `people.create`, `activities.create`, `report.review`, `assignments.manage`, `sites.edit` are never used as gates today. Backend authorization remains authoritative.

### Gap 4 — Missionary outreach form depends on deployment context

**Resolution:** sources the operational context from the **workspace context** rather than the deployment record:
- In **Site Operations**, the site is the route context — unambiguous, always authorised.
- In the **Missionary** workspace, keep deployment-first but rely on the documented role-derived fallback (`WorkspaceController::resolveAssignmentContextFromRoles`, priority team → site → campaign → org_unit). Where a person holds several contexts, present the selector rather than silently taking the first.
- Never send arbitrary frontend-supplied IDs: the context is always one the user is authorised for.

**Related hardening (recommended, not a "gap" item):** `workspaceApi.mySites()` is keyed on `outcomes.record`; it should be keyed on a neutral notion of site membership so that site holders without `outcomes.record` still see their sites. Surfaced for approval in §13.

---

## 11. Phased implementation plan

Reuses every existing module. No module is rebuilt. No migration is required for Phases 1–4 as described (the resolver reads existing `scopes`).

### Phase 1 — Workspace foundation

- **Reuse:** `AppShell`, `MissionaryShell`, `ui.tsx`, `permissions.ts`, `AuthScope`, `/auth/me`.
- **Backend changes:** add a `workspaces` descriptor array to `AuthController::login()` and `me()` (computed from `getEffectiveScopes()`); optionally fix `mySites()` scope key. Additive, no migration.
- **Frontend changes:** new `lib/workspaces.ts` (`resolveWorkspaces`, `defaultWorkspace`); unify shells into `WorkspaceShell` + `WorkspaceDefinition`; replace `AuthGuard`'s binary redirect with descriptor-based routing; add the workspace switcher and context selector; add `can(permission, ctx)` in `permissions.ts`.
- **Tests:** new `WorkspaceResolutionTest` (backend) — descriptor derivation per role/scope, expired/revoked exclusion, multi-site, zero-workspace case. Frontend: unit tests for `resolveWorkspaces` and `can`.
- **Risks:** behavioural change to `AuthGuard` could redirect existing admin users differently — mitigate by mapping `primary_role = 'admin'` to a descriptor during transition.

### Phase 2 — Site Operations

- **Reuse:** `MissionSiteController`, `TeamController`, `PersonController`, `OutreachContactController`, `ActivityController`, `AttendanceController`, `FieldReportController`, `MissionOutcomeController`, `SiteLogisticsController`, and all their policies.
- **Backend changes:** none required beyond Phase 1 (site-scoped policies already correct).
- **Frontend changes:** new `/site/[siteId]/*` route group; site dashboard; re-homed Teams (site-fixed), People, Activities, Attendance, Outreach, Reports (review queue), Outcomes, Logistics; per-site nav gating.
- **Tests:** reuse `ScopedAuthorizationTest` section J (site coordinator own-site vs sibling-site) and extend with workspace-route tests.
- **Risks:** duplicating Team Leader/Site Secretary screens — guard by keeping the coordinator's screens supervisory, linking into existing detail views.

### Phase 3 — Campaign Management

- **Reuse:** `CampaignController`, `MissionSiteController`, `TeamController`, `AssignmentController`, `FieldReportController` (consolidate), `ResourceDistributionController`, `CommunicationController`.
- **Backend changes:** none strictly required; the campaign-wide vs site-scoped distinction is already enforced in `TeamPolicy`/`TeamController`.
- **Frontend changes:** new `/campaign/[campaignId]/*` group; multi-site overview; campaign-wide team creation; deployments; consolidation/oversight screens; migrate `/admin/campaigns*`, `/admin/sites`, `/admin/teams`, `/admin/reports`, `/admin/outcomes` and leave redirects.
- **Tests:** extend `OfficeAndRoleAssignmentTest` / `RbacApiAcceptanceVerificationTest` for campaign-wide team creation by campaign-scope vs site-scope.
- **Risks:** breaking existing `/admin` bookmarks — mitigated by redirects.

### Phase 4 — Platform Administration

- **Reuse:** `SystemRoleAdministrationController`, `UserRoleAssignmentController`, `InvitationController`, `OrgHierarchyController`, `OrgMembershipController`, `OfficeController`, `/ref/*`.
- **Backend changes:** none (API complete).
- **Frontend changes:** new `/admin/accounts`, `/admin/roles`, `/admin/organisation`, `/admin/reference` pages; narrow `/admin` to platform concerns; gate on `admin.*`.
- **Tests:** `FoundationalRbacTest`, `RbacApiAcceptanceVerificationTest` already cover the API; add UI-level acceptance where applicable.
- **Risks:** this is net-new UI surface — scope it tightly (read + the operations already supported by the API).

**Phase ordering rationale:** Phase 1 is a prerequisite (nothing else can route correctly without the resolver). Phase 2 delivers the most-felt fix (Site Coordinator). Phase 3 depends on Phase 1's route groups. Phase 4 is independent and can proceed in parallel once Phase 1 lands.

---

## 12. Exact files, APIs, policies and tests affected

### 12.1 Backend — files

| File | Change (proposed) |
|---|---|
| `backend/app/Http/Controllers/Auth/AuthController.php` | add `workspaces[]` to the `me()`/`login()` payload (from `getEffectiveScopes()`); leave `primary_role` during transition |
| `backend/app/Services/AuthorizationService.php` | **no change** (read-only source of truth); optionally expose a `describeWorkspaces()` helper |
| `backend/app/Http/Controllers/WorkspaceController.php` | consider a neutral scope key for `mySites()` (currently `outcomes.record`); consider returning **all** contexts, not just the first |
| `backend/app/Models/UserRoleAssignment.php` | no change (`isActive()` is the contract) |

### 12.2 Backend — APIs

No new endpoints strictly required. Additive fields on `/auth/me` and `/auth/login`. Optional: `GET /workspace/contexts` returning all authorised contexts.

### 12.3 Backend — policies

**No policy changes.** All policies already resolve scope through `AuthorizationService`. `TeamPolicy` and `TeamController` were corrected in the immediately preceding task and are the model to follow.

### 12.4 Frontend — files

| File | Change (proposed) |
|---|---|
| `frontend/src/lib/api.ts` | extend `AuthUser` with `workspaces?: WorkspaceDescriptor[]`; possibly fix `workspaceApi.mySites` usage |
| `frontend/src/lib/permissions.ts` | add `can(user, permission, ctx)`; keep `canAccessModule` |
| `frontend/src/lib/workspaces.ts` | **new** — `resolveWorkspaces`, `defaultWorkspace`, `WorkspaceDefinition` |
| `frontend/src/components/AuthGuard.tsx` | replace binary redirect with descriptor-based routing |
| `frontend/src/components/AppShell.tsx` | superseded by `WorkspaceShell` (keep as thin wrapper or remove) |
| `frontend/src/components/MissionaryShell.tsx` | superseded by `WorkspaceShell` |
| `frontend/src/components/WorkspaceShell.tsx` | **new** — context-driven shell + switcher + context selector |
| `frontend/src/lib/workspace-definitions.ts` | **new** — the four nav definitions |
| `frontend/src/app/site/[siteId]/**` | **new** — Site Operations group |
| `frontend/src/app/campaign/[campaignId]/**` | **new** — Campaign Management group |
| `frontend/src/app/admin/**` | narrowed to Platform Administration; operational pages moved + redirected |
| `frontend/src/components/admin/AddOutreachContactModal.tsx` | take context from the workspace, not a free campaign/site choice |
| `frontend/src/app/(missionary)/people/page.tsx` | context from workspace context (deployment-first, role fallback) |

### 12.5 Tests

| Test | Role |
|---|---|
| `backend/tests/Feature/ScopedAuthorizationTest.php` | **existing** — already proves site-scoped coordinator own-site vs sibling-site for teams and outreach (section J). Extend for campaign-wide team creation by a site-scoped holder (must 403). |
| `backend/tests/Feature/OfficeAndRoleAssignmentTest.php` | **existing** — role/scope assignment integrity; extend for workspace descriptor derivation. |
| `backend/tests/Feature/RbacApiAcceptanceVerificationTest.php` | **existing** — RBAC API acceptance; extend for `/auth/me` `workspaces[]`. |
| `backend/tests/Feature/FoundationalRbacTest.php` | **existing** — scope resolution primitives. |
| `backend/tests/Feature/OrgUnitScopeAndReferenceDataTest.php` | **existing** — org-unit scoping (used by union/org workspace derivation). |
| `backend/tests/Feature/WorkspaceResolutionTest.php` | **new** — descriptor derivation: per role, per scope, expired/revoked exclusion, multi-site, zero-workspace. |
| frontend `resolveWorkspaces` / `can` unit tests | **new** — pure-function tests. |

### 12.6 Reuse inventory (nothing rebuilt)

Every module listed in §6 keeps its existing controller, policy, model, service and API. Workspaces change **where a module is presented and with which context**, not what it does.

---

## 13. Architectural decisions requiring approval

| # | Decision | Recommendation | Alternatives |
|---|---|---|---|
| 1 | Where does the workspace-derivation rule live? | **Backend** (`/auth/me` returns `workspaces[]`), so it cannot drift from `isActive()`/scope semantics | Compute client-side from `scopes` (simpler, but duplicates the active-window rule) |
| 2 | Route shape for scoped workspaces | **Path segments** `/site/[siteId]/...`, `/campaign/[campaignId]/...` | Query params (`?site=`) — weaker for deep-linking and share links |
| 3 | Fate of `primary_role` | Keep during transition as a compatibility shim; deprecate once descriptors are live | Remove immediately (riskier) |
| 4 | Fate of existing `/admin/*` operational URLs | Move to new groups and leave **redirects** | Hard move (breaks bookmarks) |
| 5 | Does a person with zero operational assignments see anything? | A neutral "no current assignment" state, not a broken dashboard | Deny login (rejected — violates "one identity") |
| 6 | Should `workspaceApi.mySites()` stop being keyed on `outcomes.record`? | **Yes** — key on a neutral site-membership notion | Leave as-is (site holders without `outcomes.record` see no sites) |
| 7 | Should `myAssignment()` return all contexts or just the first? | Return **all** contexts (feeds the site/campaign selector) | Keep first-only (forces a new endpoint later) |
| 8 | Scope of Phase 4 Platform Administration | Read + the operations the API already supports | Full admin build-out now (larger than the current product stage) |
| 9 | Should `campaign_leader` gain access to any `/admin` page during transition? | No — route them to Campaign Management from Phase 1 | Keep them in missionary workspace until Phase 3 |
| 10 | Workspace priority order for landing | Platform → Campaign → Site → Missionary | Configurable per deployment |

---

## Appendix A — Verified evidence index

| Claim | Evidence |
|---|---|
| Workspace selection is binary | `AuthController.php` lines ~580–595; `AuthGuard.tsx` lines 22–37 |
| Admin nav static / unfiltered | `AppShell.tsx` lines 12–19; `admin/layout.tsx` lines 1–11 |
| Missionary nav filtered + page-guarded | `MissionaryShell.tsx` lines 31–46, 70–71, 81–82 |
| Frontend already receives scope data | `api.ts` `AuthUser`/`AuthScope` (~lines 219–261); `AuthController` `getEffectiveScopes()` |
| `site` scope requires a supplied site | `AuthorizationService.php` `resolveEffectiveAssignments()` (~lines 848–883) |
| Active-window predicate | `UserRoleAssignment.php` `isActive()` (~lines 99–106) |
| Immutable permission versions | `RolePermissionVersion.php` (~lines 16–30) |
| Deployment vs role context | `WorkspaceController.php` `myAssignment()` (~52–85), `resolveAssignmentContextFromRoles()` (~154–215) |
| `mySites` keyed on `outcomes.record` | `WorkspaceController.php` `mySites()` (~415–443) |
| Roles + scope types | `DatabaseSeeder.php` role/permission blocks (~lines 683–946) |
| Team creation UI unguarded, defaults campaign-wide | `admin/campaigns/[id]/page.tsx` (~585–590, 655–673) |
| Outreach creation UIs | `AddOutreachContactModal.tsx` (~70–78); `(missionary)/people/page.tsx` (~191–200) |

## Appendix B — Constraints honoured by this proposal

- Inspection and recommendations only — no code, migration, permission or seed changes made.
- No completed module rebuilt.
- No functionality duplicated into separate applications (shared modules re-surfaced per workspace).
- Backend authorization not weakened — it remains the single source of truth.
- Operational access is **not** derived from organisational affiliation alone.
- The model does **not** assume one role per person.
- Scope kept proportionate: Phase 1 is a resolver + shell unification; later phases re-home existing pages.
