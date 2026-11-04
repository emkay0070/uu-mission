# Audit People and Fieldwork Modules

> **Workspace:** e:\Year 2026\Codding\UU Mission

---

**User**

# UU Mission — People and Fieldwork Relationship Audit

We need to investigate the existing People and Fieldwork modules and establish whether their responsibilities and data relationships match the intended mission workflow.

**IMPORTANT: Audit only. Do not modify files, database schemas, migrations, APIs, components, or tests. Do not implement fixes yet.**

## 1. Intended product behaviour

UU Mission must preserve the history of people reached through mission activities, including individuals who participate only once, never return, share very little information, or do not disclose whether they are church members or visitors.

We must distinguish three concepts:

- **Person:** An individual known to the mission, to the extent that their identity and details are available.
- **Encounter:** An actual contact or interaction between the mission and an individual, such as outreach, a conversation, a home visit, prayer, or follow-up.
- **Activity participation / attendance:** A record that an individual participated in a particular site activity, including attendance status.

These records must be related where appropriate, but must not be treated as interchangeable.

## 2. Fieldwork activities

Investigate:

- Who can create activities and at what scope?
- Are activities created under a specific mission site?
- Can the site coordinator or another appropriately assigned person create activities?
- Are permissions checked using AuthorizationService and active scoped assignments?
- Can activities be updated, cancelled, completed and reported?
- Does each activity have its own attendance register?
- Can the activity register distinguish people who were pre-registered from walk-ins?

An activity should belong to a site. The register is primarily for recording attendance and participation in that activity.

## 3. Attendance register and walk-ins

Trace the entire workflow from opening an activity register to saving attendance.

Establish exactly:

- What happens when an existing Person is selected?
- What happens when someone is added as a walk-in?
- Does adding a walk-in create a Person record, an activity participant, an attendance record, or several of these?
- Which fields are mandatory?
- Can someone participate without providing their name or contact information?
- Is a walk-in incorrectly treated as a visitor, outreach contact, or new church prospect?
- Can the same Person participate in multiple activities without duplicate Person records?
- Are attendance records retained if the individual never returns?

Do not assume that walk-in means visitor. It means the person arrived without prior registration.

## 4. People and Encounters

Trace the People module, including:

- Person creation and identity fields.
- Outreach contacts and encounters.
- Encounter types, dates, locations and outcomes.
- Follow-ups and discipleship records.
- Links between People, Fieldwork activities, attendance and site records.
- Whether an encounter can be recorded without forcing a person to disclose personal information.
- Whether someone who attends an activity can be preserved in the mission's history without automatically being classified as an outreach prospect.

Investigate whether activity attendance automatically creates an Encounter or outreach contact. If it does, identify exactly where and why.

Our intended behaviour is:

- Attendance alone creates activity participation/attendance, not automatically an outreach encounter.
- An actual interaction should be recorded as an Encounter when applicable.
- A Person should be created or reused when sufficient information is available.
- Where an individual cannot be identified, preserve an anonymous attendance or encounter record without inventing an identity.
- If the individual is identified later, the record should be linkable to their Person history.

## 5. Data relationships and history

Trace the relevant database tables, foreign keys, models, services, controllers, policies, API resources and frontend screens.

Show the relationships between:

Person → Encounter → Activity → Activity Participant → Attendance Register → Site.

Do not assume this exact relationship already exists; report the actual schema and identify any differences.

Check:

- Duplicate-person prevention and matching.
- Whether attendance can exist without a Person ID.
- Whether anonymous participation is supported.
- Soft deletion and historical preservation.
- Whether deleting or archiving an activity or person can destroy mission history.
- Whether the same Person can be encountered and participate across multiple activities and sites.

## 6. Authorization and site boundaries

Verify that:

- A site coordinator can manage activities only within their authorised site.
- An assigned activity leader can perform only the actions their permissions allow.
- Team-scoped users receive only the necessary parent-site context.
- Users cannot view or modify sibling-site records without authorization.
- Backend authorization remains authoritative; frontend visibility is not treated as security.

## 7. Required report

Return a structured report with:

1. **Current workflow:** What happens today, step by step, when an activity is created and attendance is registered.
2. **Current data model:** Actual tables and relationships, including how walk-ins are stored.
3. **People versus Fieldwork:** What each module currently owns and where responsibilities overlap.
4. **Gaps:** Differences between current behaviour and the intended behaviour above.
5. **Risks:** Particularly lost historical participation, duplicate people, incorrect visitor classification, forced personal information, and accidental creation of outreach contacts.
6. **Recommended separation:** A minimal proposed responsibility and data-flow model.
7. **Files and endpoints:** Exact file paths, controllers, models, services, APIs and frontend screens involved.
8. **Tests:** Existing tests covering these workflows and missing critical cases.

For every finding, distinguish:
- Confirmed from code/schema.
- Inferred but not verified.
- Not currently supported.

**Do not implement anything. We will review the findings and agree on the correct workflow before authorising changes.**

**Assistant**

I'll start by reviewing memory context and exploring the codebase structure.

The LS output appears truncated. Let me explore the key directories directly.

Now let me read the core schema migrations, routes, and the authorization service.

Now let me read the fieldwork/attendance schema migrations in bulk.

Key finding: `people` had campaign/site scoping added then removed. Let me read the remaining people migrations and the encounters/outreach schema.

Now the key controllers. Let me read them.

Now the models, policies, and service provider. Let me read them.

No `ActivityPolicy` exists. Let me verify authorization wiring and read the frontend fieldwork/people screens.

Let me examine the frontend fieldwork/people screens and the remaining encounter schema.

Let me read the fieldwork screen and the attendance API client.

Let me check where records are created programmatically and review the tests.

Let me read the person detail screen and the encounter form modal.

Let me confirm the engagement-stage column history and the seeded permission codes.

I have completed the reconnaissance. Let me verify two security-critical claims before finalizing the report.

Verification complete. Findings confirmed. Here is the audit report.

---

# UU Mission — People & Fieldwork Relationship Audit

**Scope:** Read-only investigation. No files, schemas, migrations, APIs, components, or tests were modified.
**Legend:** ✅ **Confirmed** (code/schema) · 🔶 **Inferred** (not fully verified) · ❌ **Not currently supported**

---

## 1. Current Workflow

### 1a. Creating an Activity (Fieldwork)

✅ Confirmed path:

