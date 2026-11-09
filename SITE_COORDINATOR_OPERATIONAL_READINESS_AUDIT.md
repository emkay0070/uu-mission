# UU Mission — Site Coordinator Workspace Operational Readiness Audit

**Audit type:** Read-only. No fixes, refactors, migrations, permissions or UI components were created.
**Repository state:** working tree as inspected this session (backend Laravel 11 / PHP 8.3 / PostgreSQL; frontend Next.js App Router / TypeScript / Turbopack).
**Primary evidence type:** static code inspection with file:line citations. See §6 for what was *not* executed and why.

> Continuation note: this report completes an audit that a previous run began and abandoned mid–evidence-gathering ("Abnormally stopped ~14%"), before any deliverable was written. Every claim below was re-derived from the current repository, not from prior reports.

---

## 1. Executive summary

**Is the Site Coordinator workspace operationally ready for demonstration? No — not for the full responsibility set.**

What is genuinely demonstrable today:

- Opening the workspace, seeing the correct site identity, switching between multiple authorised sites, and seeing a truthful "what needs your attention" overview — all work.
- Listing People, Teams, Activities, Attendance registers, Outreach contacts, Reports and Outcomes — all work, all correctly scoped to the active site.
- Creating a team at the active site — works (UI + backend + test).
- Registering an outreach contact at the active site — works (UI + backend).
- Verifying a submitted attendance register — works (UI + backend).
- Submitting / approving / returning a report inline — works (UI + backend).

What is *not* demonstrable, and why the workspace is **not** ready:

- **P0 — a control that always fails.** The Outcomes page shows "Done / Lost" follow-up buttons to any coordinator holding `outcomes.manage_followup` at their site (which the seeded Site Coordinator role does), but the backend authorises that action **campaign-only**, so every click returns 403. See §4-B1 and §5-F1.
- **P0 — team workflow dead-ends.** A coordinator can *create* a team but **cannot open it, edit it, appoint a leader, add or remove members, or view the roster** — there is no team-detail UI anywhere in the frontend for any role. See §4-B2.
- **P1 — every list is a dead end.** Attendance registers, reports, outcomes and activities render as non-clickable cards. There is no detail route, so a coordinator cannot inspect an attendance register's entries, a report's contents, an outcome, or an activity.
- **P1 — creation is mostly absent.** Site-initiated activity creation, outcome recording, outreach contact editing, engagement-stage changes and logistics capture have **no UI in the Site Operations workspace** (although the backend and API functions exist for most of them).
- **P1 — readiness is a placeholder.** `/site-operations/logistics` states "Readiness is not measured yet", yet the backend logistics (`SiteLogisticsController@show/upsert`) and readiness (`MissionSiteController@readiness`) endpoints exist and are authorised.

**Overall verdict:** the Site Operations workspace is a **strong read-oriented shell with two genuine defects and a broad, systematic UI surface gap**. It presents data honestly and never fabricates, which is a real strength — but as "the Site Coordinator can perform the work expected of them", it fails on team management, attendance inspection, report/outcome/activity detail, outcome follow-up, and site readiness.

---

## 2. Existing implementation (what already works)

### 2.1 Workspace resolution and access

- Workspaces are resolved by [WorkspaceResolver.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Services/WorkspaceResolver.php). `siteOperations()` (L208–225) qualifies a user only when they hold one of `SITE_OPERATIONS_PERMISSIONS` (L93–105) **at `scope_type = 'site'`**. Notably, `outcomes.manage_followup` is listed at L102 — the workspace *intends* site coordinators to manage follow-ups (the crux of defect §5-F1).
- The route group is a dedicated layout: [layout.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/layout.tsx) wraps children in `AuthGuard` → `SiteOperationsShell`.

### 2.2 Shell, navigation and multi-site context

- [SiteOperationsShell.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/SiteOperationsShell.tsx) defines `SITE_NAV` (L41–91) and filters each entry by `canIn(permission)` **for the active site** (L126–128). It renders a site selector only when more than one site is authorised (`contexts.length > 1`, L149–165) and embeds the `WorkspaceSwitcher` (L188).
- [SiteOpsContext.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/site-operations/SiteOpsContext.tsx) holds *every* authorised site and the active one; `canIn(permission)` delegates to `can(user, permission, scope)` (L65–68) with `scope = { campaign_id, site_id, team_id, org_unit_id: null }`.
- The client-side scope rule is [workspaces.ts](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/lib/workspaces.ts) `scopeCovers()` (L103–118): a `site` scope covers only that exact site; a `team` scope covers only that exact team; a `campaign` scope covers the campaign. `can()` (L132–151) never widens. This mirrors the backend and is correct.