1. `POST /api/v1/campaigns/{campaign}/activities` → [ActivityController::store()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/ActivityController.php#L63)
2. Requires `mission_site_id`, `activity_type_id`, `title`, `scheduled_at`.
3. Validates the site belongs to the campaign (composite ownership check).
4. Status lifecycle: `scheduled → in_progress → completed → cancelled → reported` (per migration [2026_10_23_202000_create_activities_table.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/migrations/2026_10_23_202000_create_activities_table.php)).
5. `destroy()` only permitted when `status === 'scheduled'` and no registers/outcomes exist — so completed activities cannot be destroyed through the API.

✅ **An activity belongs to a site** (composite FK `(mission_site_id, campaign_id) → mission_sites(id, campaign_id)`).

🔴 **Authorization is broken (Confirmed):** `store/view/update/delete` all call `$this->authorize('viewAny'|'create'|'view'|'update'|'delete', ...)` — **but no `ActivityPolicy` class exists** (grep = 0 matches) and none is registered in [AppServiceProvider.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Providers/AppServiceProvider.php). Any activity action therefore throws `AuthorizationException` (deny) rather than enforcing the RBAC model → **activities are effectively unusable via API today**, not merely under-protected.

### 1b. Creating an Attendance Register & Recording Attendance

✅ Confirmed path:

1. `POST /attendance-registers` → [AttendanceController::store()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AttendanceController.php) — requires `campaign_id`, `mission_site_id`, register type; verifies site∈campaign; requires permission `attendance.submit` **for that site**. `activity_id` is **optional** (standalone roll-call allowed).
2. `GET /attendance-registers/{id}/participants` → returns candidate attendees:
   - If linked to an activity → `ActivityParticipant` rows.
   - If standalone → only `Person`s that *already* have attendance entries in that campaign/site.
3. `PUT .../entries` → [updateEntries()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AttendanceController.php#L237) — **`entries.*.person_id` is `required|exists:people,id`**; always writes `attendee_type = 'missionary'`.
4. `submit()` → `open → submitted`; `verify()` → `submitted → verified` (verified cannot be reopened).

### 1c. Registering a Person from the "People" screen

✅ Confirmed: [people/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/(missionary)/people/page.tsx) is titled **"Outreach Contacts"**. The "Create Contact" flow:

1. `POST /outreach-contacts` → [OutreachContactController::store()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OutreachContactController.php) which in **one transaction** creates a **Person** (if none supplied) **+** an **OutreachContact** **+** (if `encounter_date` present) an **OutreachEncounter** **+** engagement-stage history.
2. `POST /outreach-encounters` when adding a follow-up encounter.

✅ **Consequence: every person registered from the People screen is automatically classified as an outreach prospect with an encounter.** There is no neutral "register a person" path in that screen.

---

## 2. Current Data Model

✅ Confirmed tables/relations relevant here:

| Table | Key columns | Notes |
|---|---|---|
| `people` | `first_name`, `last_name` (**now nullable** — `2026_11_01_083723_make_person_names_nullable.php`), `phone`, soft-deletes | No `campaign_id`/`mission_site_id` (removed by `2026_11_03_020200_remove_outreach_fields_from_people_table.php`). Person is scope-less by design; location derived from linkages. |
| `activities` | `mission_site_id`+`campaign_id` composite FK, `status`, `scheduled_at` | Belongs to a site. |
| `activity_participants` | `activity_id`, **`person_id NOT NULL`**, unique `(activity_id, person_id)`, cascade delete | ❌ Cannot represent anonymous/walk-in participants. |
| `attendance_registers` | `campaign_id`, `mission_site_id`, `activity_id` (**nullable**), `status` | Standalone roll-call supported. |
| `attendance_entries` | `attendance_register_id` (cascade), `attendee_type` default `'missionary'`, **`person_id` nullable**, `visitor_name`(200), `visitor_phone`(30), `visitor_gender`, `is_first_time`, `decision_made`, `no updated_at` | CHECK `chk_ae_identifier (person_id IS NOT NULL OR visitor_name IS NOT NULL)`. |
| `outreach_contacts` | one per `(person, campaign)`; `engagement_stage_id`; legacy NULL-campaign rows flagged `is_legacy` | |
| `outreach_encounters` | **`person_id NOT NULL`**, `campaign_id` (restrict), `mission_site_id` (nullable, restrict) | Encounter cannot exist without a Person. |

### How walk-ins are stored

🔴 **Confirmed mismatch:** the **columns** exist (`attendance_entries.visitor_name/visitor_phone/visitor_gender`, CHECK constraint permitting person-less rows), **but no controller endpoint ever writes them.** `updateEntries()` hard-requires `person_id` and hard-sets `attendee_type='missionary'`. Therefore:

- ✅ **Walk-ins are effectively unsupported via the API.** The DB is capable; the application layer is not.
- 🔶 The migration comment conflates *walk-in* with *"community visitors"* — a semantic confusion baked into the schema naming.

### Confirmed relationship graph (actual)

```
MissionSite ──< Activity ──< AttendanceRegister ──< AttendanceEntry >── Person
                     │                                     │
                     └──< ActivityParticipant >── Person     └──(visitor_* cols: DEAD / unwritten)

Person ──1:1── OutreachContact ──< OutreachEncounter (FK wrongly named)
Person ──< BaptismRecord, OrgMembership, ...
```

❌ **There is no `Encounter` entity independent of `OutreachContact`.** The concept of "encounter" is bound to the outreach pipeline, not to a neutral interaction.

---

## 3. People versus Fieldwork — Ownership & Overlap

✅ Confirmed:

| Concern | Owning module today | Verdict |
|---|---|---|
| Person identity | People (`PersonController`) + Onboarding | Shared; no single owner |
| Outreach journey / engagement stage | OutreachContactController | Correctly separated (recent change) |
| Encounter / interaction | OutreachEncounterController | ❌ Bound to outreach, requires Person + OutreachContact |
| Fieldwork activity | ActivityController | Correct owner of activities |
| Attendance record | AttendanceController | Correct owner of attendance |
| Activity participation | ActivityParticipant (created by `PersonController::store` when `activity_id` passed) | ⚠ Leaks Fieldwork concern into the People controller |

🔴 **Overlap of concern:** [PersonController::store()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/PersonController.php) accepts `campaign_id`/`mission_site_id`/`activity_id` (excluded from persistence via `Arr::except`) and, when `activity_id` is present, creates an `ActivityParticipant` itself. Fieldwork participation is thus written by the People controller rather than by a Fieldwork service.

🔴 **The People screen is a misnomer** — it is an outreach-contact pipeline UI, not a neutral people registry.

---

## 4. Gaps vs. Intended Behaviour

| Intended behaviour | Actual | Status |
|---|---|---|
| Attendance alone creates participation, **not** an outreach encounter | Attendance writes only `attendance_entries` — it does **not** auto-create encounters ✅ | ✅ Matches (good) |
| Encounter recorded only on real interaction | Encounters only via `OutreachEncounterController`, requires contact | ✅ Partially matches |
| Person created/reused when info available | `checkDuplicates` matches name OR exact phone | ✅ Partial |
| Anonymous attendance preserved | ❌ `updateEntries` requires `person_id`; `activity_participants.person_id` NOT NULL | ❌ **Not supported** |
| Anonymous encounter preserved | ❌ `outreach_encounters.person_id` NOT NULL | ❌ **Not supported** |
| Later link anonymous record to Person | No `person_id` on visitor rows → no re-link path | ❌ **Not supported** |
| Person ≠ visitor ≠ outreach prospect | Register forces `attendee_type='missionary'`; People screen forces OutreachContact | ❌ Violated |
| Walk-in = arrived without registration | No walk-in path at all; column naming says "visitor" | ❌ Confused/unbuilt |
| Same Person in many activities/sites without duplicates | Unique `(activity_id, person_id)` allows multi-activity; multi-site via campaign | ✅ Supported |
| Attendance retained if person never returns | `attendance_entries` retained (no cascade from Person); but if Person is hard-deleted, `person_id` nullOnDelete leaves a row failing... ⚠ actually CHECK requires person_id OR visitor_name → a row with only person_id would need visitor_name | 🔶 Partial / fragile |
| No forced personal disclosure | Person phone/name optional; but encounter requires Person + contact | 🔶 Partial |

🔴 **Critical gap in section 4 of your request (auto-encounter creation):** **Confirmed it does NOT happen in the attendance flow** — but it **DOES happen in the People/outreach flow**, where `OutreachContactController::store()` creates Person + OutreachContact + Encounter together. So "attendance → outreach contact" is safe, but "the only way to register a person → outreach contact" is not.

---

## 5. Risks

1. **Incorrect visitor/walk-in classification (Confirmed):** the only attendance write path stamps `attendee_type='missionary'` with a required `person_id`; a genuine walk-in cannot be recorded, so operators must either create a fake Person or misclassify.
2. **Accidental outreach-contact creation (Confirmed):** any person added via the People screen becomes an outreach prospect + encounter. No neutral registration path → contaminated pipeline.
3. **Lost historical participation (Confirmed risk):** `attendance_entries.person_id` is `nullOnDelete`; deleting a Person leaves NULL person_id, and the CHECK constraint then fails on any row lacking `visitor_name` — **history can be corrupted or blocked**. `activity_participants.person_id` is `cascadeOnDelete` → participation rows are **destroyed** on Person delete. Soft-deletes mitigate only if never hard-deleted.
4. **Forced personal information (Confirmed):** encounters require a Person and a pre-existing OutreachContact (`"Please register them as an outreach contact first"`), forcing identity disclosure and pipeline entry.
5. **Duplicate people (Partial):** dedupe is name-match OR exact phone only — no normalization, no alias/merge. Anonymous persons (nullable names) can silently proliferate.
6. **Activity authorization failure (Confirmed):** no `ActivityPolicy` → authorization throws; fieldwork may be entirely blocked or (if the exception is swallowed upstream) unenforced. Either way it is not the designed RBAC behaviour.
7. **Bogus relation (Confirmed, latent):** [OutreachEncounter::outreachContact()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Models/OutreachEncounter.php) is defined as `belongsTo(OutreachContact::class, 'person_id')` — wrong FK; it resolves via person_id, which is not the contact's key → mis-joins/empty results.

---

## 6. Recommended Separation (minimal, no redesign of RBAC)

Deliberately minimal — reuse existing tables where possible:

1. **Keep `activities`, `attendance_registers`, `attendance_entries` as-is.** They already model Fieldwork correctly.
2. **Make the visitor columns real (facade, not new table):** allow `updateEntries` to accept either `person_id` **or** `visitor_name`, and set `attendee_type` from an explicit `attendee_type` field (drop the hard-coded `'missionary'`). This immediately enables walk-ins + anonymous attendance using the already-present schema and CHECK constraint.
3. **Separate "Person registration" from "Outreach classification":** add/route a neutral person-create that does **not** create an OutreachContact. OutreachContact should be an *opt-in escalation* ("this person is now a prospect"), not the default outcome of registration.
4. **Decouple encounters from outreach:** allow `outreach_encounters.person_id` to be nullable **only if** the encounter carries a site/campaign context, OR introduce a neutral `encounters` concept so attendance/anonymous interactions don't require the outreach pipeline. (Design decision for you — flagged, not implemented.)
5. **Add an `ActivityPolicy`** registering the existing `people.*`/`attendance.*`/`outcomes.*` codes scoped to the activity's site — no new permission system.
6. **Later-link mechanism:** when a visitor row is later identified, allow setting `person_id` on the existing `attendance_entries` row (retain the visitor fields as provenance).
7. **Protect history:** change `activity_participants.person_id` from `cascadeOnDelete` to `nullOnDelete`, or rely strictly on soft-deletes, so deleting a Person never erases participation history.

---

## 7. Files & Endpoints

**Controllers**
- [ActivityController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/ActivityController.php) — activity lifecycle; **unregistered policy** (L34, 63, 98, 113, 143, 191, 227)
- [AttendanceController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AttendanceController.php) — registers, participants, entries (L237 required person_id), submit/verify
- [PersonController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/PersonController.php) — index scoping, store (context leakage), `checkDuplicates`, `applyVisiblePeopleScope`
- [OutreachContactController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OutreachContactController.php) — forces Person+Contact+Encounter
- [OutreachEncounterController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OutreachEncounterController.php) — requires Person + existing Contact
- [OnboardingController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OnboardingController.php) — person-mode registration

**Models / Policies**
- Models: [Person.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Models/Person.php), [ActivityParticipant.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Models/ActivityParticipant.php), [OutreachContact.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Models/OutreachContact.php), [OutreachEncounter.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Models/OutreachEncounter.php) (buggy relation)
- Policies: `PersonPolicy`, `OutreachContactPolicy`, `AttendanceRegisterPolicy`, `OutreachEncounterPolicy` — all delegate to AuthorizationService
- Registered in [AppServiceProvider.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Providers/AppServiceProvider.php) — **no Activity policy**

**Migrations of note**
- `2026_10_23_202000_create_activities_table.php`, `..._202100_create_attendance_registers_table.php`, `..._202200_create_attendance_entries_table.php`
- `2026_10_29_200100_create_activity_participants_table.php` (person_id NOT NULL)
- `2026_11_01_100100_create_outreach_encounters_table.php` (person_id NOT NULL)
- `2026_11_03_020000_create_outreach_contacts_table.php` + `2026_11_04_000000_update_outreach_contacts_constraints.php`
- `2026_11_03_020200_remove_outreach_fields_from_people_table.php`
- `2026_11_01_083723_make_person_names_nullable.php`

**Frontend**
- [fieldwork/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/(missionary)/fieldwork/page.tsx) — registers + checklist; **no walk-in UI**
- [people/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/(missionary)/people/page.tsx) — "Outreach Contacts" misnomer
- [people/[id]/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/(missionary)/people/[id]/page.tsx) — profile + encounters + participations
- [EncounterFormModal.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/EncounterFormModal.tsx)
- [SimplePersonRegistration.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/SimplePersonRegistration.tsx)
- [api.ts](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/lib/api.ts) — `AttendanceEntry` has visitor fields, but `updateEntries` payload sends only `person_id`

**Routes:** [api.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/routes/api.php) (`attendance-registers`, `people`, `outreach-contacts`, `outreach-encounters`, `activities` nested under campaigns)

---

## 8. Tests

✅ Confirmed: [WorkspaceTest.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/tests/Feature/WorkspaceTest.php) is the only feature test and covers **only workspace endpoints**. Seeded missionary role: `attendance.submit, outcomes.record, people.view, assignments.view`.

❌ **No tests exist for:** activity creation/authorization, attendance register lifecycle, walk-in/visitor entry, anonymous attendance, duplicate detection, encounter creation rules, cross-site/scope authorization for any of these, or history preservation on Person delete.

**Critical missing cases to add later (not now):**
- Walk-in recorded without `person_id` (anonymous attendance).
- Attendance entry survives Person soft-delete / hard-delete without corruption.
- Registering a person does **not** create an OutreachContact unless explicitly requested.
- Encounter cannot be created without a Person (assert current behaviour) and re-link path for unidentified attendees.
- Site coordinator can create/manage activities **only** in their site; sibling-site denial returns 403.
- Same Person attends multiple activities/sites with no duplicate Person rows.

---

## Summary of top findings

- 🔴 **No `ActivityPolicy`** exists though activities authorize against it → fieldwork authorization is broken (Confirmed).
- 🔴 **Walk-ins are unreachable via API** despite schema support; `updateEntries` forces `person_id` + `attendee_type='missionary'` (Confirmed).
- 🔴 **Registering a person auto-creates an outreach prospect + encounter** (Confirmed) — the core "People vs Outreach" confusion.
- 🔴 **Anonymous participation/encounters not supported** (`person_id` NOT NULL in both `activity_participants` and `outreach_encounters`) (Confirmed).
- 🟡 **History-at-risk**: `cascadeOnDelete` on participants; nullable-person rows conflict with the CHECK constraint (Confirmed).
- 🟡 **Latent relation bug** in `OutreachEncounter::outreachContact()` (Confirmed).
- ✅ **Good news:** attendance does **not** auto-generate encounters, and activities are already site-bound with a proper composite FK.

No files were changed. Awaiting your decision on the intended workflow before any implementation.

---

**User**

# UU Mission — Revised People, Fieldwork & Attendance Architecture

We have clarified the product model. Please revise your previous implementation plan accordingly.

Do not modify any files or database structures yet.

## 1. Preserve the existing People page as Outreach Contacts

The existing People page is an outreach management workspace.

Rename its navigation label and page title to **Outreach Contacts**.

It should manage people who have deliberately entered an outreach process, such as:
- Bible studies.
- Spiritual follow-up.
- Discipleship.
- Continued pastoral or evangelistic engagement.

Preserve the existing outreach features and workflows.

Do not convert this screen into a general person directory.

## 2. Person is the shared identity

A Person is the underlying identity record.

The same Person may have multiple relationships with UU Mission:
- Fieldwork participant.
- Outreach Contact.
- Missionary.
- Church member or other organisational relationship, where applicable.

Do not create duplicate Person records or separate identity tables for these concepts.

Use separate relationship records where appropriate.

## 3. Fieldwork is the entry point for general activity participation

When someone is registered through an activity's attendance register:

- Find an existing Person or create a minimal Person if the individual is identifiable.
- Create the appropriate activity participation/attendance relationship.
- Do not automatically create an OutreachContact.
- Do not automatically create an OutreachEncounter.
- Do not force a visitor/member/prospect classification.
- Allow anonymous attendance without a Person record.

A person can later be deliberately transitioned into OutreachContact using the same Person identity. Their previous participation history must remain connected.

## 4. Establish the exact role of ActivityParticipant

The current schema contains both ActivityParticipant and AttendanceEntry.

Determine whether ActivityParticipant represents:
- Planned/pre-registered participants.
- Actual activity participation.
- Both.

Recommend the cleanest minimal approach.

Do not create duplicate participation records or make Person registration responsible for Fieldwork data.

AttendanceEntry must remain the authoritative record of actual attendance.

## 5. Attendance registers are occurrence-based

We have decided that an activity may have multiple attendance registers.

Normally:
- One-day activity: one register.
- Multi-day activity: one register per day.
- Multiple sessions on the same day: separate registers where necessary.
- Recurring activity: separate register for each occurrence.

All registers remain linked to their parent activity.

The activity should provide an aggregated view of its attendance history.

A Person attending multiple days should have multiple attendance entries but remain one Person.

Investigate the existing schema and recommend how to represent the register date and optional session without unnecessarily duplicating existing fields.

## 6. Register creation UX

The intended workflow:

1. An authorised user opens an activity.
2. Selects Create Attendance Register.
3. The activity and site are preselected.
4. The user selects the attendance date.
5. Session name is optional.
6. The system checks for a duplicate register for the same activity/date/session.
7. The register opens for attendance marking.

Do not ask users to reselect campaign and site when these are already known from the activity.

Retain standalone roll-call registers for situations that do not have an associated activity.

## 7. Attendance entry options

Support:
- Existing Person.
- New identifiable Person.
- Anonymous participant.

Walk-in describes arrival without prior registration; it is not a membership or identity classification.

Preserve available information without forcing personal disclosure.

Provide a later-linking mechanism for anonymous or unidentified attendance entries.

## 8. Reconcile historical preservation

Review Person deletion, ActivityParticipant deletion, AttendanceEntry constraints and historical records.

Participation and attendance history must not disappear or become invalid when Person information changes or a Person is deleted.

## 9. Revised implementation plan

Return an updated phased plan covering:

1. Activity authorization defect.
2. Preserve and rename Outreach Contacts.
3. Shared Person identity and neutral registration from Fieldwork.
4. ActivityParticipant versus AttendanceEntry responsibilities.
5. Occurrence-based attendance register creation.
6. Identifiable and anonymous walk-ins.
7. Later identity linking.
8. Outreach transition using an existing Person.
9. Historical data preservation.
10. Tests and verification.

For every proposed change, identify exact tables, models, endpoints, controllers and frontend screens affected.

Pay special attention to existing data and backwards compatibility.

Do not implement anything until we approve the revised plan.

**Assistant**

Let me verify the exact current schema and controller details needed for an accurate revised plan.

Now the activity controller, models, routes, and frontend.

Verified all schema, authorization, and route details. Here is the revised phased plan.

---

# UU Mission — Revised People / Fieldwork / Attendance Plan

**Status: proposal only. No files or schema modified.**
Legend: ✅ Confirmed from code · 🔶 Inferred · ❌ Not currently supported

---

## Key schema facts this plan builds on (Confirmed)

- `attendance_registers`: `register_name` (required, 250), `register_date` (date, required), `register_type` (default `meeting`), `activity_id` **nullable**, `status` (`open|submitted|verified`), unique `(mission_site_id, register_date, register_type, register_name)`. **No `session_name` column.** An activity can already have many registers via `hasMany`.
- `attendance_entries`: `person_id` **nullable**, `visitor_name/visitor_phone/visitor_gender` present, CHECK `person_id IS NOT NULL OR visitor_name IS NOT NULL`, `attendee_type` default `'missionary'`, **no `updated_at`**.
- `activity_participants`: `person_id` **NOT NULL**, **`cascadeOnDelete`**, unique `(activity_id, person_id)`.
- **No `ActivityPolicy` exists and none is registered** in [AppServiceProvider.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Providers/AppServiceProvider.php#L44-L51) → every activity action throws (Confirmed).
- **No `DELETE /people/{person}` route** → Person deletion is not exposed via API; Person uses `SoftDeletes` (Confirmed).
- No `activities.*` permission codes exist in the seeder; only `attendance.*`, `people.*`, `outreach-contacts.*` (Confirmed).
- Nav label is `'People & Follow-up'` at [MissionaryShell.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/MissionaryShell.tsx#L41); module key `people` and route `/people` drive gating in [permissions.ts](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/lib/permissions.ts#L64-L89).

---

## Phase 1 — Activity authorization defect

**Problem (Confirmed):** `ActivityController` calls `authorize('viewAny'|'create'|'view'|'update'|'delete', ...)` but no policy class exists → `AuthorizationException`.

**Change:**
- New `backend/app/Policies/ActivityPolicy.php`, modelled on [AttendanceRegisterPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/AttendanceRegisterPolicy.php): resolve the activity's real `(campaign, site)` via `AuthorizationService`.
  - `viewAny(User, Campaign)` → `attendance.view|attendance.submit` in campaign scope.
  - `view/create/update(User, [Activity, Campaign] | Activity)` → `attendance.submit` at site (campaign-wide fallback).
  - `delete` → same as update, plus the existing `destroy()` guard.
- Register in [AppServiceProvider.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Providers/AppServiceProvider.php#L44-L51): `Gate::policy(Activity::class, ActivityPolicy::class);`
- **Option (defer):** introduce `activities.create/edit/view` codes + seeder role grants later. Minimal path reuses `attendance.submit` so no new permission system (honours the RBAC hard constraint).

**Affected:** `ActivityController.php` (no signature change needed), new `ActivityPolicy.php`, `AppServiceProvider.php`. 🔶 Frontend `activitiesApi` will start returning data instead of 403/500.

---

## Phase 2 — Preserve & rename Outreach Contacts

**Change (UI only, no data change):**
- [MissionaryShell.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/MissionaryShell.tsx#L41): label `'People & Follow-up'` → `'Outreach Contacts'`.
- `frontend/src/app/(missionary)/people/page.tsx`: title already "Outreach Contacts" (Confirmed) — verify heading/subtitle phrasing.
- **Keep** route `/people`, module key `people`, permission codes `people.*`/`outreach-contacts.*` untouched to avoid breaking gating, seeder, and `moduleForPath`. 🔶 Optional later: add `/outreach-contacts` redirect.
- Preserve all existing outreach workflows and components (EncounterFormModal, stage history, baptisms).

**Affected:** `MissionaryShell.tsx`, `people/page.tsx`. No backend change.

---

## Phase 3 — Shared Person identity & neutral registration from Fieldwork

**Intended:** registration via the register finds/reuses a Person or creates a minimal one; **never** auto-creates OutreachContact/OutreachEncounter; anonymous attendance allowed.

**Change:**
- Add a neutral create entry path that does **not** touch outreach. Options:
  - Reuse `POST /people` ([PersonController::store](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/PersonController.php#L118)) — it already avoids outreach — **but** it currently (a) requires at least one name and (b) side-creates an `ActivityParticipant` when `activity_id` is sent (see Phase 4). Guard-rail: stop it writing participation.
  - Add `POST /people/from-attendance` (or extend `updateEntries`) that creates a minimal Person (`first_name`/`last_name` nullable already ✅), then links the entry.
- **Duplicate-prevention:** reuse [checkDuplicates()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/PersonController.php#L226) (name-both OR exact phone, scope-constrained) before creating a walk-in-as-person.
- `OutreachContactController::store()` already accepts an existing `person_id` → Phase 8 transition reuses identity with no new Person (Confirmed).

**Affected:** `PersonController.php`, `AttendanceController.php`, `routes/api.php`; frontend `api.ts` (`peopleApi`), fieldwork page. **Data:** none (no schema change).

---

## Phase 4 — ActivityParticipant vs AttendanceEntry responsibilities

**Recommendation (minimal):**
- **`ActivityParticipant` = planned / pre-registered roster** (forward-looking intent; optional). Keep table, keep unique `(activity_id, person_id)`.
- **`AttendanceEntry` = authoritative record of actual attendance** (Confirmed already true). Keep as the source of truth.
- **Remove the coupling** where [PersonController::store](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/PersonController.php#L202-L209) creates an `ActivityParticipant` as a side effect of registering a Person. Roster changes should go through an explicit Fieldwork/activity endpoint instead.
- `AttendanceController::participants()` currently reads the roster for activity-linked registers — keep roster as a *candidate list*, but the participants endpoint must **merge** roster ∪ existing entries ∪ anonymous entries (see Phase 6) so walk-ins surface.

**Affected:** `PersonController.php`, `AttendanceController::participants()`, possibly new roster endpoints (e.g. `POST /campaigns/{campaign}/activities/{activity}/participants`). Frontend `fieldwork/page.tsx`, `api.ts`. **Data:** no schema change in this phase.

---

## Phase 5 — Occurrence-based attendance registers

**Recommendation — no new column needed:**
- `register_date` already models the occurrence date (Confirmed).
- Repurpose the **required** `register_name` as the **occurrence/session label** (e.g. "Day 2 — Morning"). This satisfies "session name is optional" because a single-occurrence activity can pass the activity title as the name. *Alternative* (if you prefer explicitness): add a nullable `session_name` column and keep `register_name` as a display title — I recommend **against** it as duplicative unless required.
- Multiple registers per activity/day already work (`activity_id` nullable + `hasMany`; the existing unique index includes `register_type` and `register_name`, so distinct sessions don't collide) ✅.
- **Fix the duplicate pre-check** in [AttendanceController::store](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AttendanceController.php#L123-L135): it currently ignores `activity_id`, so two different activities at the same site/date/type/name would wrongly 409. Make it activity-aware: when `activity_id` is set, check `(activity_id, register_date, register_name)`; when standalone, keep `(mission_site_id, register_date, register_type, register_name)`.
- Aggregated activity attendance view: derive from `activity.attendanceRegisters → entries` (already eager-loadable in [ActivityController::show](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/ActivityController.php#L95-L103)).

**Affected:** `AttendanceController.php` (store + index), `AttendanceRegister` model (optional helper), frontend `fieldwork/page.tsx` + possibly a new activity-detail screen. **Data:** none required.

---

## Phase 6 — Identifiable & anonymous walk-ins

**Problem (Confirmed):** `updateEntries` requires `person_id` and hardcodes `attendee_type='missionary'`; no endpoint writes `visitor_*`; walk-ins unreachable despite schema.

**Change — extend `POST /attendance-registers/{register}/entries`:**
- Accept per-entry either:
  - `person_id` (existing Person), or
  - new identifiable person payload (`first_name/last_name/phone` → find-or-create Person), or
  - **anonymous**: `visitor_name` (+ optional `visitor_phone`, `visitor_gender`).
- Set `attendee_type` **explicitly** from the payload (`missionary | visitor | local_member`) instead of forcing `'missionary'`.
- Preserve existing `is_first_time`, `decision_made`, `notes` handling.
- `updateOrCreate` key must not rely solely on `person_id` (anonymous rows have none) — key anonymous rows by the register + a client-supplied `entry_key`, or match on `visitor_name`/`visitor_phone`.
- Validation mirrors the DB CHECK: reject rows with neither `person_id` nor `visitor_name`.

**Affected:** [AttendanceController::updateEntries()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AttendanceController.php#L225-L282), [AttendanceEntry.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Models/AttendanceEntry.php) (fillable already includes visitor fields ✅), `api.ts` `attendanceApi.updateEntries` payload type, `fieldwork/page.tsx` (add walk-in row UI + "new person" vs "anonymous" toggle). **Data:** none (schema already supports this).

---

## Phase 7 — Later identity linking

**New:** `POST /attendance-registers/{register}/entries/{entry}/link-person` (or `PATCH`) accepting `person_id` (or new-person payload):
- Sets `person_id` on an existing anonymous entry, retains `visitor_*` as provenance, records `linked_at` (optional).
- Permission: `attendance.submit` at the register's site; register must not be `verified`.
- Optional convenience: same action from the Person profile ("link this attendance to…").

**Affected:** `AttendanceController.php`, `routes/api.php`, `AttendanceEntry` model (optional `linked_at` column — the only schema addition in this phase; needs a migration if you want the audit trail), `api.ts`, `people/[id]/page.tsx` + `fieldwork/page.tsx`. **Data:** optional additive column only.

---

## Phase 8 — Outreach transition using an existing Person

**Current state (Confirmed):** [OutreachContactController::store()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OutreachContactController.php) creates Person+Contact+Encounter if no `person_id`; one Contact per `(person, campaign)`; [OutreachEncounterController::store()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OutreachEncounterController.php) requires an existing Contact.

**Change:**
- Ensure the transition flow **always passes the existing `person_id`** (no new Person). Preferred: add `POST /outreach-contacts/from-person` or make the existing endpoint's `person_id` path the default when invoked from a Person profile.
- Prior participation/attendance history stays connected because it hangs off the same `person_id` (Confirmed — `attendanceEntries`/`activityParticipations` are Person relations).
- On the People→Outreach Contacts screen, keep current behaviour (it is the outreach workspace) but confirm it never generates duplicate Person rows (it already reuses `person_id` when supplied ✅).
- **Latent bug to fix later:** [OutreachEncounter::outreachContact()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Models/OutreachEncounter.php) is declared with the wrong FK (`person_id`) — correct to `outreach_contact_id` or a `hasOneThrough`.

**Affected:** `OutreachContactController.php`, `OutreachEncounterController.php`, `OutreachEncounter.php`, frontend `people/page.tsx`, `people/[id]/page.tsx`, `EncounterFormModal.tsx`. **Data:** none.

---

## Phase 9 — Historical data preservation

**Risks (Confirmed):**
- `activity_participants.person_id` is `cascadeOnDelete` + NOT NULL → hard-deleting a Person **destroys** participation rows.
- `attendance_entries.person_id` is `nullOnDelete`; a nulled person_id then **violates** `chk_ae_identifier` unless `visitor_name` exists → rows can become invalid/corrupt on hard delete.

**Change:**
- Person is already `SoftDeletes` (Confirmed) → the cascade only fires on hard delete. Mitigations:
  1. Make `activity_participants.person_id` **nullable** + change FK to `nullOnDelete` (mirrors attendance_entries), so history survives identity changes.
  2. When a Person is force-deleted, first copy identity into `visitor_name` on their attendance entries (data-backfill in the delete path) so the CHECK still holds.
  3. Do **not** expose hard delete; if a delete endpoint is ever added, make it soft-delete-only.
- Activity deletion already guarded when registers/outcomes exist (Confirmed) — keep.
- Backwards compatibility: existing rows already satisfy the CHECK; making participants nullable is additive and safe. A migration is needed only for the FK/nullability change.

**Affected:** migration for `activity_participants`, `Person`/`ActivityParticipant` models, any future delete endpoint. Frontend: none.

---

## Phase 10 — Tests & verification

**Current (Confirmed):** only [WorkspaceTest.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/tests/Feature/WorkspaceTest.php) exists; **no tests** for activities, registers, attendance, people, or encounters.

**Add (Feature tests):**
1. Activity CRUD authorised per site; sibling-site denial → 403 (Phase 1).
2. Register creation preselecting activity/site; duplicate detection is activity-aware; multi-day activity holds multiple registers (Phase 5).
3. Attendance entry with `person_id`; with new-person payload; **anonymous** `visitor_name` only; reject entry with neither (Phase 6).
4. Link anonymous entry to a Person; verify history still connected (Phase 7).
5. Outreach transition reuses `person_id`; no duplicate Person; prior attendance still visible (Phase 3/8).
6. Person soft-delete preserves attendance/participation; hard-delete path keeps entries valid (Phase 9).
7. Scope isolation: coordinator sees only their site; team-scoped user sees only parent-site context (Phase 1/3).

**Verification commands:** `php artisan test --filter=Fieldwork|Attendance|People`; frontend `tsc --noEmit` before committing UI changes.

---

## Data & backwards-compatibility summary

| Phase | Schema change | Risk |
|---|---|---|
| 1 Activity policy | none | none |
| 2 Rename label | none | none (route/key kept) |
| 3 Neutral registration | none | none |
| 4 Participant/Entry roles | none (behaviour only) | removing side-effect could affect existing flows that relied on it — verify callers |
| 5 Occurrence registers | none | controller-only dedupe fix |
| 6 Walk-ins | none (columns already exist) | keying anonymous rows must be deterministic |
| 7 Later linking | optional additive column | safe |
| 8 Outreach transition | none | correct the latent FK relation |
| 9 Preservation | nullable + `nullOnDelete` on participants | additive, safe; test backfill of CHECK |

---

## Open decisions for you
1. **Activity permission codes:** reuse `attendance.submit`/`attendance.view`, or introduce `activities.*`?
2. **Session representation:** reuse `register_name` as the session label (my recommendation) vs. add a nullable `session_name` column?
3. **Roster semantics:** keep `ActivityParticipant` as planned/pre-registration, or retire it and derive participants solely from attendance entries?
4. **Anonymous entry key:** client-supplied `entry_key` vs. match on `visitor_name`+`visitor_phone`.

Tell me which decisions you want, and I will turn this into an approved, ordered implementation task list. No code will be written until you authorise it.

---

**User**

The revised plan is accepted in principle, subject to the following decisions and corrections.

1. **Activity permissions:** Introduce distinct `activities.view`, `activities.create`, `activities.update` and `activities.delete` permissions. Do not reuse `attendance.submit` for activity management. Continue using AuthorizationService and active scoped role assignments.

2. **Register naming:** Keep `register_name` as the human-readable register title and `register_date` as the occurrence date. Do not add `session_name` at this stage. Generate sensible default register names in the UI.

3. **ActivityParticipant:** Keep it as an optional planned/pre-registered participant roster. `AttendanceEntry` remains the authoritative actual attendance record. Ensure the participant-list endpoint merges planned participants with existing attendance entries.

4. **Anonymous entry identity:** Use a persistent client-generated UUID for attendance-entry idempotency. Do not rely on names or phone numbers as unique identifiers.

5. **Truly anonymous attendance:** Correct the database CHECK constraint so an explicitly anonymous attendance entry can have both `person_id` and `visitor_name` null. Introduce an explicit, validated way to distinguish anonymous entries from invalid incomplete entries. Never invent placeholder names.

6. **Classification:** Do not conflate walk-in status with `attendee_type`. Walk-in describes arrival without prior registration. Attendee classification is a separate concept and should only be recorded when known and relevant. Do not force missionary/member/visitor classification.

7. **Register uniqueness:** Correct both the controller duplicate check and PostgreSQL unique constraints. Activity-linked registers must be unique within their activity/date/name context. Standalone registers must remain unique within their site/date/type/name context. Do not rely only on controller validation.

8. **Historical preservation:** Keep Person soft deletion as the normal deletion behaviour. Ensure historical attendance and planned participation remain valid. Do not implement hard-delete identity-copying as a routine mechanism.

9. Preserve Outreach Contacts as the deliberate outreach workflow, and preserve the existing shared Person identity. Attendance must not automatically create an OutreachContact or OutreachEncounter.

Please now return the final ordered implementation task list, including:
- Exact permission codes and role grants.
- Exact database constraint and migration changes.
- Exact API payloads and validation rules.
- The register creation and attendance-marking UX.
- The distinction between planned participants and actual attendance.
- Tests for all the above and existing outreach workflow regression tests.

Do not modify files yet. Wait for final implementation authorisation.

**Assistant**

Let me verify the seeder structure, AuthorizationService API, and a few remaining details so the task list is exact.

All verifications complete. Here is the final ordered implementation task list incorporating your nine decisions.

**Status: plan only — no files modified. Awaiting implementation authorisation.**

---

# UU Mission — Final Ordered Implementation Task List

## Decisions incorporated
1. New `activities.*` codes (not reusing `attendance.submit`).
2. Keep `register_name` + `register_date`; no `session_name`.
3. `ActivityParticipant` = optional planned roster; `AttendanceEntry` = authoritative; participant endpoint merges both.
4. Client-generated UUID for entry idempotency.
5. Anonymous entries explicitly supported via `is_anonymous`.
6. Classification separate from walk-in; never forced.
7. Partial unique indexes in Postgres + matching controller checks.
8. Soft-delete is the only deletion path; history stays valid.
9. Attendance never creates OutreachContact/OutreachEncounter.

---

## PHASE A — Activity authorization (Decision 1)

### Task A1 — Add permission codes
**File:** [DatabaseSeeder.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/seeders/DatabaseSeeder.php#L674-L698) `$permissions` array — add block:

```php
// activities module
['code' => 'activities.view',   'label' => 'View field activities',   'module' => 'activities'],
['code' => 'activities.create', 'label' => 'Create field activities', 'module' => 'activities'],
['code' => 'activities.update', 'label' => 'Update field activities', 'module' => 'activities'],
['code' => 'activities.delete', 'label' => 'Delete field activities', 'module' => 'activities'],
```

### Task A2 — Role grants
**File:** same seeder, `$roleData` (lines [709–784](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/seeders/DatabaseSeeder.php#L709-L784)). Resulting grants:

| Role | activities permissions |
|---|---|
| `platform_admin` | all (auto via `array_keys($permMap)`) |
| `union_director` | `activities.view` |
| `campaign_leader` | `activities.view`, `activities.create`, `activities.update`, `activities.delete` |
| `site_coordinator` | `activities.view`, `activities.create`, `activities.update`, `activities.delete` |
| `site_secretary` | `activities.view` |
| `team_leader` | `activities.view` |
| `team_secretary` | `activities.view` |
| `missionary` | `activities.view` |

Seeding is idempotent (`firstOrCreate` + `syncWithoutDetaching`). **Note:** existing environments need `php artisan db:seed --class=DatabaseSeeder` re-run (or a one-off data migration) to materialise the new codes.

### Task A3 — Create `ActivityPolicy`
**New file:** `backend/app/Policies/ActivityPolicy.php`, modelled on [AttendanceRegisterPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/AttendanceRegisterPolicy.php):

- `viewAny(User $user, Campaign $campaign)` → `activities.view` in campaign scope.
- `create(User $user, Campaign $campaign)` → `activities.create` at campaign site scope.
- `view(User, Activity)` / `update(User, Activity)` / `delete(User, Activity)` → resolve `activity->site` then `activity->campaign`; check `activities.view` / `activities.update` / `activities.delete` via `hasPermissionForSite` → `hasPermissionForCampaign` fallback.

Compatible with the existing call sites ([ActivityController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/ActivityController.php#L34-L143) already passes `[Activity::class, $campaign]` for viewAny/create and `$activity` for the rest — no controller signature change needed).

### Task A4 — Register the policy
**File:** [AppServiceProvider.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Providers/AppServiceProvider.php#L44-L51) — add `Gate::policy(Activity::class, ActivityPolicy::class);` and the import.

### Task A5 — Frontend permission surface
**File:** [permissions.ts](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/lib/permissions.ts#L29-L73) — add the four `activities.*` codes to `OPERATIONAL_PERMISSIONS`; add `activities.view` to the `fieldwork` module gate set (`MODULE_PERMISSIONS.fieldwork`).

---

## PHASE B — Rename Outreach Contacts (Decision 9, UI only)

### Task B1 — Nav label
**File:** [MissionaryShell.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/MissionaryShell.tsx#L41) — `'People & Follow-up'` → `'Outreach Contacts'`. Keep `href: '/people'`, module key `people`.

### Task B2 — Page copy
**File:** `frontend/src/app/(missionary)/people/page.tsx` — confirm title "Outreach Contacts"; adjust subtitle to reflect deliberate outreach (Bible studies, follow-up, discipleship). Keep all existing outreach features unchanged. Keep `/people` route and module gating untouched.

---

## PHASE C — Database constraints & migrations

### Task C1 — Migration: attendance-register uniqueness (Decision 7)
**New file:** `backend/database/migrations/2026_11_05_010000_fix_attendance_registers_unique_constraints.php`

Up:
```sql
DROP INDEX ar_unique_register;

CREATE UNIQUE INDEX ar_unique_activity_occurrence
  ON attendance_registers (activity_id, register_date, register_name)
  WHERE activity_id IS NOT NULL;

CREATE UNIQUE INDEX ar_unique_standalone
  ON attendance_registers (mission_site_id, register_date, register_type, register_name)
  WHERE activity_id IS NULL;
```
Down: recreate `ar_unique_register` on `(mission_site_id, register_date, register_type, register_name)`; drop the two partial indexes.

### Task C2 — Migration: attendance-entry identity columns (Decisions 4, 5, 6)
**New file:** `2026_11_05_010100_add_identity_columns_to_attendance_entries.php`

Up:
1. `entry_uuid` — `uuid`, nullable initially; backfill existing rows with `Str::uuid()` in chunks; then set `NOT NULL`; add `UNIQUE (entry_uuid)`.
2. `is_anonymous` — `boolean`, `NOT NULL`, `DEFAULT false`.
3. `is_walk_in` — `boolean`, nullable (historical snapshot; walk-in ≠ classification).
4. `attendee_type` — `DROP DEFAULT`, `DROP NOT NULL` (classification now optional, never forced).

Down: drop the columns; restore `attendee_type` default `'missionary'` + `NOT NULL`.

### Task C3 — Migration: attendance-entry CHECK constraint (Decision 5)
**New file:** `2026_11_05_010200_fix_attendance_entries_identifier_constraint.php`

Up:
```sql
ALTER TABLE attendance_entries DROP CONSTRAINT chk_ae_identifier;

ALTER TABLE attendance_entries ADD CONSTRAINT chk_ae_identity CHECK (
  is_anonymous = true OR person_id IS NOT NULL OR visitor_name IS NOT NULL
);

ALTER TABLE attendance_entries ADD CONSTRAINT chk_ae_anon_exclusive CHECK (
  NOT (is_anonymous = true AND (person_id IS NOT NULL OR visitor_name IS NOT NULL))
);
```
Down: drop both; restore `chk_ae_identifier`.

### Task C4 — Migration: planned-participation preservation (Decision 8)
**New file:** `2026_11_05_010300_restrict_activity_participant_person_delete.php`

Up:
```sql
ALTER TABLE activity_participants DROP CONSTRAINT activity_participants_person_id_foreign;
ALTER TABLE activity_participants
  ADD CONSTRAINT activity_participants_person_id_foreign
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT;
```
Rationale: `person_id` stays `NOT NULL` (roster integrity); a Person can never be hard-deleted while planned participation exists. Soft delete (the normal path, `SoftDeletes` on Person) never touches the FK. Down: restore `ON DELETE CASCADE`.

> Attendance entries already use `nullOnDelete`; because of `chk_ae_identity`, a hard delete of a person whose entries are neither anonymous nor named would be refused by the DB — consistent with Decision 8. No identity-copying routine is introduced.

### Task C5 — Model fillables
- [AttendanceEntry.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Models/AttendanceEntry.php#L11-L15): add `entry_uuid`, `is_anonymous`, `is_walk_in` to `$fillable`; cast `is_anonymous`/`is_walk_in` to boolean.
- `AttendanceRegister` / `ActivityParticipant`: no change.

---

## PHASE D — Planned participants vs actual attendance (Decision 3)

### Task D1 — Stop Person registration creating participation
**File:** [PersonController::store](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/PersonController.php#L202-L209) — remove the `if ($request->filled('activity_id')) { activityParticipations()->create(...) }` side effect. Person registration stays identity-only.

### Task D2 — Explicit roster endpoint
**New endpoints** in `routes/api.php` (nested under activities):
```
GET    /campaigns/{campaign}/activities/{activity}/participants
POST   /campaigns/{campaign}/activities/{activity}/participants
DELETE /campaigns/{campaign}/activities/{activity}/participants/{participant}
```
Controller: extend `ActivityController` or add `ActivityParticipantController`.
- POST body: `{ person_id }` or `{ person: {first_name,last_name,phone,...} }` (find-or-create Person). Validates `activities.update` at the activity's site. Unique `(activity_id, person_id)` enforced.
- Semantics: **planned/pre-registered roster only** — never creates AttendanceEntry or OutreachContact.

### Task D3 — Participant-list merge
**File:** [AttendanceController::participants()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AttendanceController.php#L165-L217) — rewrite to return the **union**:
1. planned `ActivityParticipant` rows (roster),
2. existing `AttendanceEntry` rows (incl. anonymous entries with no `person_id`),
3. deduplicate by `person_id` (roster ∪ entries); anonymous entries always appear.

New response shape (entry-centric):
```json
[
  { "entry_uuid": "...", "person_id": 12, "name": "Jane Doe", "village": "...", "phone": "...",
    "planned": true, "is_present": true, "attendee_type": null, "is_walk_in": false,
    "is_anonymous": false, "is_first_time": false, "decision_made": null, "notes": null },
  { "entry_uuid": "...", "person_id": null, "name": null, "planned": false, "is_present": true,
    "is_anonymous": true, "attendee_type": null, "is_walk_in": true, "is_first_time": false }
]
```
Standalone registers (no `activity_id`): planned list is empty; return existing entries + previously-attending people in scope.

---

## PHASE E — Register creation & attendance UX (Decisions 2, 6, 7)

### Task E1 — Register store endpoint accepts activity-first payload
**File:** [AttendanceController::store()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AttendanceController.php#L95-L144)

Validation:
```php
'activity_id'     => ['required_without:mission_site_id', 'integer', 'exists:activities,id'],
'campaign_id'     => ['required_without:activity_id', 'integer', 'exists:campaigns,id'],
'mission_site_id' => ['required_without:activity_id', 'integer', 'exists:mission_sites,id'],
'register_name'   => ['required', 'string', 'max:250'],
'register_date'   => ['required', 'date'],
'register_type'   => ['sometimes', 'string', 'in:meeting,roll_call,service,training,community_event'],
```
Behaviour: when `activity_id` present, derive `campaign_id` + `mission_site_id` from the activity (reject if supplied and mismatched); authorise `attendance.submit` at the activity's site.

### Task E2 — Correct duplicate pre-check
Replace the query at [L123–L135](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AttendanceController.php#L123-L135):
```php
$dup = AttendanceRegister::query()
    ->when($activityId, fn($q) => $q->where('activity_id', $activityId)
                                     ->where('register_date', $date)
                                     ->where('register_name', $name),
           fn($q) => $q->whereNull('activity_id')
                        ->where('mission_site_id', $siteId)
                        ->where('register_date', $date)
                        ->where('register_type', $type)
                        ->where('register_name', $name))
    ->first();
```
Return `409` with the existing register so the UI can offer "open existing". Mirrors the DB partial indexes (Task C1).

### Task E3 — Fieldwork UI: activity-first register creation
**File:** [fieldwork/page.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/(missionary)/fieldwork/page.tsx)

- Add an **activity detail view** with "Create Attendance Register".
- Modal preselects activity + site + campaign (read-only); user picks **date** (default: today or activity `scheduled_at`) and optional session/name.
- **Default register name generation** (client-side, Decision 2): e.g. `"{activity.title}"` for one-off, `"{activity.title} — Day {n}"` for multi-day, `"{activity.title} — {date}"` fallback. User may edit.
- On `409`, show "A register already exists for this date" and offer to open it.
- Retain a **standalone roll-call** entry point (campaign/site chosen explicitly).
- Activity detail shows an **aggregated attendance history** (registers → entries, totals).

### Task E4 — Attendance-marking UI
- Rows = merged list from Task D3; planned rows tagged "Planned".
- Controls: present toggle; optional classification selector (`missionary | church_member | visitor | other`, default **unset**); optional first-time / decision / notes; a walk-in toggle (default off, auto-on for unplanned additions).
- "+ Add person" → search existing (`peopleApi` / `checkDuplicates`) → sets `person_id`.
- "+ Add walk-in" → either a **named unidentified** row (`visitor_name`/`visitor_phone`) or **anonymous** row (`is_anonymous = true`). UI may render "Anonymous attendee #n" as a label only — **never persisted as a name**.
- Client generates one `entry_uuid` per row (`crypto.randomUUID()`), reused across saves for idempotency.
- Save is disabled on zero present rows (existing behaviour kept).

---

## PHASE F — API payloads & validation (Decisions 4, 5, 6)

### Task F1 — Rewrite `POST /attendance-registers/{register}/entries`
**File:** [updateEntries()](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AttendanceController.php#L225-L282)

Request:
```json
{
  "entries": [
    { "entry_uuid": "5b1e...", "is_present": true, "person_id": 42,
      "attendee_type": null, "is_walk_in": false, "is_first_time": false,
      "decision_made": null, "notes": null },

    { "entry_uuid": "8c2a...", "is_present": true,
      "person": { "first_name": "Amina", "last_name": "K", "phone": "07..." } },

    { "entry_uuid": "d94f...", "is_present": true, "is_anonymous": true },

    { "entry_uuid": "ff03...", "is_present": true, "visitor_name": "Unidentified man",
      "visitor_phone": null, "visitor_gender": "male" }
  ]
}
```

Validation (FormRequest recommended):
```php
'entries'                    => ['required','array'],
'entries.*.entry_uuid'       => ['required','uuid'],
'entries.*.is_present'       => ['required','boolean'],
'entries.*.person_id'        => ['sometimes','nullable','integer','exists:people,id'],
'entries.*.person'           => ['sometimes','array'],
'entries.*.person.first_name'=> ['required_with:entries.*.person','nullable','string','max:100'],
'entries.*.person.last_name' => ['required_with:entries.*.person','nullable','string','max:100'],
'entries.*.person.phone'     => ['sometimes','nullable','string','max:30'],
'entries.*.is_anonymous'     => ['sometimes','boolean'],
'entries.*.visitor_name'     => ['sometimes','nullable','string','max:200'],
'entries.*.visitor_phone'    => ['sometimes','nullable','string','max:30'],
'entries.*.visitor_gender'   => ['sometimes','nullable','in:male,female,unspecified'],
'entries.*.attendee_type'    => ['sometimes','nullable','in:missionary,church_member,visitor,other'],
'entries.*.is_walk_in'       => ['sometimes','nullable','boolean'],
'entries.*.is_first_time'    => ['sometimes','boolean'],
'entries.*.decision_made'    => ['sometimes','nullable','in:accepted_christ,requested_bible_study,joined_church,none'],
'entries.*.notes'            => ['sometimes','nullable','string'],
```
Plus an `after` rule enforcing mutual exclusivity per row:
- if `is_anonymous = true` → `person_id`, `person`, `visitor_name` must all be absent;
- else exactly one of `person_id` / `person` / `visitor_name` required.

Server behaviour:
- Idempotent upsert by `(attendance_register_id, entry_uuid)`.
- `is_present = false` → delete the row by `entry_uuid`.
- `person` object → find-or-create via `checkDuplicates` logic, then link.
- **Never** sets `attendee_type` unless supplied; **never** creates OutreachContact/OutreachEncounter (Decision 9 — assert in tests).
- Reject when register status is `submitted`/`verified` (existing rule kept).

### Task F2 — Link-person endpoint
```
POST /attendance-registers/{register}/entries/{entry}/link-person
     { "person_id": 42 }  |  { "person": { "first_name": "...", ... } }
```
Sets `person_id`, `is_anonymous = false`; retains `visitor_*` as provenance; permission `attendance.submit`; register must not be `verified`.

### Task F3 — Frontend API types
**File:** [api.ts](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/lib/api.ts#L1402-L1434) — update `AttendanceParticipant`, `attendanceApi.updateEntries` payload, `attendanceApi.create` (activity-first), add `linkPerson` and roster endpoints. Ensure undefined/null omission (no `undefined` serialised as `"undefined"`).

---

## PHASE G — Historical preservation (Decision 8)

### Task G1 — Confirm soft-delete remains the only path
- No `DELETE /people/{person}` route exists (Confirmed) — keep it that way.
- Person keeps `SoftDeletes`; attendance/participation history stays referenced.
- DB safety: Task C4 (RESTRICT) + Task C3 (`chk_ae_identity`) guarantee a hard delete cannot silently destroy or invalidate history.
- No identity-copying routine is added (explicitly per Decision 8).

### Task G2 — Verification documentation
Add a short note in the plan-of-record that soft-deleted people remain visible in read-only historical contexts (attendance/roster views show the linked person, marked inactive).

---

## PHASE H — Tests

**File:** `backend/tests/Feature/` (new test classes; currently only [WorkspaceTest.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/tests/Feature/WorkspaceTest.php) exists).

### Task H1 — Activity authorization
- `activities.view` grants listing; `activities.create` required to create; 403 without.
- Sibling-site user cannot view/update/delete another site's activity (403/404).
- `viewAny` constrained to authorised campaign scope.

### Task H2 — Register creation & uniqueness
- Activity-linked register derives campaign/site; no reselection needed.
- Duplicate `(activity_id, date, name)` → 409; duplicate standalone → 409; same date/session under different activity → allowed.
- Multi-day activity holds multiple registers; one Person across days = one Person, many entries.

### Task H3 — Attendance entries
- Existing person entry (`person_id`).
- New identifiable person via `person` object (find-or-create; no duplicate Person).
- Anonymous entry (`is_anonymous = true`, both ids null) succeeds.
- Named unidentified entry (`visitor_name` only) succeeds.
- Invalid: `is_anonymous = true` + `person_id` → 422; no identity and not anonymous → 422.
- Idempotency: repeated save with same `entry_uuid` does not duplicate rows.
- `attendee_type` left null when not supplied (no forced `'missionary'`).

### Task H4 — Merge & roster
- Participant endpoint merges planned + attendance, deduped by `person_id`, includes anonymous rows.
- Removing a planned participant does not delete attendance history.

### Task H5 — Link-person
- Anonymous entry links to a Person; history preserved; provenance retained.

### Task H6 — History preservation
- Soft-delete a Person → attendance + planned participation still resolve.
- Hard delete refused where participation exists (RESTRICT) / does not invalidate attendance.

### Task H7 — Outreach workflow regression (Decision 9)
- **Attendance does not create OutreachContact or OutreachEncounter** (assert counts unchanged after `updateEntries`).
- Outreach Contacts `store` still creates Person+Contact+Encounter as today.
- Outreach transition from an existing `person_id` keeps prior attendance/participation linked and creates no duplicate Person.
- Encounter still requires an existing OutreachContact (current behaviour intact).

### Task H8 — Frontend verification
- `tsc --noEmit` clean after `api.ts` + `fieldwork/page.tsx` changes.
- Manual: activity → create register (preselected) → mark planned + walk-in + anonymous → save → reopen → data intact.

---

## Suggested execution order
A1 → A2 → A3 → A4 → A5 → B1 → B2 → C1 → C2 → C3 → C4 → C5 → D1 → D2 → D3 → E1 → E2 → F1 → F2 → F3 → E3 → E4 → G1 → G2 → H1–H8.

**Backward compatibility notes**
- `participants()` response shape changes → fieldwork UI must ship with the API change (Phase D/E together).
- `attendee_type` losing its default is additive-safe (existing rows keep their stored value).
- Seeder must be re-run for `activities.*` codes in existing environments.
- Partial unique indexes are additive; the old index is dropped in the same migration.

**Open items requiring your confirmation before build**
1. Should `site_coordinator` also hold `activities.delete`, or restrict deletion to `campaign_leader`/admin only?
2. Should `is_walk_in` be stored as a snapshot, or derived purely from roster membership (no column)?
3. Named-but-unidentified entries (`visitor_name`) — retain as a supported mode, or force either identified-person or fully anonymous?

No files have been modified. Awaiting your final implementation authorisation.

---

**User**

**IMPLEMENTATION AUTHORISATION — UU MISSION**

I approve the full implementation plan, Phases A–H.

Proceed with implementation using the final approved decisions:

1. `site_coordinator` receives `activities.view`, `activities.create`, `activities.update`, and `activities.delete`.
2. `is_walk_in` is stored as a historical snapshot, not derived from current roster membership.
3. Support named-but-unidentified attendance entries without creating a Person. Anonymous entries must also be supported explicitly.
4. Development mode: backward compatibility with old development data and APIs is not required. You may replace or simplify existing structures where appropriate.
5. Preserve the agreed separation:
   - Person = permanent identity.
   - ActivityParticipant = planned roster.
   - AttendanceEntry = actual attendance.
   - OutreachContact = intentional outreach relationship.
   - OutreachEncounter = actual outreach interaction.
6. Attendance must never automatically create OutreachContacts or OutreachEncounters.
7. Enforce authorization server-side, including campaign/site/team boundaries. Do not introduce legacy permission fallbacks.
8. Preserve historical records and use soft deletion for people.

**Execution requirements:**
- Follow the approved A–H order.
- Implement migrations, models, policies, controllers, API contracts and frontend together where their contracts depend on each other.
- Do not stop after planning or partial implementation.
- Run migrations and reseed development permissions as needed.
- Add the planned backend feature tests.
- Run the full backend test suite, frontend TypeScript check and production build.
- Fix failures caused by this implementation before reporting completion.
- Do not expand the product scope with unrelated features.

At completion, report:
- Files changed and migrations added.
- Implemented features.
- Test results and build results.
- Any remaining genuine blockers.

You are authorised to modify the files and database in the development environment.

**Assistant**

I'll start by setting up a task list and reconnoitering the exact current state of the codebase and environment.

Environment confirmed: PostgreSQL 16 (Laragon) on port 5433, test DB `uu_mission_test`. Let me verify the toolchain works.

Baseline: 113 tests passing. Now let me read the core files I'll modify and the existing test patterns.

Now let me read the key files needed for Phase A and C.

Now Phase A. Seeder permissions first.

Now the ActivityPolicy.

Now the frontend permission surface.

Now Phase C migrations. Let me verify the test DB connection first.

Now Phase F/D — rewriting the attendance controller. First the participants endpoint (merge).

Now the entries update endpoint rewrite plus the link endpoint.

Now add the `findDuplicateRegister` helper next to the other private helpers.

Now Phase E — the fieldwork page rewrite.

I'll pick up from the remaining phases. Let me first establish the current state and set up task tracking.

People page title is already "Outreach Contacts". Now updating the nav label and the subtitle to reflect intentional outreach (not every person).

Phase B complete. Now let me inspect the implemented controllers and existing test conventions before Phase H.

Let me examine the test conventions and the remaining implementation files.

Now let me make the `is_walk_in` snapshot strictly historical (preserved across updates of the same row).

`ActivityPolicy::create` only checks campaign scope, which would lock out site-scoped coordinators. Fixing that.

Now replace the now-unused `validateSiteScope` helper with a `resolveSite` helper.

Let me enforce site/campaign boundaries on the activity list too (currently it returns every activity in the campaign).

Now writing the Phase H backend feature tests.

---