### 2.3 Site switching

Every Site Operations board is mounted with `key={active.site_id}` and reads the site from context, e.g. [page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/page.tsx#L83-L90) and each module page. Switching site therefore **remounts** the board and re-issues site-scoped queries; stale data from the previous site cannot persist. This is a correct implementation (though untested — see §6).

### 2.4 Authorization at the policy layer (verified)

| Policy | Scope correctness | Evidence |
|---|---|---|
| `TeamPolicy` | Correct — `view`/`update`/`manageMember` resolve the team's real site; `create` takes the target site | [TeamPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/TeamPolicy.php#L56-L110) |
| `ActivityPolicy` | Correct — `create` takes the site; `view`/`update`/`delete` resolve the activity's site | [ActivityPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/ActivityPolicy.php#L71-L112) |
| `MissionSitePolicy` | Correct — `view` → `canViewSite`; `update` → site **or** campaign | [MissionSitePolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/MissionSitePolicy.php#L32-L46) |
| `MissionOutcomePolicy` | **Partial defect** — `view`/`viewAny` site-correct, but `assignFollowUp`/`completeFollowUp` are campaign-only | [MissionOutcomePolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/MissionOutcomePolicy.php#L74-L90) |
| `CampaignAssignmentPolicy` | **Defect (latent)** — only `viewAny` has the site branch; `view`/`create`/`update`/`delete` are campaign-only | [CampaignAssignmentPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/CampaignAssignmentPolicy.php#L38-L65) |
| `AttendanceRegisterPolicy`, `FieldReportPolicy`, `PersonPolicy`, `OutreachContactPolicy` | Correct (site-aware); contact policy delegates to `PersonPolicy` | (policy review; see §5) |

### 2.5 Backend surface available to the Site Operations modules

Routes exist (and are authorised) for everything the audit asked about, including the parts that have **no UI**: [api.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/routes/api.php). Confirmed present and authorised:

- Teams: index / store / show / update / members.
- Activities: index / store / show / update / participants(roster) / objectives.
- Attendance registers: index / entries / participants / summary / reconciliation / verify / submit.
- Reports: index / show / submit / review / forward / consolidate / recover / preparation / events.
- Outcomes: index / create / follow-up / complete-follow-up.
- Outreach contacts: index / store / show / update.
- Sites: readiness GET; logistics GET + PUT (`SiteLogisticsController@show` authorizes `view`, `@upsert` authorizes `update` on the site).

`MissionSitePolicy` makes these scope-correct, so in almost every case the backend is ready and the gap is on the frontend.

---

## 3. Action-level capability matrix

Classification legend: **Working** · **Partially working** · **UI gap** · **API gap** · **Authorisation gap** · **Scope mismatch** · **Data gap** · **Navigation gap** · **Not implemented** · **Unverified**.

| Module | Expected action | UI status | API status | Backend auth | Workflow result | Root cause | Evidence |
|---|---|---|---|---|---|---|---|
| Overview | Open assigned site workspace | Available | Working | Allowed | Successful | — | [page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/page.tsx) |
| Overview | See correct site identity/context | Available | Working | Allowed | Successful | — | `active.site_name`, L251 |
| Overview | Meaningful site statistics | Partial | Working | Allowed | Partially successful | "personnel" is assignments, not people (see §7-A) | L253–254 |
| Overview | Today's / upcoming activities | Available (today only) | Working | Allowed | Successful (today); **no upcoming** | Only `scheduled_at == today` filtered | L192–194 |
| Overview | Identify attendance needing attention | Available | Working | Allowed | Successful | — | L195–197 |
| Overview | Identify reports awaiting action | Available | Working | Allowed | Successful | — | L120 `pending_review:true` |
| Overview | Understand site readiness | Placeholder | (endpoint exists) | Allowed | Blocked | UI placeholder | [logistics/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/logistics/page.tsx) |
| Overview | Teams and their leadership | Available | Working | Allowed | Successful | Leader resolved via per-team `get()` | L130–140 |
| People | View personnel at site | Available | Working | Allowed | Successful | — | [people/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/people/page.tsx) |
| People | Identify operational roles | Missing | Working | Allowed | Blocked | Row shows only gender/phone | L137–141 |
| People | See who belongs to which team | Missing | Working | Allowed | Blocked | No team membership shown | — |
| People | Register outreach contact / participant | Missing here | Working | Allowed | **UI gap** | Registration lives in Outreach (deliberate) | header comment L11–18 |
| People | Update authorised information | Missing | Working | Allowed | **UI gap** | No edit UI | — |
| People | Access personnel details | Available (leaves workspace) | Working | Allowed | Partially successful | Navigates to `/people/{id}` (missionary workspace) | L128–143 |
| Teams | View teams at site | Available | Working | Allowed | Successful | — | [teams/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/teams/page.tsx) |
| Teams | Create a team in site | Available | Working | Allowed | Successful | — | L78–104; `site_coordinator_can_create_team_at_own_site` |
| Teams | Open an individual team | **Missing** | Working (`get`) | Allowed | **Blocked** | No team detail route/UI exists anywhere | card is a plain `div`, L208–219 |
| Teams | Edit team details | Missing | Working (`update`) | Allowed | **UI gap** | No edit UI | — |
| Teams | Appoint a team leader | Missing | Working (`addMember` role=leader) | Allowed | **UI gap** | `teamsApi.addMember` never called | api.ts L474; no usage |
| Teams | Assign missionaries to team | Missing | Working (`addMember`) | Allowed | **UI gap** | No UI | — |
| Teams | Remove members | Missing | Working (`removeMember`) | Allowed | **UI gap** | No UI | — |
| Teams | View membership / leadership / status | Partial | Working | Allowed | Partially successful | Only `members_count` on card; leader only on Overview | L214–216 |
| Activities | View planned + site-initiated | Available | Working | Allowed | Successful | `is_planned` badge distinguishes origin | L117–119 |
| Activities | Create site-initiated activity | Missing here | Working (`create`) | Allowed | **UI gap** | Create UI exists only in `(missionary)/fieldwork` | fieldwork L454 |
| Activities | Edit / schedule / assign to team | Missing | Working | Allowed | **UI gap** | No UI | — |
| Activities | View activity details | Missing | Working (`get`) | Allowed | **Navigation gap** | No detail route/Link | — |
| Activities | Monitor status | Available | Working | Allowed | Successful | Status pill + filter | L10–16, L129–131 |
| Attendance | View site registers | Available | Working | Allowed | Successful | Paged | [attendance/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/attendance/page.tsx) |
| Attendance | Open a register | **Missing** | Working (`show`) | Allowed | **Navigation gap** | Cards are non-clickable; no register detail route | L152–176 |
| Attendance | Inspect attendance entries | Missing | Working (`entries`) | Allowed | **UI gap** | `attendanceApi.entries` never called in frontend | — |
| Attendance | Understand which activity/team/site-day | Partial | Working | Allowed | Partially successful | Shows `register_type` + activity title only | L159–162 |
| Attendance | Review/verify within authority | Available (conditional) | Working (`verify`) | Allowed | Successful when `status==='submitted'` & `attendance.verify` | — | L165–174 |
| Attendance | Identify missing/incomplete | Missing | Working (`reconciliation`) | Allowed | **UI gap** | No reconciliation view | — |
| Outreach | View site contacts | Available | Working | Allowed | Successful | Paged + server-side search | [outreach/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/outreach/page.tsx) |
| Outreach | Register a contact (site-scoped) | Available | Working | Allowed | Successful | campaign + site fixed by context | L246–270 |
| Outreach | Update contact information | Missing | Working (`update`) | Allowed | **UI gap** | No edit UI | — |
| Outreach | Track engagement stages | Partial | Working | Allowed | Read-only | Stage shown, cannot be changed | L164 |
| Outreach | View engagement history | Missing | Working | Allowed | **UI gap** | Not shown here | — |
| Outreach | Record follow-up / encounter | Missing | Working | Allowed | **UI gap** | Encounters only in person detail page | — |
| Outreach | Identify contacts needing follow-up | Missing | Working | Allowed | **UI gap** | No follow-up cue | — |
| Reports | View site reports | Available | Working | Allowed | Successful | — | [reports/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/reports/page.tsx) |
| Reports | Open an individual report | Missing | Working (`show`) | Allowed | **Navigation gap** | No report detail route | cards non-clickable |
| Reports | Review submitted reports | Available | Working (`review`) | Allowed | Successful | Inline approve/return | L159–180 |
| Reports | Understand period + team | Available | Working | Allowed | Successful | L126–129 |
| Reports | Access details / supporting info | Missing | Working (`preparation`,`events`) | Allowed | **UI gap** | Never called | — |
| Reports | Perform forwarding | Missing | Working (`forward`) | Allowed | **UI gap** | `reportsApi.forward` unused | — |
| Reports | Identify rejected/returned/incomplete | Partial | Working | Allowed | Partially successful | `status`/`review_state` text only | L116–124 |
| Outcomes | View site outcomes | Available | Working | Allowed | Successful | — | [outcomes/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/outcomes/page.tsx) |
| Outcomes | Open an individual outcome | Missing | Working | Allowed | **Navigation gap** | No detail route | — |
| Outcomes | Record outcomes within authority | Missing | Working (`create`) | Allowed | **UI gap** | Create UI only elsewhere | — |
| Outcomes | **Complete/assign follow-up** | Available | Working | **Denied (incorrectly scoped)** | **Blocked — always 403** | `MissionOutcomePolicy` campaign-only | L83–90 vs L148–167 |
| Logistics | View site readiness | Placeholder | Working (`readiness`,`show`) | Allowed | Blocked | UI placeholder only | logistics/page.tsx |
| Logistics | Record/update readiness info | Missing | Working (`upsert`) | Allowed | **API gap (UI)** | No form | — |
| Logistics | Monitor outstanding needs | Missing | — | — | **Not implemented** | No model/UI | — |

---

## 4. Blocked workflows (detailed)

### A. Outcome follow-up is offered but always fails
- **What the user tries to do:** on `/site-operations/outcomes`, close an outstanding follow-up ("Done" / "Lost") for an outcome at their own site.
- **What happens:** the button is visible (because `canIn('outcomes.manage_followup')` is true at their site), the click calls `outcomesApi.completeFollowUp(campaignId, id, result)`, and the backend rejects it with 403.
- **What should happen:** a Site Coordinator holding `outcomes.manage_followup` at their site should be able to complete/assign that site's follow-ups.
- **Why it fails:** [MissionOutcomePolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/MissionOutcomePolicy.php#L74-L90) `assignFollowUp()` and `completeFollowUp()` call **`hasPermissionForCampaign(...)` only**. A site-scoped assignment never satisfies `hasPermissionForCampaign`, so a site coordinator is denied. This directly contradicts [WorkspaceResolver.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Services/WorkspaceResolver.php#L102) (which lists `outcomes.manage_followup` as a site-operations capability) and the seeded role ([DatabaseSeeder.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/seeders/DatabaseSeeder.php#L832) gives `site_coordinator` that code at site scope).
- **Where the problem exists:** backend policy (authorisation), not the UI. The UI is doing the right thing by trusting the resolved permission.
- **What would need to change:** the two methods should resolve the outcome's real site and accept `hasPermissionForSite(...)`, mirroring `view()` (L43–53). No permission, role or scope change is required.

### B. Team workflow is a dead end after creation
- **What the user tries to do:** create a team, then open it, edit it, appoint a leader, add/remove members.
- **What happens:** only creation works. Team cards are plain `div`s with no link ([teams/page.tsx L208–219](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/teams/page.tsx#L208-L219)); there is **no team detail route anywhere in the frontend** (only `site-operations/teams`, `admin/teams`, and the missionary's own `(missionary)/team`), and `teamsApi.addMember` / `removeMember` / `update` are never called by any page.
- **What should happen:** the coordinator should be able to open the team, see its roster and leader, appoint a leader, and manage members.
- **Why it fails:** pure **frontend gap**. The backend is ready: [TeamPolicy](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/TeamPolicy.php#L56-L92) authorises `view`, `update` and `manageMember` at the team's site, and the routes/API functions exist.
- **Where the problem exists:** frontend routing + UI (`teamsApi.get/addMember/removeMember` defined at [api.ts L470–477](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/lib/api.ts#L470-L477), unused).
- **What would need to change:** a team detail route + roster/leader management UI in the Site Operations workspace.

> This is exactly the scenario the brief anticipated: *"A Site Coordinator may be able to create a team but be unable to open it afterwards… they may open the team but be unable to appoint a leader."* The first half is literally true today.

### C. Attendance registers cannot be opened
- **What the user tries to do:** inspect the entries behind a register shown on `/site-operations/attendance`.
- **What happens:** nothing — the register card is not clickable and there is no register detail route.
- **What should happen:** opening the register should list its entries (and, ideally, per-register reconciliation/summary).
- **Why it fails:** **frontend gap**, *not* an API/policy problem. `attendanceApi` already exposes `show`, `entries`, `participants`, `summary`, `reconciliation`, and the `(missionary)/fieldwork` page already consumes `participants`/`reconciliation`/`summary` — so the data path is proven; the Site Operations UI simply omits it.
- **Note on the brief's hypothesis:** the brief suggested the cause might be a wrong identifier, a missing endpoint, a wrong-scope policy, or a query constraint. Verified cause is **none of those** — it is a missing UI affordance.

### D. Reports, outcomes and activities cannot be opened
- Same pattern: lists render non-clickable cards and `reportsApi.show`, `outcomesApi` detail and `activitiesApi.get` are never called. Backend authorised. Root cause = **frontend navigation gap**, not API or policy.

### E. Site readiness cannot be viewed or recorded
- `/site-operations/logistics` is a static placeholder stating readiness "is not measured yet", yet [SiteLogisticsController@show/upsert](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/SiteLogisticsController.php#L25-L43) and `MissionSiteController@readiness` exist and are authorised via `MissionSitePolicy`. Root cause = **frontend UI gap**, distinct from the genuinely-not-implemented "monitor outstanding needs" which has no model/UI at all.

---

## 5. Scope and permission findings

**A. Correct scope behaviour (verified good).** Site-scoped users are consistently narrowed to their own site and denied siblings: `WorkspaceResolver::siteOperations` only qualifies scope_type `site` (L211); controllers pass the resolved site into policies; `scopeCovers` on the client never widens. Team-scoped authority does **not** leak into site authority, and campaign-scoped authority remains distinct from site authority in both `scopeCovers` (client) and `resolveEffectiveAssignments`/`hasPermissionForSite` (server).

**B. Defect — outcome follow-up (confirmed).** `MissionOutcomePolicy::assignFollowUp` / `completeFollowUp` are campaign-only. See §4-A. **Impact:** every site-scoped coordinator receives 403 on a control the UI shows.

**C. Defect — campaign assignment mutations (latent).** [`CampaignAssignmentPolicy`](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/CampaignAssignmentPolicy.php#L47-L65): only `viewAny` carries the site branch; `view`, `create`, `update`, `delete` are campaign-only. The current Site Operations UI does not exercise these on a per-record basis (the Overview uses the *list* endpoint, i.e. `viewAny`), so this is **latent** here — but any future "manage deployments at my site" action would 403 for a site coordinator who legitimately holds `assignments.manage` at that site. The file's own comment documents that the same class of defect was fixed for `viewAny` only.

**D. No excessive/global grants observed.** No path widens a site role to campaign/global scope. The two defects are *under*-authorisation (false denies), not over-authorisation.

**E. Client/server agreement.** The client's `can()` and the server's scope resolution agree on the model (site ≠ campaign ≠ team). The single divergence is server-side: the outcome follow-up methods do not honour the site scope the rest of the system, and the workspace resolver, clearly intend.

**F. Answering the brief's boundary checklist (PART 4):**
1. Create/manage teams at own site — **create yes; manage yes in policy, no in UI.**
2. Appoint team leaders — **policy yes (member role); no UI; no dedicated endpoint; untested.**
3. Assign eligible missionaries to teams — **API yes; no UI; eligibility not enforced by any selector (none exists).**
4. Create site-initiated activities — **policy yes (tested); no UI.**
5. Manage campaign-planned activities at site — **view yes; edit not exposed in UI.**
6. View/manage site attendance — **view + verify yes; entry inspection absent.**
7. Manage outreach contacts at site — **create yes; update absent.**
8. Review site reports/outcomes — **reports yes; outcome follow-up denied (defect).**
9. Manage site readiness — **backend yes; UI placeholder.**
10. Operations against another site denied — **yes.**
11. Team-scoped role restricted to its team — **yes.**
12. Campaign-scoped distinct from site authority — **yes.**
13. Changing selected site changes data/actions — **yes (context + remount); untested.**

---

## 6. Testing and evidence

**Evidence actually used:** static inspection only — controllers, policies, services, routes, seeders, providers, migrations, frontend pages/components/api-client.

**Automated tests: NOT executed (blocked — environment, not application).**
The suite could not run because the test database `uu_mission_test` (PostgreSQL, port 5433) was left half-migrated by the earlier aborted run. Running it produced schema collisions:

```
SQLSTATE[23505]: duplicate key ... (users_id_seq, 2200) already exists
SQLSTATE[42P07]: relation "jobs" already exists
Tests: 13 failed (0 assertions)
```

Repairing the test database requires a destructive DB action (wipe / schema drop / new database with credentials), which the user elected not to authorise; accordingly **no runtime result in this report is a test result**. Every "Allowed"/"Correct" claim in §2/§5 is a *policy-code* verdict, and every "tested" reference is to an *existing test in the repository*, not to a test run this session.

**Existing tests relevant to this audit (present, not run):** `ScopedAuthorizationTest.php` (incl. `site_coordinator_can_create_team_at_own_site`, `..._can_list_assignments_at_own_site`, `..._can_view_a_team_at_own_site`), `FieldworkAttendanceAndRosterTest.php` (`site_coordinator_can_create_activity_at_own_site`), `CampaignWorkspaceTest.php` (`test_site_operations_workspace_remains_intact`), `WorkspaceAccessControlTest.php`, `OfficeAndRoleAssignmentTest.php`.

**Coverage gaps (no test found):**
- Site readiness / logistics workflow (`SiteLogisticsController` show/upsert; `MissionSiteController@readiness`).
- Team leader appointment; team member add/remove.
- Attendance register entry inspection from the Site Operations context.
- Explicit site switching changing the operational context.
- **Outcome follow-up at site scope** — a test here would have caught defect §5-B.

**Manual browser verification:** not performed in this session. Critical journeys (workspace entry, site switching, the outcome follow-up 403, and the team dead-end) still require a running-app check before the demo freeze.

**Frontend type check / build:** not run (the dev-server/build interaction is known to corrupt Turbopack state; the standing instruction is no production build while dev is live).

---

## 7. Data integrity findings

**A. Two different numbers both mean "people".**
- The Overview shows personnel as the count of **CampaignAssignment** rows: `{data.assignments.length} personnel` ([page.tsx L253](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/page.tsx#L253)), sourced from `assignmentsApi.list` (deployment records).
- The People page shows the count of **Person** rows: `peopleApi.list({ campaign_id, mission_site_id })` ([people/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/site-operations/people/page.tsx#L59-L68)).
- These are different datasets and will legitimately disagree. The brief explicitly asked to investigate this discrepancy: **confirmed**, and it is by design of the two endpoints, not a bug in either — but the labels ("personnel" vs "people") do not tell the operator they are different things.

**B. The People list mixes missionary personnel with outreach contacts.**
- Person registration is identity-only ([PersonController@store](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/PersonController.php#L112-L123)), and **registering an outreach contact creates a `Person`** ([OutreachContactController@store, L157](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OutreachContactController.php#L157) `Person::create`).
- Therefore the Site Operations People list (filtered only by campaign/site) contains **both** outreach contacts and missionary personnel, with no discriminator shown. The brief warns: *"Do not silently treat outreach contacts as missionary personnel."* Today the list does exactly that. `PersonController@index` even seeds the People module with `outreach-contacts.view` scopes ([L71–74](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/PersonController.php#L71-L74)).

**C. No invalid leader selector is possible — because there is no selector.** The brief asked whether a leader selector "may display people who are not eligible". There is no selector at all (team detail is unimplemented), so this cannot occur; eligibility enforcement is therefore also absent.

**D. DB constraints are protective, not problematic.** Composite FKs `(mission_site_id, campaign_id)` on teams/assignments/activities/registers/reports/outcomes, plus `chk_ura_scope_exclusive` on `user_role_assignments`, ensure a site always belongs to its campaign. No integrity violation was found in the code paths audited.

**E. Person deletion is RESTRICTed** (`ON DELETE RESTRICT` from `activity_participants`/`attendance_entries`), preserving historical records — consistent with the project's auditability intent.

---

## 8. Proposed reusable UI component library (assessment only — nothing built)

### 8.1 Repeated patterns already present
Every Site Operations module rebuilds the same scaffold: `if (!active || active.site_id == null || active.campaign_id == null) return <NoSiteContext/>` → `<Board key={active.site_id}>` → header (title + count) → `ErrorBanner` → loading `Skeleton[]` → `EmptyState` → list → optional `Pagination`.

Specifically duplicated:
- **Scope guard + remount scaffold** — identical across all 9 boards.
- **Status filter pill group** — verbatim in `attendance` and `activities` (`STATUS_FILTERS` constant + button styling).
- **Async action with `busyId`** — repeated in `attendance.verify`, `reports.act`, `outcomes.resolve`.
- **Debounced server-side search input** — duplicated in `people` and `outreach` (300 ms debounce, reset to page 1).
- **List row card** (icon square + title + meta + trailing status) — repeated in teams, activities, attendance, outreach, reports, outcomes.
- **Error banner** — inconsistent: `ErrorBanner` in teams/reports/outcomes/outreach vs ad-hoc inline red `div` in activities/people/attendance/overview.

### 8.2 Existing components to reuse (do not rebuild)
`EmptyState`, `ErrorBanner`, `Skeleton`, `StatusBadge`, `Modal`, `Pagination`, `Spinner`, `StatCard`, `cn` ([ui.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/ui.tsx)); `useSiteOperations`/`SiteOpsContext`; `NoSiteContext`; `WorkspaceSwitcher`; `can`/`permissionsFor`.

### 8.3 Components that are duplicated and could be consolidated
`ScopeGuard`+remount wrapper; status filter pills; debounced search; async action button; list-row card; error banner usage.

### 8.4 Proposed shared components, responsibilities, and API contracts
Scope-aware = receives resolved context/permissions; **must never invent or grant authority**.

| Component | Scope-aware? | Responsibility | Suggested contract |
|---|---|---|---|
| `ScopeBoard` | Yes | Guard on active context, remount on `site_id`, render header/loading/error/empty/list | `{ context: WorkspaceContext; title: string; count?: number; isLoading: boolean; error?: string; empty: ReactNode; children: ReactNode }` |
| `FilterPills` | No | Single/multi select pills | `{ options: {value:string; label:string}[]; value: string; onChange(v): void }` |
| `SearchField` | No | Debounced server-side search | `{ value; onChange(q:string, debounced:boolean): void; placeholder? }` |
| `AsyncButton` | Yes | Busy state + optional permission gate | `{ onAction(): Promise<void>; can?: boolean; idleLabel; busyLabel; icon; tone }` |
| `RecordRow` | No | Icon + title + meta + trailing slot | `{ icon; title; meta?: ReactNode; trailing?: ReactNode; href?: string; onClick? }` |
| `RecordDetail` | Yes | View/edit/review/approve modes driven by passed permissions + workflow state | `{ mode:'view'\|'edit'\|'review'\|'approve'; can:{edit?:boolean;review?:boolean}; fields; actions }` |
| `AttendanceRegisterView` | Yes | Register header + entry list (+summary/reconciliation slots) | `{ register; entries; canVerify?: boolean; canSubmit?: boolean }` |
| `ReportReviewPanel` | Yes | Figures + review/forward/consolidate actions by permission | `{ report; can:{review;submit;forward;consolidate} }` |
| `TeamMemberManager` | Yes | Roster + leader appointment + add/remove | `{ team; members; canManage: boolean; candidates; onAdd; onRemove; onSetLeader }` |
| `ReadinessIndicator` | Yes | Honest readiness state (value only when real records exist) | `{ state:'not_measured'\|'partial'\|'ready'; inputs?: LogisticsRecord[] }` |

### 8.5 Which stay module-specific
Campaign-consolidation UI; activity scheduling calendar; report preparation/reconciliation detail; outcome follow-up editor — these are domain workflows, not generic primitives.

### 8.6 Recommended minimal implementation order
1. `ScopeBoard` + `RecordRow` + `FilterPills` + `SearchField` + `AsyncButton` (remove the bulk of duplication).
2. `RecordDetail` in **view** mode → unblocks opening teams/attendance/reports/outcomes/activities.
3. `TeamMemberManager`, `AttendanceRegisterView`, `ReportReviewPanel` (the operational views the coordinator actually needs).
4. `ReadinessIndicator` last (depends on logistics data decisions).

**Do not build any of this during the audit.**

---

## 9. Prioritised remediation roadmap

Complexity: S (small) / M (medium) / L (large). Dependencies noted.

### P0 — Demonstration blockers
1. **Fix outcome follow-up authorisation** (§4-A, §5-B). *Complexity S.* Change `MissionOutcomePolicy::assignFollowUp/completeFollowUp` to accept site scope (mirror `view()`). Dependency: none. Risk: must keep campaign-wide working; add a site-scope test.
2. **Make a team openable in Site Operations** (§4-B). *Complexity M.* Needs a team detail route + minimal roster/leader view. Dependency: `RecordDetail`/`TeamMemberManager` (or a minimal bespoke page).

### P1 — Core operational gaps
3. **Attendance register detail + entry inspection** in Site Ops. *M.* Uses existing `attendanceApi.entries/summary/reconciliation`.
4. **Report detail (and forward/consolidate)** in Site Ops. *M.* Uses `reportsApi.show/forward/consolidate/preparation`.
5. **Activity create/edit** in Site Ops (site-initiated). *M.* Uses `activitiesApi.create/update`.
6. **Outreach contact update + stage change + engagement history.** *M/L.* Uses `outreachContactsApi.update`, encounter APIs.
7. **Logistics/readiness UI** on the existing backend endpoints. *M.* Depends on a decision about which logistics fields are authoritative.

### P2 — UX and consistency
8. **Consolidate the shared components** in §8.6. *L.* Paydown that makes 3–7 cheaper.
9. **People list clarity:** distinguish outreach contacts from missionary personnel; show operational role/team; navigate within the workspace instead of jumping to `/people/{id}`. *M.* May need an API discriminator (data decision).
10. **Overview clarity:** label "personnel" vs "people" honestly, or align the metric. *S.*
11. **Consistent error handling** (use `ErrorBanner` everywhere). *S.*

### P3 — Future enhancements
12. Readiness scoring once real logistics records exist. *L.* Depends on 7.
13. Upcoming-activities view on the Overview (currently today-only). *S.*
14. Activity detail panels / scheduling interface. *M.*

---

## 10. Final readiness assessment

| Area | Assessment | Basis |
|---|---|---|
| **Navigation & workspace access** | **Ready** | Dedicated shell, adaptive nav, multi-site selector, switcher; correct scope model. |
| **People management** | **Partial** | Read-only; no role/team info; registration delegated to Outreach; rows leave the workspace; mixes contacts with personnel (§7-B). |
| **Team management** | **Blocked** | Create works; open/edit/leader/members all missing UI (§4-B). |
| **Activities** | **Partial** | Full, correctly-scoped read; no create/edit/schedule/detail in Site Ops. |
| **Attendance** | **Partial** | List + verify work; register cannot be opened; entries not inspectable (§4-C). |
| **Outreach** | **Partial** | List + create + server-side search work; update/stage/history/follow-up missing. |
| **Reports** | **Partial** | List + inline review work; no detail/forward/consolidate. |
| **Outcomes** | **Blocked (follow-up)** | List works; follow-up control always 403s (defect); no create/detail. |
| **Logistics** | **Not implemented (UI)** | Placeholder despite implemented, authorised backend. |
| **Scope & authorisation** | **Mostly sound, 2 defects** | Under-authorisation only: outcome follow-up (confirmed) and campaign-assignment mutations (latent). |
| **Overall demonstration readiness** | **Not ready for full responsibility set** | A read-oriented subset is demonstrable and honest; the P0 items must be resolved before declaring operational readiness. |

**Strongest positive finding:** the platform consistently prefers an honest empty/placeholder state over fabricated data (Overview readiness, logistics page, report preparation with no "completion %"). This is the right foundation — the gaps are overwhelmingly *surfacing work that the backend already supports*, plus two small authorisation corrections.

**Recommendation:** treat **§9 P0 item 1** (a one-method authorisation correction) and **§9 P0 item 2** (make teams openable) as the controlled pre-freeze corrections, then freeze presentation scope. Everything else in P1 is valuable but can be scheduled after the freeze without changing the architecture.

---

*End of audit. Per the brief's stop conditions, no fixes, refactors, migrations, permissions or components were created; findings are reported for a controlled decision.*
