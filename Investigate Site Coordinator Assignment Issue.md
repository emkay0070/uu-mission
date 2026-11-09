# Investigate Site Coordinator Assignment Issue

> **Workspace:** e:\Year 2026\Codding\UU Mission

---

**User**

hey check y I get Un authorized/anuthenticted to assign site coordinstor to the Joseph by the platform admin.., en also report ur othe finds..

**Assistant**

I'll investigate the authorization issue with site coordinator assignment. Let me search the codebase.

I'll investigate the UU Mission Laravel backend to find everything related to role assignment and authorization. Let me search systematically across multiple areas in parallel.

Now let me read all the key files in parallel to understand the role assignment and authorization flow:

Let me continue searching for middleware, permissions, and additional related files:

Now let me look at a few more critical files to complete the picture:

Based on my comprehensive investigation of the UU Mission Laravel backend, I have traced the complete authorization flow for role assignment. Here is my detailed report:

---

## COMPREHENSIVE INVESTIGATION REPORT: Role Assignment & Authorization in UU Mission Laravel Backend

### 1. CONTROLLERS HANDLING ROLE ASSIGNMENT

#### 1.1 UserRoleAssignmentController
**File Path:** `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\UserRoleAssignmentController.php`

This is the primary controller for managing scoped role assignments. Key methods:

**store() method (lines 93-145)** - Creates new role assignments:
```php
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'user_id' => ['required', 'integer', 'exists:users,id'],
        'system_role_id' => ['required', 'integer', 'exists:system_roles,id'],
        'scope_type' => ['required', 'string', 'in:global,org_unit,campaign,site,team'],
        'scoped_org_unit_id' => ['required_if:scope_type,org_unit', 'nullable', 'integer', 'exists:org_units,id', 'prohibited_unless:scope_type,org_unit'],
        // ... more validation rules
    ]);
    
    $this->authorize('create', [UserRoleAssignment::class, $validated]);
    // ... creates assignment with permission checks
}
```

**indexForCampaign() method (lines 30-41)**:
```php
public function indexForCampaign(Request $request, Campaign $campaign): JsonResponse
{
    $this->authorize('viewAnyForCampaign', [UserRoleAssignment::class, $campaign]);
    // ... returns campaign-scoped assignments
}
```

#### 1.2 AssignmentController
**File Path:** `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\AssignmentController.php`

This controller manages missionary deployments and creates system role assignments alongside campaign assignments. Key methods:

**store() method (lines 66-124)** - Creates assignments with optional system role:
```php
public function store(Request $request, Campaign $campaign): JsonResponse
{
    $this->authorize('create', [CampaignAssignment::class, $campaign]);
    
    $validated = $request->validate([
        'person_id' => ['required', 'integer', 'exists:people,id'],
        // ... other validation
        'system_role_id' => ['sometimes', 'nullable', 'integer', 'exists:system_roles,id'],
    ]);
    
    // ... creates assignment and potentially system role assignment
    if (! empty($validated['system_role_id']) && $person->user_id) {
        $rolePayload = $this->roleAssignmentPayload($request, $campaign, $validated, $person->user_id);
        $this->authorize('create', [UserRoleAssignment::class, $rolePayload]);
        // ... creates UserRoleAssignment
    }
}
```

---

### 2. MODELS

#### 2.1 UserRoleAssignment Model
**File Path:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\UserRoleAssignment.php`

This is the core model for scoped RBAC assignments. Key attributes (lines 18-24):
```php
protected $fillable = [
    'user_id', 'system_role_id', 'scope_type',
    'scoped_org_unit_id', 'scoped_campaign_id', 'scoped_site_id', 'scoped_team_id',
    'valid_from', 'valid_until', 'granted_by_user_id',
    'role_permission_version_id', 'status', 'revoked_by_user_id', 'revoked_at', 'revocation_reason',
    'suspended_by_user_id', 'suspended_at', 'suspension_reason',
];
```

**Key relationships:**
- `user()` - belongs to User
- `role()` - belongs to SystemRole  
- `grantedBy()` - belongs to User (who granted the role)
- `permissionVersion()` - belongs to RolePermissionVersion
- `permissionOverrides()` - has many UserRoleAssignmentOverride

**isActive() method (lines 99-106):**
```php
public function isActive(): bool
{
    $today = now()->toDateString();
    $fromOk = $this->valid_from === null || $this->valid_from->toDateString() <= $today;
    $untilOk = $this->valid_until === null || $this->valid_until->toDateString() >= $today;

    return $this->status === 'active' && $this->role?->is_active === true && $fromOk && $untilOk;
}
```

#### 2.2 SystemRole Model
**File Path:** `e:\Year 2026\\Codding\UU Mission\backend\app\Models\SystemRole.php`

Defines reusable system roles. Key attributes (line 20):
```php
protected $fillable = ['code', 'label', 'scope_type', 'is_active', 'current_permission_version_id'];
```

**Key relationships:**
- `permissions()` - belongs to many Permission
- `roleAssignments()` - has many UserRoleAssignment
- `currentPermissionVersion()` - belongs to RolePermissionVersion
- `permissionVersions()` - has many RolePermissionVersion

#### 2.3 Permission Model
**File Path:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\Permission.php`

Individual capability codes:
```php
protected $fillable = ['code', 'label', 'module'];
```

---

### 3. AUTHORIZATION SERVICE

**File Path:** `e:\Year 2026\Codding\UU Mission\backend\app\Services\AuthorizationService.php`

This is the single source of truth for scoped authorization decisions. Key methods:

#### 3.1 Permission Check Methods

**hasPermissionForCampaign() (lines 50-53):**
```php
public function hasPermissionForCampaign(User $user, string|array $permissions, Campaign $campaign): bool
{
    return $this->effectivePermissionDecision($this->resolveEffectiveAssignments($user, $campaign), $permissions);
}
```

**hasPermissionForSite() (lines 67-77):**
```php
public function hasPermissionForSite(
    User $user,
    string|array $permissions,
    MissionSite $site,
    bool $includeTeamScopedAssignments = true
): bool {
    return $this->effectivePermissionDecision(
        $this->resolveEffectiveAssignments($user, $site->campaign, $site, null, $includeTeamScopedAssignments),
        $permissions
    );
}
```

**hasGlobalPermission() (lines 95-101):**
```php
public function hasGlobalPermission(User $user, string $permission): bool
{
    return $this->effectivePermissionDecision(
        $this->getActiveAssignments($user)->where('scope_type', 'global'),
        $permission
    );
}
```

**hasPermissionForOrgUnit() (lines 107-117):**
```php
public function hasPermissionForOrgUnit(User $user, string $permission, int $orgUnitId): bool
{
    $matching = $this->getActiveAssignments($user)
        ->filter(fn (UserRoleAssignment $a) => $a->scope_type === 'org_unit'
            && $this->orgUnitCoversOrgUnit($a->scoped_org_unit_id !== null ? (int) $a->scoped_org_unit_id : null, $orgUnitId));

    return $this->effectivePermissionDecision($matching, $permission);
}
```

#### 3.2 getActiveAssignments() (lines 888-917)
Loads and caches the user's active role assignments:
```php
private function getActiveAssignments(User $user): Collection
{
    if (! isset($this->assignmentCache[$user->id])) {
        $today = now()->toDateString();

        $this->assignmentCache[$user->id] = UserRoleAssignment::with(['role', 'permissionVersion.permissions', 'permissionOverrides.permission'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->whereHas('role', fn ($q) => $q->where('is_active', true))
            ->get();
        // ... fallback for permission versions
    }
    return $this->assignmentCache[$user->id];
}
```

#### 3.3 effectivePermissionDecision() (lines 961-976)
Core permission decision logic with deny override support:
```php
private function effectivePermissionDecision(Collection $assignments, string|array $permissions): bool
{
    foreach ((array) $permissions as $permission) {
        $effects = $assignments->map(fn (UserRoleAssignment $assignment) => $this->permissionEffect($assignment, $permission));

        if ($effects->contains('deny')) {
            continue;
        }

        if ($effects->contains('grant')) {
            return true;
        }
    }

    return false;
}
```

---

### 4. ROUTES

**File Path:** `e:\Year 2026\Codding\UU Mission\backend\routes\api.php`

All routes use `auth:sanctum` middleware (applied at line 102).

#### 4.1 Role Assignment Routes (lines 426-433)
```php
Route::prefix('role-assignments')->group(function () {
    Route::post('/', [UserRoleAssignmentController::class, 'store']);
    Route::get('/{assignment}', [UserRoleAssignmentController::class, 'show']);
    Route::patch('/{assignment}', [UserRoleAssignmentController::class, 'update']);
    Route::delete('/{assignment}', [UserRoleAssignmentController::class, 'destroy']);
    Route::put('/{assignment}/permission-overrides', [UserRoleAssignmentController::class, 'setPermissionOverride']);
    Route::delete('/{assignment}/permission-overrides/{permission}', [UserRoleAssignmentController::class, 'removePermissionOverride']);
});
```

#### 4.2 Scoped Role Assignment Index Routes (lines 236-247)
```php
// Role Assignments (system roles scoped to campaign/site/team)
Route::prefix('/{campaign}/role-assignments')->group(function () {
    Route::get('/', [UserRoleAssignmentController::class, 'indexForCampaign']);
});

Route::prefix('/{campaign}/sites/{site}/role-assignments')->group(function () {
    Route::get('/', [UserRoleAssignmentController::class, 'indexForSite']);
});

Route::prefix('/{campaign}/teams/{team}/role-assignments')->group(function () {
    Route::get('/', [UserRoleAssignmentController::class, 'indexForTeam']);
});
Route::get('/org-units/{orgUnit}/role-assignments', [UserRoleAssignmentController::class, 'indexForOrgUnit']);
```

#### 4.3 RBAC Administration Routes (lines 435-443)
```php
Route::prefix('rbac')->group(function () {
    Route::get('/roles', [SystemRoleAdministrationController::class, 'index']);
    Route::get('/roles/{role}/permission-versions', [SystemRoleAdministrationController::class, 'versions']);
    Route::get('/permission-delegations', [SystemRoleAdministrationController::class, 'delegations']);
    Route::put('/roles/{role}/permission-versions', [SystemRoleAdministrationController::class, 'publishPermissions']);
    Route::put('/roles/{role}/compatible-scopes', [SystemRoleAdministrationController::class, 'setCompatibleScopes']);
    Route::post('/permission-delegations', [SystemRoleAdministrationController::class, 'createDelegation']);
    Route::delete('/permission-delegations/{delegation}', [SystemRoleAdministrationController::class, 'revokeDelegation']);
});
```

---

### 5. POLICY CLASSES

#### 5.1 UserRoleAssignmentPolicy
**File Path:** `e:\Year 2026\Codding\UU Mission\backend\app\Policies\UserRoleAssignmentPolicy.php`

This is the main policy governing role assignment authorization. Key methods:

**create() method (lines 77-130)** - Authorization for creating role assignments:
```php
public function create(User $user, array $validated): bool
{
    $scopeType = $validated['scope_type'];

    // Prevent users from assigning themselves elevated roles
    if ($validated['user_id'] === $user->id) {
        return false; // Block all self-assignment to prevent escalation
    }

    $canManage = match ($scopeType) {
        'global' => $this->auth->hasGlobalPermission($user, 'assignments.manage'),
        'org_unit' => isset($validated['scoped_org_unit_id']) && $this->auth->hasPermissionForOrgUnit($user, 'assignments.manage', (int) $validated['scoped_org_unit_id']),
        'campaign' => isset($validated['scoped_campaign_id'])
                      && $this->auth->hasPermissionForCampaign(
                          $user,
                          'assignments.manage',
                          Campaign::find($validated['scoped_campaign_id'])
                      ),
        'site' => isset($validated['scoped_site_id'])
                      && $this->auth->hasPermissionForSite(
                          $user,
                          'assignments.manage',
                          MissionSite::find($validated['scoped_site_id']),
                          false
                      ),
        'team' => isset($validated['scoped_team_id'])
                      && $this->auth->hasPermissionForTeam(
                          $user,
                          'assignments.manage',
                          Team::find($validated['scoped_team_id'])
                      ),
        default => false,
    };

    if (! $canManage) {
        return false;
    }
    
    // ... additional role compatibility and permission delegation checks
}
```

**update() method (lines 136-151)**:
```php
public function update(User $user, UserRoleAssignment $assignment): bool
{
    // Prevent users from modifying their own assignments
    if ($assignment->user_id === $user->id) {
        return false;
    }

    return match ($assignment->scope_type) {
        'global' => $this->auth->hasGlobalPermission($user, 'assignments.manage'),
        'org_unit' => $assignment->scopedOrgUnit && $this->auth->hasPermissionForOrgUnit($user, 'assignments.manage', (int) $assignment->scoped_org_unit_id),
        'campaign' => $assignment->scopedCampaign && $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $assignment->scopedCampaign),
        'site' => $assignment->scopedSite && $this->auth->hasPermissionForSite($user, 'assignments.manage', $assignment->scopedSite, false),
        'team' => $assignment->scopedTeam && $this->auth->hasPermissionForTeam($user, 'assignments.manage', $assignment->scopedTeam),
        default => false,
    };
}
```

**delete() method (lines 171-179)**:
```php
public function delete(User $user, UserRoleAssignment $assignment): bool
{
    // Prevent users from revoking their own assignments
    if ($assignment->user_id === $user->id) {
        return false;
    }

    return $this->update($user, $assignment);
}
```

#### 5.2 AuthServiceProvider
**File Path:** `e:\Year 2026\Codding\UU Mission\backend\app\Providers\AuthServiceProvider.php`

Registers policies (lines 28-36):
```php
protected $policies = [
    Communication::class => CommunicationPolicy::class,
    OfficeHeld::class => OfficePolicy::class,
    Department::class => DepartmentPolicy::class,
    UserRoleAssignment::class => UserRoleAssignmentPolicy::class,
    // ... other policies
];
```

---

### 6. PERMISSIONS AND CONSTANTS

#### 6.1 Permission Codes (from DatabaseSeeder.php, lines 658-732)

Key permissions for role assignment:
- `assignments.manage` - Manage role assignments
- `assignments.view` - View role assignments
- `admin.roles.manage` - Manage roles and permissions

Full permission modules:
- **admin**: users.manage, roles.manage, org.manage
- **org**: org.view, org.manage
- **campaigns**: create, edit, view, archive
- **sites**: create, edit, view
- **assignments**: manage, view
- **reporting**: submit, review, view, view_team
- **resources**: view, create, manage, distribute, approve_distribute, publish, archive

#### 6.2 System Roles (from DatabaseSeeder.php, lines 743-846)

| Role Code | Label | Scope Type |
|-----------|-------|------------|
| `platform_admin` | Platform Administrator | global |
| `union_director` | Union Mission Director | org_unit |
| `campaign_leader` | Campaign Leader | campaign |
| `site_secretary` | Site Secretary | site |
| `site_coordinator` | Site Coordinator | site |
| `team_leader` | Team Leader | team |
| `team_secretary` | Team Secretary | team |
| `missionary` | Missionary / Field Worker | site |

---

### 7. MIDDLEWARE

The application uses standard Laravel Sanctum middleware for authentication. All protected routes are wrapped with `auth:sanctum` middleware (applied at line 102 in routes/api.php).

**No custom EnsurePermission or EnsureRole middleware exists** - authorization is handled entirely through:
1. Laravel's built-in `Authorize` middleware (via `$this->authorize()` calls in controllers)
2. The `AuthorizationService` class for complex permission checks

---

### 8. AUTHORIZATION FLOW SUMMARY

The complete authorization flow for assigning a role/site coordinator to a user:

```
1. ROUTE
   POST /api/v1/role-assignments
   Middleware: auth:sanctum

2. CONTROLLER
   UserRoleAssignmentController@store()
   Validates input (user_id, system_role_id, scope_type, scoped_*_id)
   
3. AUTHORIZATION CHECK
   $this->authorize('create', [UserRoleAssignment::class, $validated]);
   
4. POLICY
   UserRoleAssignmentPolicy@create()
   - Blocks self-assignment (escalation prevention)
   - Checks assignments.manage permission at target scope:
     * global: hasGlobalPermission(user, 'assignments.manage')
     * org_unit: hasPermissionForOrgUnit(user, 'assignments.manage', orgUnitId)
     * campaign: hasPermissionForCampaign(user, 'assignments.manage', campaign)
     * site: hasPermissionForSite(user, 'assignments.manage', site, false)
     * team: hasPermissionForTeam(user, 'assignments.manage', team)
   - Validates role-scope compatibility
   - Checks permission delegations exist for all permissions in role

5. SERVICE
   AuthorizationService methods:
   - getActiveAssignments() - loads user's active role assignments with caching
   - effectivePermissionDecision() - applies deny overrides, grants access if any assignment grants
   - permissionEffect() - checks permission overrides (grant/deny) and role's permission version

6. DATABASE
   - user_role_assignments table - stores the assignment
   - user_role_assignment_events table - audit trail
```

---

### 9. KEY FILES SUMMARY

| File Path | Purpose |
|-----------|---------|
| `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\UserRoleAssignmentController.php` | CRUD for role assignments |
| `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\AssignmentController.php` | Campaign assignments with system role creation |
| `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\SystemRoleAdministrationController.php` | RBAC admin (roles, permissions, delegations) |
| `e:\Year 2026\Codding\UU Mission\backend\app\Models\UserRoleAssignment.php` | Role assignment model |
| `e:\Year 2026\Codding\UU Mission\backend\app\Models\SystemRole.php` | System role model |
| `e:\Year 2026\Codding\UU Mission\backend\app\Models\Permission.php` | Permission model |
| `e:\Year 2026\Codding\UU Mission\backend\app\Services\AuthorizationService.php` | Core authorization logic |
| `e:\Year 2026\Codding\UU Mission\backend\app\Policies\UserRoleAssignmentPolicy.php` | Authorization policy for role assignments |
| `e:\Year 2026\Codding\UU Mission\backend\app\Providers\AuthServiceProvider.php` | Policy registration |
| `e:\Year 2026\Codding\UU Mission\backend\routes\api.php` | Route definitions |
| `e:\Year 2026\Codding\UU Mission\backend\database\seeders\DatabaseSeeder.php` | Role/permission seeding |
| `e:\Year 2026\Codding\UU Mission\backend\database\migrations\2026_10_23_200700_create_rbac_tables.php` | RBAC table schema |

---

This completes the comprehensive investigation of the role assignment and authorization system in the UU Mission Laravel backend.

Let me read the key authorization files directly.

Now let me examine the AuthorizationService resolution logic and the frontend assignment call.

Let me check whether the fix migrations have actually been run and inspect the DB state.

Found the smoking gun. Let me confirm the DB state before concluding.

Confirmed: `permission_delegations` is **empty (0 rows)**. Let me verify the rest of the chain to complete the diagnosis.

Root cause confirmed. Let me check the platform admin's own grant state, then apply the pending fix.

Root cause confirmed. Applying the pending fix migration.

Delegations are now in place (45/45). Let me verify against the project's role-assignment tests.

Fixed and verified. Here's the full diagnosis.

## Root cause of the "Unauthorized" error

It's a **403 "This action is unauthorized."** from Laravel's policy — not a 401/token problem. The platform admin *is* authenticated and *does* have `assignments.manage`; the request is being denied by one specific rule that had no data to satisfy it.

The flow when you assign Site Coordinator to Joseph from **Campaign Deployments**:

1. Frontend → `assignmentsApi.create(campaignId, { person_id, mission_site_id, system_role_id })` → `POST /api/v1/campaigns/{campaign}/assignments` ([api.ts](file://e:/Year 2026/Codding/UU Mission/frontend/src/lib/api.ts#L890-L901))
2. [AssignmentController::store()](file://e:/Year 2026/Codding/UU Mission/backend/app/Http/Controllers/AssignmentController.php#L99-L118) builds a site-scoped role payload and calls `$this->authorize('create', ...)`.
3. [UserRoleAssignmentPolicy::create()](file://e:/Year 2026/Codding/UU Mission/backend/app/Policies/UserRoleAssignmentPolicy.php#L77-L130) runs three gates:
   - ✅ `hasPermissionForSite(... 'assignments.manage' ...)` — passes (platform_admin has a global assignment, which `resolveEffectiveAssignments` treats as covering every scope).
   - ✅ role/scope compatibility — passes (`site_coordinator` is compatible with `site`).
   - ❌ **delegation gate** ([lines 127-129](file://e:/Year 2026/Codding/UU Mission/backend/app/Policies/UserRoleAssignmentPolicy.php#L127-L129)): every permission carried by the role must have an active `permission_delegation` covering the target scope. The `permission_delegations` table was **empty (0 rows)**, so this returned `false` → 403.

The fix migration was already written in the repo but never executed:

```
2026_11_06_194435_backfill_global_permission_delegations ..... Pending
```

Its own docblock describes exactly this bug: *"assigning Site Coordinator to a missionary from Campaign Deployments — failed with 403 'This action is unauthorized'."*

## What I did

Ran `php artisan migrate --force`. That migration is now **Ran**, and `permission_delegations` went from `0 → 45` (one global delegation per permission). Since a `global` delegation covers every scope ([AuthorizationService::delegationScopesFor()](file://e:/Year 2026/Codding/UU Mission/backend/app/Services/AuthorizationService.php#L697)), Site Coordinator's 28 permissions are now all delegable at site scope.

Verified with the project's own tests — including the exact scenario:
```
✓ admin can assign site coordinator at site scope via campaign deployment
Tests: 24 passed (44 assertions)
```

**Try the assignment again — Joseph should now save successfully.** No code change was needed; the app logic was correct, the reference data was missing.

## Other findings from the trace

1. **Latent privilege-escalation gap — strict comparison.** [UserRoleAssignmentPolicy::create() line 82](file://e:/Year 2026/Codding/UU Mission/backend/app/Policies/UserRoleAssignmentPolicy.php#L82) does `$validated['user_id'] === $user->id`. `$user->id` is always `int`, but on the direct `POST /role-assignments` path `$validated['user_id']` is raw request input. Laravel's `integer` rule validates but does **not** cast, so a client sending `"5"` (string) would fail the `===` and **bypass the self-assignment block**. The deployment path is safe (it passes `$person->user_id`, an int), but the direct endpoint isn't. Recommend casting: `(int) $validated['user_id'] === (int) $user->id`.

2. **Silent no-op when the person has no user account.** Both [AssignmentController::store()](file://e:/Year 2026/Codding/UU Mission/backend/app/Http/Controllers/AssignmentController.php#L101) and the update path guard the role creation with `$person->user_id`. If Joseph is a `Person` record with no linked `User`, the campaign assignment saves but **no role is assigned, and no error is returned** — the admin thinks it worked. Worth surfacing a warning.

3. **Delegations are an unguarded config dependency.** The 403 was invisible until now because nothing seeds `permission_delegations` at role/permission creation time — the repair existed only as a one-off backfill. Any future permission added without a matching delegation will silently 403 every role that carries it. Consider seeding a delegation in the same seeder/hook that creates a permission, or a health check.

4. **Misleading error surface.** A data/config problem surfaces to the user as "This action is unauthorized," which reads like an auth failure. A more specific message (e.g. "no permission delegation configured for this scope") would have made this self-diagnosing — the `setPermissionOverride` endpoint already returns exactly that message ([line 236](file://e:/Year 2026/Codding/UU Mission/backend/app/Http/Controllers/UserRoleAssignmentController.php#L236)).

Want me to apply the hardening in finding #1 (and #2) now?

---

**User**

Proceed with hardening findings #1 and #2.

### 1. Fix the self-assignment comparison

In `UserRoleAssignmentPolicy::create()`:

- Correct the strict comparison so numeric string IDs cannot bypass the self-assignment restriction.
- Cast both values to integers before comparing.
- Preserve all existing authorization and delegation checks.

Add regression tests covering:
- Integer self-assignment.
- Numeric-string self-assignment.
- Assignment to another user.
- Existing authorized administrator delegation.

### 2. Fix silent role-assignment failure

Inspect both `AssignmentController::store()` and its update path.

When an administrator requests a system role assignment for a person without a linked user account:

- Do not silently skip role creation.
- Return a clear validation error explaining that the person must have a linked user account before a system role can be assigned.
- Ensure the campaign assignment and role assignment cannot leave the administrator with a misleading success response.
- Preserve legitimate person-to-campaign assignments that do not request a system role.

Review transaction handling so failures do not leave partially completed operations.

### Constraints

- Do not weaken RBAC.
- Do not bypass AuthorizationService or UserRoleAssignmentPolicy.
- Do not change the permission delegation model.
- Do not introduce unrelated migrations.
- Preserve existing assignment lifecycle behaviour.

### Verification

Run focused regression tests, relevant RBAC and assignment tests, and report:

1. Files changed.
2. Exact defects corrected.
3. Tests added and results.
4. Confirmation that existing assignment workflows remain functional.

**Assistant**

I'll read the test file and the remaining controller code to follow existing conventions precisely.

Now I'll make the two fixes. First, the policy self-assignment guard.

Now the AssignmentController fixes — import, store guard, and update guard.

Now the update path.

Now updating tests. First, replace the outdated expectation for the person-without-user case.

Now the self-assignment regression tests (finding #1).

Now format the changed files and run the focused tests.

Pint only reformatted an already-dirty `DatabaseSeeder.php` (pre-existing uncommitted changes, formatting-only); my three target files were already clean. Now the focused regression tests.

Now the broader RBAC and assignment test suites.

Let me review the final state of the changed controller sections.

Let me prove the numeric-string test actually detects the defect (negative control): temporarily revert the cast, run the two self-assignment tests, then restore.

Negative control confirmed — without the cast, the numeric-string self-assignment returns **201 (bypass)** while the integer case still returns 403. Restoring the fix.

Both hardening items are implemented, verified, and proven by a negative control.

## 1. Files changed

| File | Change |
|---|---|
| [UserRoleAssignmentPolicy.php](file://e:/Year 2026/Codding/UU Mission/backend/app/Policies/UserRoleAssignmentPolicy.php#L81-L87) | Integer-cast self-assignment guard |
| [AssignmentController.php](file://e:/Year 2026/Codding/UU Mission/backend/app/Http/Controllers/AssignmentController.php#L98-L112) | Store-path guard + transaction ordering |
| [AssignmentController.php](file://e:/Year 2026/Codding/UU Mission/backend/app/Http/Controllers/AssignmentController.php#L177-L191) | Update-path guard |
| [AssignmentController.php](file://e:/Year 2026/Codding/UU Mission/backend/app/Http/Controllers/AssignmentController.php#L344-L364) | New `assertPersonCanHoldSystemRole()` helper |
| [OfficeAndRoleAssignmentTest.php](file://e:/Year 2026/Codding/UU Mission/backend/tests/Feature/OfficeAndRoleAssignmentTest.php) | 7 regression tests + coverage-matrix update |

`vendor/bin/pint --dirty` reported no fixes for my three files (already compliant). It did reformat an **already-dirty** `database/seeders/DatabaseSeeder.php` (pre-existing uncommitted work, formatting-only) — flagging it so it isn't mistaken for my change.

## 2. Exact defects corrected

**Defect 1 — self-assignment bypass.** `create()` compared `$validated['user_id'] === $user->id`. The right side is always `int`; the left is raw request input, and Laravel's `integer` rule validates without casting. A numeric-string ID (`"1"`) failed the strict comparison, so the escalation guard never fired. Now both sides are cast to `int`. All other gates (scope authority, role/scope compatibility, delegation) are untouched and still evaluated afterwards.

**Defect 2 — silent role-assignment failure.** `store()`/`update()` guarded role creation with `... && $person->user_id`, so a system role requested for a person with no linked user was skipped while the campaign assignment still saved → `201` with no role. Both paths now throw a `422 ValidationException` keyed to `system_role_id` with the message *"This person does not have a linked user account, so a system role cannot be assigned. Link a user account to this person first."* The frontend already surfaces this via `err.message`, so no frontend change was needed.

**Transaction handling.** The new guard is placed *before* the `DB::transaction` block in `store()`, and *before* any write in `update()` — the request aborts with nothing partially persisted. When no system role is requested, the path is unchanged, so ordinary person-to-campaign deployments still work.

## 3. Tests added and results

Added to `OfficeAndRoleAssignmentTest`:
1. `test_admin_cannot_assign_role_to_themselves_with_integer_id` → 403
2. `test_admin_cannot_assign_role_to_themselves_with_numeric_string_id` → 403
3. `test_admin_can_assign_role_to_another_user` → 201 (proves the existing campaign delegation still authorizes)
4. `test_user_without_authority_cannot_assign_role_to_another_user` → 403
5. `test_system_role_assignment_rejected_for_person_without_user` → 422 + no assignment, no role (replaces the old test that asserted the buggy `201`)
6. `test_campaign_assignment_without_system_role_succeeds_for_person_without_user` → 201
7. `test_system_role_update_rejected_for_person_without_user` → 422, assignment unchanged, no role granted

Results:
- Focused filter (`themselves|another_user|person_without_user`): **14 passed (27 assertions)**
- `OfficeAndRoleAssignmentTest`: **30 passed (60 assertions)**
- RBAC + assignment suites (`ScopedAuthorizationTest`, `FoundationalRbacTest`, `RbacApiAcceptanceVerificationTest`, `WorkspaceAccessControlTest`, `MissionaryOnboardingTest`): **81 passed (221 assertions)**

**Negative control (defect detection proven):** temporarily reverting only the cast produced
```
FAILED > admin cannot assign role to themselves with numeric string id
Expected response status code [403] but received 201.
```
The integer-ID case still returned 403. Restoring the cast → all 30 pass again.

## 4. Existing workflows confirmed functional

The passing suites cover the unchanged lifecycle end-to-end: campaign deployment with and without a system role, site-scoped assignment, role change on update (old revoked → new created), role removal via `null`, `403` refusals (`user cannot assign roles outside their authority`), duplicate prevention, and admin deployment that previously hit the delegation 403. Constraints held: no RBAC weakening (the guard only became stricter), decisions still flow exclusively through `AuthorizationService`/`UserRoleAssignmentPolicy`, the delegation model is untouched, no migrations or lifecycle changes were introduced.

---

**User**

This action is unauthorized.

thanks what 'm getting..kindly check the laravel log en see

**Assistant**

The log has nothing relevant — and that's expected: Laravel does **not** write `AuthorizationException` (403 "This action is unauthorized.") to `laravel.log`. Let me confirm that and find which request is actually 403ing.

The 403 isn't logged by design. Let me read the authorization internals that decide this specific grant.

Now let me reproduce the real decision against live data (the actual admin and DB state).

---

**User**

{message: "This action is unauthorized.",…}
exception
: 
"Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException"
file
: 
"E:\\Year 2026\\Codding\\UU Mission\\backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Exceptions\\Handler.php"
line
: 
777
message
: 
"This action is unauthorized."
trace
: 
[{,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…}, {,…},…]
0
: 
{,…}
class
: 
"Illuminate\\Foundation\\Exceptions\\Handler"
file
: 
"E:\\Year 2026\\Codding\\UU Mission\\backend\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Exceptions\\Handler.php"
function
: 
"prepareException"
line
: 
721
type
: 
"->"
1
: 
{,…}
class
: 
"Illuminate\\Foundation\\Exceptions\\Handler"
file
: 
"E:\\Year 2026\\Codding\\UU Mission\\backend\\vendor\\laravel\\framework\\src\\Illuminate\\Routing\\Pipeline.php"
function
: 
"render"
line
: 
51
type
: 
"->"
2
: 
{,…}
class
: 
"Illuminate\\Routing\\Pipeline"
file
: 
"E:\\Year 2026\\Codding\\UU Mission\\backend\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php"
function
: 
"handleException"
line
: 
182
type
: 
"->"

**Assistant**

Let me look at the policy methods the campaign page hits on load (the file you have open) and the handler line that produced that message.

The exception confirms it's a **policy/Gate denial** (`AuthorizationException` → `AccessDeniedHttpException` at Handler:777), not a token issue. Let me check the most likely live cause: a stale pinned permission version on the admin's role assignment.

Good — that rules out the stale-version theory (pinned = current = v17, and it *does* include `assignments.manage`). So the denial is elsewhere. Let me inspect the other policy in the assignment path and the route middleware.

`CampaignAssignmentPolicy` is fine. Let me enumerate the exact policy methods vs. the abilities the controller authorizes — the missing-method pattern is a known issue in this codebase.

The policy methods exist, and `roleAssignmentsApi` is only imported (unused) — so the campaign page isn't calling it. Let me check for route-level `can:` middleware and reproduce the real decisions with a throwaway boot script.

All backend gates for the assignment flow return **YES** — so the 403 is coming from a different action. Let me see exactly what the platform_admin role can do and where "Coordinator" appears in the UI.

Let me check the role/scope compatibility matrix for `site_coordinator` — the deployment form can also send `assigned_team_id`, which changes the scope.

Let me check for the known "policy omission" class of bug — a controller authorizing a policy/method that doesn't exist (which yields exactly this 403).

All policies exist. Let me check the permissions the campaign page's data loads require — the platform_admin role notably has **no `departments.*`** permission, yet the page imports `departmentsApi`.

`roleAssignmentsApi` is never actually called — so the form goes through `assignmentsApi.create`. The form can send `assigned_team_id`, which flips the scope to **team**. Let me read the exact compat/delegation checks.

Now I see the exact gate. The form sends `assigned_team_id`, which forces scope to `team` — and `site_coordinator` is **not** compatible with `team`. Let me confirm against live data.

Now let me confirm the team-scope incompatibility with the live admin.

Confirmed. Let me check how the form's scope fields are populated to pin the exact trigger.

Found it — and the log answer is simple: **403 policy denials are never written to `laravel.log`.**

## Why the log is empty (not a logging bug)

Your paste proves the origin: [Handler.php:777](file:///e:/Year%202026/Codding/UU%20Mission/backend/vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php#L771-L784) maps `AuthorizationException` (no status) → `AccessDeniedHttpException`. Laravel keeps `AuthorizationException` and `AccessDeniedHttpException` in its internal **"do not report"** list, so `report()` returns early and nothing is ever written. The log's newest entry is a test error from Nov 6 — consistent with zero authz entries.

Also note `bootstrap/app.php` only maps `AuthenticationException`/`RouteNotFoundException` → 401 "Unauthenticated." Your message is the *other* message, so it's a **Gate/policy denial**, as expected.

## Confirmed root cause — scope/role mismatch

The deployment form's **"System Role"** dropdown lists every role with `scope_type ∈ {campaign, site, team}` ([page.tsx#L945-L956](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/admin/campaigns/%5Bid%5D/page.tsx#L945-L956)), but the role's **scope is derived from the form's site/team fields**, with team winning:

```php
$scopeType = ! empty($data['assigned_team_id']) ? 'team'
           : (! empty($data['mission_site_id']) ? 'site' : 'campaign');
```
[AssignmentController.php#L366-L373](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AssignmentController.php#L366-L373)

Policy then requires the role to be compatible with that scope:
[UserRoleAssignmentPolicy.php#L118-L121](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/UserRoleAssignmentPolicy.php#L118-L121)

Live compatibility matrix: `site_coordinator => org_unit, campaign, site` — **no `team`.**

I reproduced it against your real data (admin@uumission.org, site 1, team 1 "Lira-Kato Mission Team"):

```
site (mission_site_id only)      => ALLOWED (200/201)
team (assigned_team_id set)      => DENIED (403 "This action is unauthorized.")
campaign (neither set)           => ALLOWED (200/201)
```

So: because the form had **Team = Lira-Kato Mission Team** selected ([page.tsx#L920-L929](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/admin/campaigns/%5Bid%5D/page.tsx#L920-L929)), the payload became team-scoped, and a site-scoped role can never satisfy that → 403. The delegation fix from earlier is working fine; this is a different, unrelated gate. Same trap exists in the edit modal ([line 1013-1022](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/admin/campaigns/%5Bid%5D/page.tsx#L1013-L1022)).

## Other findings

1. **Opaque failure mode.** A client-side selection mismatch surfaces as a generic 403 instead of "Site Coordinator can't be granted at team scope — pick a site or a team-compatible role."
2. **`roleAssignmentsApi` is dead code** — defined in [api.ts#L883-L908](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/lib/api.ts#L883-L908) and imported but never called ([page.tsx#L6](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/admin/campaigns/%5Bid%5D/page.tsx#L6)).
3. **Team silently overrides site** in scope derivation, so selecting both always yields team scope.
4. Team-compatible roles (`team_leader`, `team_secretary`) work; site roles (`site_coordinator`, `site_secretary`) only work with no team selected.
5. Confirmed at the data layer: `permission_delegations` = 45, admin pinned version = current = v17 (includes `assignments.manage`) — earlier fix intact.

Before I change anything, this needs a product decision:

You picked "Other" but no text came through — I don't want to guess and change the wrong behavior.

To confirm before I touch anything:

1. When the admin selects **Team = Lira-Kato Mission Team** and **System Role = Site Coordinator**, what *should* happen?
   - (a) It should save, and the person gets a **site-scoped** coordinator role (scope taken from the role, not the team) — needs `mission_site_id`.
   - (b) It should save as a **team-scoped** role — meaning `site_coordinator` should also be compatible with `team` in the matrix.
   - (c) It should be **rejected** with a clear message telling the admin the pairing is invalid.
   - (d) Something else.

2. Same question for the real-world intent: is "Site Coordinator" meant to be authority **over a site** (independent of which team they're deployed under), or authority tied to the **team** they're deployed with?

Your answer decides which layer changes: role-compatibility matrix (`role_scope_compatibilities`), scope derivation in [AssignmentController.php#L366-L373](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/AssignmentController.php#L366-L373), and/or the dropdown in [page.tsx#L945-L956](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/admin/campaigns/%5Bid%5D/page.tsx#L945-L956).

---

**User**

UU Mission — Derive System Role Scope from the Selected Role

Objective

Correct the Campaign Deployments assignment workflow so that a system role's authorization scope is determined by the selected role's own scope_type, not by the presence of a selected deployment team.

Architectural principle: Deployment assignment and system role assignment are separate concepts.

A missionary can be deployed to a campaign, site and team while holding a system role whose scope is the campaign or site rather than the team.

1. Inspect the Existing Implementation

Before changing code, inspect:

AssignmentController::store() and its update path.

UserRoleAssignmentPolicy.

AuthorizationService.

The role definitions and their scope_type values.

The Campaign Deployments frontend assignment form.

Existing assignment and role-assignment tests.

Identify exactly where the current implementation derives the system role scope from deployment data.

Do not assume the role hierarchy or scope identifiers without inspecting the existing models and relationships.

2. Backend: Role-Driven Scope Resolution

When an administrator selects a system role:

Read the selected role's authoritative scope_type.

Derive the role assignment scope from that value.

Resolve the corresponding scope identifier from the campaign deployment context.

Expected behaviour:

Role scope_type

Role assignment scope

Required identifier

campaign

campaign

campaign_id

site

site

mission_site_id

team

team

assigned_team_id

org_unit

org_unit

Valid organisational unit ID

Use the actual scope types supported by the existing application. Do not introduce speculative scope types.

Example

Campaign: Northwest Uganda Global Mission

Mission site: Lira-Kato

Deployment team: Lira-Kato Mission Team

Selected system role: Site Coordinator

Expected result:

Campaign assignment retains the selected campaign.

Deployment retains the Lira-Kato site.

Deployment retains the Lira-Kato Mission Team.

System role assignment is created at site scope.

Its scope identifier references the Lira-Kato site.

The selected team does not change the system role's scope.

For a Team Leader role, the role assignment should instead use team scope and the selected team identifier.

3. Validation and Authorization

The backend must:

Reject a missing required scope identifier with HTTP 422 and a clear validation message.

Reject a team that does not belong to the selected site/campaign.

Reject a site that does not belong to the selected campaign.

Validate organisational-unit scope using existing hierarchy and authorization rules.

Preserve UserRoleAssignmentPolicy and AuthorizationService as authoritative.

Preserve permission delegation checks.

Never silently change the selected role's scope to make the request succeed.

Never trust a client-supplied scope type when the role record already defines it.

A role/scope mismatch should produce a meaningful validation response rather than an opaque 403 when the problem is invalid request data.

Authorization denials must still return 403.

4. Frontend: Compatible Role Selection

Improve the System Role dropdown in Campaign Deployments.

Filter available system roles according to the selected role's scope_type and the available deployment context.

A site-scoped role should remain selectable when a site and team are both selected.

A team-scoped role should require a selected team.

A site-scoped role should require a selected site.

When the site changes, clear an incompatible team selection.

When the team changes, do not silently change the system role's scope.

Show a helpful explanation when no compatible roles are available.

Frontend filtering is a usability enhancement only. Backend validation remains mandatory.

5. Preserve Existing Assignment Semantics

Do not confuse:

Campaign membership.

Site deployment.

Team deployment.

System role assignment.

Functional role designation.

Functional roles such as Preacher, Translator, Bible Worker and Site Coordinator must retain their existing meaning and relationships.

Do not automatically create additional roles or grant extra permissions.

Do not change the missionary identity, affiliation or assignment lifecycle model.

6. Regression Tests

Add or update tests covering:

Site Coordinator selected with both site and team selected → role scope is site.

Team Leader selected with site and team selected → role scope is team.

Campaign-scoped role selected with site and team selected → role scope is campaign.

Missing required site → HTTP 422.

Missing required team for a team-scoped role → HTTP 422.

Team belonging to another site → rejected.

Site belonging to another campaign → rejected.

Existing authorization and delegation restrictions remain effective.

Updating an existing assignment preserves the correct scope.

Deployment team changes do not silently change an existing role assignment's scope.

Use the actual supported roles and fixtures in the repository.

7. Verification

Run:

Focused assignment tests.

UserRoleAssignmentPolicy tests.

Scoped authorization tests.

Relevant RBAC acceptance tests.

Frontend type checking.

Production build.

8. Final Report

Provide:

Root cause of the previous scope derivation.

Files changed.

How scope is now resolved.

Validation behaviour and HTTP responses.

Frontend dropdown behaviour.

Regression test results.

Any remaining limitations.

Do not modify unrelated modules or introduce new database tables/migrations unless inspection proves they are necessary. If a migration is proposed, explain why and wait for approval.

Completion criterion: Selecting a Site Coordinator while a team is selected must create a site-scoped system role assignment while preserving the team as a deployment fact.

**Assistant**

I'll work through this systematically. First, full inspection of the current implementation.

I'll continue the Section 1 inspection. Let me read the remaining key files to confirm scope_type values and test fixtures.

Now let me inspect the role definitions, the policy in full, and the existing tests.

Let me check the frontend imports, types, and the system roles ref API.

I have a complete picture now. Let me set up a task list and start implementing.

Now the backend changes. First the imports and `store()`.

Now the `update()` path — replace the validation and role-payload block.

Now the helper methods — add team/site consistency check and rewrite the scope-resolution helpers.

Now rewrite `findSystemRoleAssignment` and add `findExistingRoleAssignment`.

Backend controller is done. Now update the test fixtures and existing tests.

Now update the three existing tests that assumed campaign scope.

Now update the update/remove tests that relied on campaign scope without a site.

Backend and tests are updated. Let me run the focused backend test suite.

---

**User**

continue

**Assistant**

All 37 pass. Now let me run the scoped authorization and RBAC test suites.

All backend tests pass (37 + 91). Now the frontend dropdown.

I'll continue by completing the frontend System Role dropdown changes. Let me first verify the current state of the file.

Both dropdowns still use the old filter. Now I'll update them to use `roleOptions` and show `roleHint`.

Frontend dropdowns updated. Let me verify the surrounding setup (destructuring, imports) is consistent.

Setup is correct. Now let me run the frontend type check.

Type check passes. Now the production build.

Build succeeded. Now let me re-run the focused backend test suites to confirm the full verification set.

All verification passes. Let me confirm the final state of the backend scope resolution to accurately report.

Let me quickly confirm the `store()`/`update()` validation flow for the report.

All changes are complete and verified. Here is the final report.

## Section 8 — Final Report

### Root cause
The Campaign Deployments form always sent `assigned_team_id` when a team was part of the deployment. [AssignmentController.php](file:///e:/Year 2026/Codding/UU Mission/backend/app/Http/Controllers/AssignmentController.php) derived the system‑role assignment scope from the *deployment context* (`assigned_team_id`/`mission_site_id`) rather than from the role itself. So assigning a **site‑scoped** `site_coordinator` while a team was selected produced a `scope_type=team` payload. The `UserRoleAssignmentPolicy` then rejected it against the `role_scope_compatibilities` matrix (`site` is not compatible with `team`) → opaque **403 "This action is unauthorized."**

### Files changed
- [AssignmentController.php](file:///e:/Year 2026/Codding/UU Mission/backend/app/Http/Controllers/AssignmentController.php) — role‑driven scope resolution (store, update, revoke); team↔site validation.
- [OfficeAndRoleAssignmentTest.php](file:///e:/Year 2026/Codding/UU Mission/backend/tests/Feature/OfficeAndRoleAssignmentTest.php) — 8 new/updated regression tests.
- [page.tsx](file:///e:/Year 2026/Codding/UU Mission/frontend/src/app/admin/campaigns/[id]/page.tsx) — role dropdown filtered by deployment context + helper text.

### How scope is resolved
Single source of truth `resolveRoleScope(SystemRole $role, Campaign $campaign, array $data)`, driven purely by `$role->scope_type`:

| role `scope_type` | assignment `scope_type` | scope id source |
|---|---|---|
| `global` | `global` | none |
| `org_unit` | `org_unit` | campaign `lead_org_unit_id` |
| `campaign` | `campaign` | the campaign itself |
| `site` | `site` | `mission_site_id` |
| `team` | `team` | `assigned_team_id` |

Everything else throws `ValidationException` on `system_role_id`. `roleAssignmentPayload()` calls this for **store**, **update** (new role), and `findExistingRoleAssignment()` for **update** (old role) / **destroy**, so revoking never throws during cleanup. The policy and `AuthorizationService` are unchanged — the policy still performs the final delegation gate.

### Validation behavior / HTTP responses
- Site‑scoped role without a site → **422**, `mission_site_id`: *"The selected role 'X' is site-scoped and requires a mission site to be selected before it can be assigned."*
- Team‑scoped role without a team → **422**, `assigned_team_id` (same message shape).
- Team belonging to another site → **422** (`validateTeamBelongsToSite`).
- Site from another campaign → **422** (`validateSiteScope`).
- Person with no linked user + system role → **422**.
- Genuine authorization denial (no delegation / self‑assignment) → still **403**.

### Frontend dropdown behavior
`roleOptions` offers `campaign` roles always, `site` roles only when a site is selected, `team` roles only when a team is selected; the currently chosen role is always kept visible so the selection is never silently dropped. Changing the site clears a team bound to a different site. `roleHint` explains the state. This is a usability aid only — the backend remains the mandatory boundary.

### Test results
- `OfficeAndRoleAssignmentTest` + `ScopedAuthorizationTest` + `FoundationalRbacTest` + `RbacApiAcceptanceVerificationTest` → **91 passed (206 assertions)**.
- `tsc --noEmit` → **exit 0**.
- `npm run build` → **compiled successfully**, 36 pages generated.

### Completion criterion
Selecting **Site Coordinator** while a team is selected now creates a **site‑scoped** `user_role_assignment` (with `scoped_team_id` null) while the `campaign_assignments` row still preserves the team as a deployment fact — asserted by `test_site_coordinator_deployed_on_a_team_keeps_site_scope`.

### Remaining limitations
- Only one system role per deployment (matches existing schema).
- `global`/`org_unit` roles require an identifier the deployment form does not collect for org units beyond the campaign's lead unit; they are filtered out of the dropdown but still resolvable via API.
- No new migrations were needed; no unrelated modules changed.

---

**User**

ok..now

You are not authorised to register outreach contacts for this campaign/site.

I have that assigned to xem zee as site coordinator..but he cant do that...can check why then also..i have realised somthing..,
the sitem coordinator should be able to add teams to his site right..

which other duties or should this site coordinator have??

**Assistant**

Let me investigate the outreach contact authorization and the site coordinator's permission set.

Now let me check the `site_coordinator` role's seeded permissions.

Found the likely cause. Let me verify the full permission catalogue, especially team management permissions.

Let me check what permission team creation/management requires.

Let me examine how `resolveEffectiveAssignments` treats site-scoped assignments for campaign checks.

Let me check `TeamController::store` to understand team-site validation and how a site-scoped coordinator would fit.

Let me check the demo seeding that assigns the Site Coordinator role, since it may reveal the scope inconsistency.

I've traced both issues. Here's what's happening.

## Issue 1 — Why Xem Zee gets 403 on outreach contacts

The check is at [OutreachContactController.php](file:///e:/Year 2026/Codding/UU Mission/backend/app/Http/Controllers/OutreachContactController.php#L125-L129):

```php
if (! $this->auth->hasPermissionForSite($request->user(), 'outreach-contacts.create', $site)) {
    return response()->json(['message' => 'You are not authorised to register outreach contacts for this campaign/site.'], 403);
}
```

There are **two** possible causes, and they can stack:

1. **The `Site Coordinator` role itself has no outreach permission.** In [DatabaseSeeder.php](file:///e:/Year 2026/Codding/UU Mission/backend/database/seeders/DatabaseSeeder.php#L794-L808) the `site_coordinator` permission set is:
   `sites.view, sites.edit, assignments.view/manage, activities.*, attendance.*, report.*, outcomes.*, people.*, resources.*, communications.*` — **no `outreach-contacts.*`**.
   For contrast, [missionary](file:///e:/Year 2026/Codding/UU Mission/backend/database/seeders/DatabaseSeeder.php#L837-L847) and [team_secretary](file:///e:/Year 2026/Codding/UU Mission/backend/database/seeders/DatabaseSeeder.php#L823-L836) **do** have `outreach-contacts.create/edit/view`. So this is an omission for the site's top officer — even a correctly site-scoped assignment is denied.

2. **Only the *functional* role was selected.** In the deployment form the "Functional Role" dropdown ([FunctionalRoleType `site_coordinator`](file:///e:/Year 2026/Codding/UU Mission/backend/database/seeders/DatabaseSeeder.php#L563)) is separate from the "System Role" dropdown. If Xem Zee only has the **functional** role and the **System Role was left empty**, they hold *zero* system permissions → same 403.

Quick way to tell which: if it's cause 2, Xem Zee would also be denied activities, attendance, reports — everything. If it's cause 1, they can do all site duties *except* outreach.

Also worth flagging: demo data grants the `site_coordinator` (a `site`-scoped role) at **`org_unit`** scope in [DatabaseSeeder.php#L630-L643](file:///e:/Year 2026/Codding/UU Mission/backend/database/seeders/DatabaseSeeder.php#L630-L643) — the same "scope from context, not role" class of problem we just fixed.

## Issue 2 — Should the Site Coordinator add teams to their site? Yes — but it's blocked

[TeamPolicy.php](file:///e:/Year 2026/Codding/UU Mission/backend/app/Policies/TeamPolicy.php#L30-L46) requires `assignments.manage` **at campaign scope**:

```php
public function create(User $user, Campaign $campaign): bool {
    return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $campaign);
}
```

But [resolveEffectiveAssignments()](file:///e:/Year 2026/Codding/UU Mission/backend/app/Services/AuthorizationService.php#L866-L867) only lets a `site`-scoped assignment match a campaign **when a site is supplied**. So a site-scoped coordinator's `assignments.manage` never satisfies a campaign-level team check → 403, even though they legitimately hold the permission. Your instinct is correct: managing teams at their own site is a core coordinator duty, and the policy resolves at the wrong scope.

## Issue 3 — Proposed duty set for Site Coordinator

The coordinator is the top officer *at site level*. Current set is already comprehensive; the gaps vs. their remit are:

| Duty | Permission | Status |
|---|---|---|
| Register/edit/view outreach contacts | `outreach-contacts.create/edit/view` | **missing — add** |
| Create/update teams & manage members at own site | `assignments.manage` (already held) | **blocked by scope bug — fix** |
| Site details, activities, attendance verify, report review, outcomes + follow-up, people, resources, communications | already granted | ok |
| Create sites / edit campaigns | `sites.create`, `campaigns.*` | leave at campaign level |

I'd keep `sites.create` (site creation) and campaign editing with the Campaign Leader — a coordinator governs an existing site, not the campaign structure.

Before I touch anything, one decision:

All four changes approved. Let me first look at how site-scoped creation is already handled elsewhere (activities), so I mirror the existing pattern rather than inventing one.

Let me see how `ActivityController` passes the resolved site into the policy.

Now I have the established pattern. Let me read the full `TeamPolicy`, `TeamController::resolveSite`/`validateSiteScope`, and the `resolveSite` helper in `ActivityController`.

Now let me verify `validateSiteScope` usage and read the demo seeding section for the coordinator.

Let me find the demo `MissionSite` fixtures so the coordinator seed can be site-scoped.

The demo seeder has no Campaign/MissionSite fixtures. Let me check the schema to see what a minimal site fixture requires.

Let me check whether any tests depend on the demo seeder before I extend it.

I have the full picture. Let me implement. First, the `TeamPolicy` fix mirroring the established `ActivityPolicy` pattern.

Now update `TeamController::store` to resolve the site before authorizing.

Now add the outreach permissions to `site_secretary` and `site_coordinator`.

Now the demo seed fix. Let me check the unique constraints on `user_role_assignments`.

Now let me add regression tests. Let me find the existing team test file.

Let me verify the outreach route and engagement stage requirements for tests.

Now let me add regression tests. First the import and fixture.

Let me verify the team members route and the Team factory.

Now insert the new test section.

I accidentally removed a brace. Let me fix it.

Let me verify the team member role values and then run the tests.

Authorization is fixed — the test reached the insert. But it exposed a second, pre-existing bug: outreach creation crashes because `from_stage_id` is `NOT NULL` yet the controller inserts `null` for the first stage history. Let me inspect.

I'll continue by reading the migration that likely made `from_stage_id` NOT NULL, plus the controller insert site.

Confirmed: the conversion migration [2026_11_01_120300...php](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/migrations/2026_11_01_120300_convert_engagement_stage_history_stages_to_foreign_keys.php#L63-L67) forces `from_stage_id` NOT NULL — but the original schema had `from_stage` nullable, because the first (initial) transition has no previous stage. Let me check the model and the test.

Now I have full clarity. Note the inconsistency: the FK was created with `->nullOnDelete()` (line 13) — which *anticipates* nulls — yet line 65 forces NOT NULL. The original `from_stage` enum was nullable. So NOT NULL is a schema regression that also contradicts its own FK rule. Let me check where `from_stage_id` is used.

Let me read the `OutreachContact` model's stage-transition helper to understand the intended contract.

Root cause fully established. Here's the full picture before I proceed.

**Why Xem Zee (Site Coordinator) couldn't register outreach contacts** — two independent causes, both now fixed:

1. **Missing permissions on the role.** The seeded `site_coordinator` role had no `outreach-contacts.*` permission codes, so the authorization engine correctly denied him. Added `outreach-contacts.create / .edit / .view` (also to `site_secretary`, as approved).

2. **Site vs. deployment-context scope (same class of bug you just fixed for role assignment).** The check is `hasPermissionForSite($user, 'outreach-contacts.create', $site)` at [OutreachContactController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OutreachContactController.php#L126-L130). That is correct — it *does* require a real site. But the coordinator must hold the role **site-scoped to that exact site**. If the assignment was created org_unit-scoped (as the old demo seed did), it never matches. Fixed by seeding the coordinator site-scoped.

**Site coordinator adding teams to their own site** — yes, and this was broken for the same "wrong scope" reason: [TeamPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/TeamPolicy.php) required `assignments.manage` at *campaign* scope, which a site-scoped assignment can never satisfy. Rewritten to resolve the site (mirroring `ActivityPolicy`), and [TeamController::store](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/TeamController.php#L57-L84) now resolves the site *before* authorizing.

**One new blocker surfaced** (not one of the four approved fixes — needs your call because it's a schema change):

The outreach test now passes *authorization* and reaches the DB insert, then fails:
```
SQLSTATE[23502]: null value in column "from_stage_id" ... violates not-null constraint
```

`engagement_stage_histories.from_stage_id` is NOT NULL, but this is a **schema regression**. Evidence:
- The original column `from_stage` was **nullable** ([create migration](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/migrations/2026_11_01_100400_create_engagement_stage_history_table.php#L14)).
- The conversion migration created the FK with `->nullOnDelete()` — which *requires* the column to be nullable — then immediately forced `->nullable(false)` ([lines 13 & 65](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/migrations/2026_11_01_120300_convert_engagement_stage_history_stages_to_foreign_keys.php#L63-L67)). The FK rule and the NOT NULL contradict each other.
- Semantically, the **first** stage record has no "from" stage, so `from_stage_id = null` is correct (exactly what [OutreachContactController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OutreachContactController.php#L183-L192) sends).

Getting your decision, since it's a schema change.

Approved. Let me check the Laravel version (affects how `->change()` works) and the migration style.

Laravel 13 + doctrine/dbal — `->change()` is safe, but for Postgres I'll use an explicit `ALTER COLUMN ... DROP NOT NULL` to guarantee the existing FK constraint is preserved.

Now run the focused test suite (tests use RefreshDatabase, so the new migration applies automatically).

All 52 pass. Now let me confirm no regressions in the related suites.

All green. Let me run the full backend suite since the schema migration affects every test using `RefreshDatabase`.

While the full suite runs, let me pull the current role permission definitions to answer your "which other duties" question precisely.

Now let me check the full suite result.

Test DB (`uu_mission_test`) is separate from dev, so I can safely apply the migration to the dev database now.

Migration applied to dev. Now checking the full suite.

---

**User**

skip don't run the test just make sure.., the code is applied and correct.

**Assistant**

Stopping the test run.

Now let me verify each change is applied and correct.

Now verifying the test file additions.

Let me verify the prior task's scope-derivation fix is still in place (the file you have open).

All changes verified in place. Let me do a lint (syntax) pass on the five changed files — this is not running the test suite.

Done. Everything is applied and verified by inspection + syntax lint (no test run, per your instruction).

## 1. Why Xem Zee couldn't register outreach contacts

Two independent causes, both now fixed:

- **The role had no outreach permission at all.** `site_coordinator` was seeded without any `outreach-contacts.*` code, so [OutreachContactController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/OutreachContactController.php#L126-L130) denied him correctly. Added `outreach-contacts.create/.edit/.view` to [site_coordinator](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/seeders/DatabaseSeeder.php#L823-L838) and [site_secretary](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/seeders/DatabaseSeeder.php#L808-L821).
- **The check is site-scoped** — `hasPermissionForSite(..., $site)`. It needs the role held **site-scoped at that exact site**. The old demo seed created the coordinator assignment **org_unit-scoped**, which can never match a site check. Fixed to site-scoped in [DatabaseSeeder.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/seeders/DatabaseSeeder.php#L657-L672), plus a demo campaign + site so it has something to scope to ([Section 6b](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/seeders/DatabaseSeeder.php#L472-L495)).

## 2. Site coordinator adding teams to their own site

Yes — and it was broken for the same "wrong scope" reason. [TeamPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/TeamPolicy.php) demanded `assignments.manage` at **campaign** scope, which a site-scoped assignment never satisfies. Rewritten to resolve the site (mirroring `ActivityPolicy`), and [TeamController::store](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/TeamController.php#L57-L84) now resolves the site **before** authorizing. Result: coordinator can create/edit teams **and** add/remove members at their own site; denied at a sibling site or campaign-wide.

## 3. Schema bug that surfaced

`engagement_stage_histories.from_stage_id` was NOT NULL, so the initial history row (`from_stage_id = null`) was rejected. That was a regression: the original `from_stage` was nullable and the FK itself was declared `nullOnDelete`. Added [2026_11_08_000000_make_from_stage_id_nullable...php](file:///e:/Year%202026/Codding/UU%20Mission/backend/database/migrations/2026_11_08_000000_make_from_stage_id_nullable_on_engagement_stage_histories.php) and applied it to the dev DB (`DONE`).

## Important — existing data needs one action

The seeder is self-healing for the **role** (it republishes a new permission version), but each `UserRoleAssignment` **pins the permission version it was created with**. Xem Zee's existing assignment still pins the old version and may still be wrong-scoped. After deploying:

1. `php artisan db:seed` (publishes the new Site Coordinator permissions + baseline delegations)
2. Re-assign him from Campaign Deployments (unassign + reassign) so his assignment pins the new version **at site scope**.

## 4. Site Coordinator — recommended duty envelope

Current set already covers the full site-operational envelope. Proposed (and what to deliberately withhold):

| Area | Permissions | Rationale |
|---|---|---|
| Site oversight | `sites.view`, `sites.edit` | Runs their own site day-to-day |
| Teams & deployment | `assignments.view`, `assignments.manage` | Creates teams and manages members at own site |
| Activities | `activities.view/create/update/delete` | Schedules and runs site activities |
| Attendance | `attendance.submit`, `attendance.verify`, `attendance.view` | Takes roll-call **and** verifies registers |
| Reporting | `report.submit`, `report.review`, `report.view` | Prepares site reports; reviews own-site submissions |
| Outcomes | `outcomes.record/view/manage_followup` | Records conversions; assigns follow-up |
| People | `people.create/edit/view` | Registers neutral identities |
| Outreach | `outreach-contacts.create/edit/view` | Registers/edits outreach contacts |
| Resources | `resources.view/create/manage/distribute/approve_distribute` | Contributes + distributes |
| Communications | `communications.view/publish/acknowledge` | Receives, publishes site-level, acknowledges |

**Deliberately withheld (Campaign Leader / Union Director level):** `sites.create`, `campaigns.create/edit/archive`, `resources.publish`, `resources.archive`, `communications.revoke`.

All of the above already matches the seeded role — no further permission changes needed unless you want to change a boundary.

---

**User**

are all controllers en UI to date with this?

**Assistant**

Good question — let me audit both layers rather than assume. I'll check every permission code the backend actually enforces, every policy the controllers invoke, and how the UI gates actions.

Substantial audit. Let me examine the remaining backend hits plus the frontend gating in parallel.

Now the key policies and the Team UI.

Now the Team and Assignment UI to see if it matches the backend scope model.

Let me check how the frontend guards the `/admin/*` area and which nav items a coordinator would see.

Audit complete. Here's the honest verdict.

## Backend — fully up to date ✅

Every controller authorizes through a policy, and every policy resolves the **real resource scope** through `AuthorizationService` (no bare `hasPermission` on a flat list):

| Area | Scope-aware? | Evidence |
|---|---|---|
| Teams | ✅ (fixed) | [TeamPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/TeamPolicy.php#L52-L90), [TeamController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/TeamController.php#L71-L76) |
| Outreach contacts | ✅ | [OutreachContactPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/OutreachContactPolicy.php#L32-L64) |
| People | ✅ | [PersonPolicy.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Policies/PersonPolicy.php#L50-L96) |
| Sites | ✅ | [MissionSiteController::index](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/MissionSiteController.php#L38-L58) narrows to authorised scopes |
| Activities / Attendance / Reports / Outcomes / Communications / Assignments | ✅ | all use `hasPermissionForSite/Campaign/Team` |

Also verified: every permission code referenced in `app/` exists in the seeder's list (no orphans), and `canViewCampaign` resolves non-empty for a site-scoped user — so a coordinator can load their campaign context.

## UI — mostly, but 4 real gaps ⚠️

The **missionary workspace** navigation is correctly permission-filtered (`canAccessModule` in [permissions.ts](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/lib/permissions.ts#L69-L109)), and the deployment role dropdown was fixed in the prior task ([role filter](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/admin/campaigns/%5Bid%5D/page.tsx#L785-L800)). But:

1. **Admin nav is not permission-filtered.** [AppShell.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/AppShell.tsx#L13-L18) is a static list and [admin/layout.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/admin/layout.tsx#L5-L11) only requires authentication. Any authenticated user sees Campaigns / People / Sites / Teams / Reports / Outcomes. Backend 403s the data, so they get empty/error pages instead of a cleanly hidden module.

2. **"Create Team" is not gated, and defaults to campaign-wide.** [campaigns/[id]/page.tsx#L610](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/admin/campaigns/%5Bid%5D/page.tsx#L610) shows the button to everyone, and the site selector defaults to [`"Campaign-wide team"`](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/admin/campaigns/%5Bid%5D/page.tsx#L661-L673). A site coordinator who leaves it empty now gets a **403** — the exact class of failure you just fixed. It should default to their own site and hide the campaign-wide option.

3. **`assignments.manage` / `campaigns.view` / `people.create` are never used as UI gates** anywhere in the frontend (they appear only in the permission list). So no control is currently driven by them.

4. **The missionary outreach form depends on a deployment.** [people/page.tsx#L188-L189](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/app/%28missionary%29/people/page.tsx#L188-L189) builds `campaign_id`/`mission_site_id` from the user's *deployment assignment*. A coordinator who holds a system role but **no campaign deployment** would send neither → **422**, not the 403 he saw. The admin modal ([AddOutreachContactModal.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/admin/AddOutreachContactModal.tsx#L32-L52)) picks campaign/site explicitly and is the safer path for coordinators.

Gaps 1–2 are the ones that directly affect a site coordinator's experience; 3–4 are polish/robustness.

---

**User**

# UU Mission — Role-Based Operational Workspaces: Architecture Assessment and Implementation Proposal

## Objective

We need to evolve UU Mission from its current missionary workspace + general admin interface into a platform with dedicated operational workspaces.

The recent inspection established that:
- The missionary workspace has permission-filtered navigation.
- The `/admin` shell currently uses a largely static navigation list.
- Site Coordinators have operational permissions but are presented with an experience that is still too similar to the missionary experience.
- Some administrative controls are not permission-gated in the frontend.
- Backend policies generally enforce actual resource scopes through AuthorizationService.

We do not want to solve this by merely hiding menu items.

We want to establish the right workspace architecture for the long-term product.

**This is an inspection and proposal task only. Do not implement anything yet.**

## 1. Proposed product direction

Evaluate a workspace model with four principal operational experiences:

### A. Missionary Workspace

Purpose: Help an individual perform their mission work.

Potential capabilities:
- Personal mission overview.
- Assigned campaigns, sites and teams.
- Personal activities and priorities.
- Fieldwork and attendance where authorised.
- Outreach contact capture and personal follow-ups.
- Resources and learning.
- Personal reports and mission progress.
- Profile and service history.

### B. Site Operations Workspace

Primary role: Site Coordinator.

Purpose: Coordinate and monitor the operation of an assigned mission site.

Potential capabilities:
- Site overview and operational indicators.
- Personnel and team management.
- Team deployment within authorised scope.
- Site activities and scheduling.
- Attendance monitoring.
- Outreach and follow-up oversight.
- Team report review.
- Site outcomes and mission progress.
- Site logistics and readiness.

This workspace should not duplicate Team Leader or Site Secretary responsibilities. It should coordinate and monitor their work.

### C. Campaign Management Workspace

Purpose: Coordinate multiple sites and manage campaign-level operations.

Potential users include Campaign Coordinators and other appropriately authorised campaign-level officers.

Potential capabilities:
- Campaign overview.
- Site creation and coordination.
- Site leadership and personnel deployment.
- Campaign activities and priorities.
- Cross-site reporting and outcomes.
- Resource distribution and oversight.
- Campaign-level communications.
- Operational performance monitoring.

### D. Platform Administration Workspace

Purpose: Administer the platform itself.

Potential capabilities:
- Accounts and people administration.
- Organisational hierarchy.
- Role and permission configuration.
- System reference data.
- Platform-level configuration.
- Administrative oversight.

Do not assume that every current `/admin` page belongs exclusively to this workspace. Determine the proper operational ownership of each module.

## 2. Inspect the current frontend architecture

Inspect:
- `AppShell.tsx`.
- `admin/layout.tsx`.
- Missionary workspace layouts and navigation.
- `permissions.ts` and `canAccessModule`.
- Authentication and user context.
- Role and effective-permission loading.
- Existing campaign and site routes.
- Dashboard implementations.
- Route guards and frontend API access.

Determine:
1. How the current workspace is selected.
2. Whether a user can hold multiple operational roles.
3. Whether the frontend can distinguish campaign, site and team scopes.
4. Whether the current architecture supports multiple workspaces without duplicating layouts.
5. Which routes currently mix platform administration with field operations.

## 3. Inspect backend authorization and role assignment

Inspect:
- AuthorizationService.
- UserRoleAssignment and permission versioning.
- Role definitions and scope types.
- Existing permission-loading APIs.
- Relevant policies and controllers.
- Campaign, site and team relationships.
- Assignment lifecycle and revocation.

Establish how workspace access should be derived.

Important architectural principles:

- A Person remains one permanent identity.
- A person can have several authorised roles.
- Each role assignment has its own scope.
- Workspace access must be based on effective, active, authorised role assignments.
- A Site Coordinator should only access the Site Operations Workspace for their assigned site.
- A person serving in multiple sites may have multiple site contexts.
- Deployment assignment facts must remain separate from system-role authority.
- Expired or revoked assignments must remove operational workspace access without deleting history.
- A missionary account must not automatically gain administrative access simply because it belongs to an organisation.

## 4. Investigate workspace selection and switching

Recommend how users should enter and move between workspaces.

Consider:
- Default landing workspace.
- Workspace switcher when a person has multiple roles.
- Site selector when a person coordinates multiple sites.
- Campaign selector for campaign-level officers.
- Remembering the last selected workspace where appropriate.
- Clear indication of the active role and scope.
- Behaviour when a role expires or is revoked.
- Behaviour when a user has only missionary access.

Avoid unnecessary selection screens for people with only one available workspace.

Do not create separate accounts or duplicate user identities for different roles.

## 5. Map existing modules to their proper workspaces

Inspect every existing operational module, including:

- Campaigns.
- Sites.
- Teams.
- People.
- Outreach Contacts.
- Activities.
- Attendance.
- Field Reports.
- Outcomes.
- Resources.
- Communication Centre.
- Learning Centre.
- Profile and missionary history.
- Logistics and Site Readiness.
- Role and permission administration.

For each module, identify:
- Its primary workspace.
- Which roles should access it.
- Whether access is read, create, edit, review, approve or manage.
- The relevant authorisation scope.
- Existing frontend and backend implementation.
- Whether the functionality should be shared between workspaces.

A module may be accessible from multiple workspaces without creating duplicate functionality.

## 6. Resolve the current four UI gaps in the new architecture

Evaluate the following findings:

1. Admin navigation is static and not permission-filtered.
2. Create Team is not properly gated and defaults to campaign-wide.
3. Some permission codes are not used as frontend action gates.
4. The missionary outreach form currently depends on deployment context.

Do not simply patch them independently. Explain how each should be handled within the proposed workspace architecture.

In particular:
- A Site Coordinator should access team management through Site Operations, not through an unnecessarily broad campaign administration interface.
- Campaign-wide team creation must not appear as an option to a site-scoped coordinator.
- Backend authorization must remain authoritative.
- Outreach contact creation should use a valid authorised operational context, not arbitrary frontend IDs.
- Shared controls should use consistent permission-aware visibility.

## 7. Recommend the workspace UX

Propose:
- Navigation structure for each workspace.
- Dashboard purpose and information hierarchy.
- Relevant quick actions.
- Workspace switching behaviour.
- Mobile navigation considerations.
- Shared components and pages.
- Empty states and error states.

The interfaces should be operationally meaningful, not just different names applied to the same dashboard.

## 8. Implementation roadmap

Recommend a phased transition that reuses existing modules.

Consider:

**Phase 1 — Workspace foundation**
- Role/scope-aware workspace resolution.
- Route protection.
- Navigation and workspace switching.
- Clear distinction between operational and platform administration.

**Phase 2 — Site Operations**
- Dedicated Site Coordinator dashboard.
- Site-scoped teams and personnel.
- Activities, attendance, outreach and reporting oversight.

**Phase 3 — Campaign Management**
- Dedicated campaign-level coordination experience.
- Multi-site overview and operational supervision.

**Phase 4 — Platform Administration**
- Separate platform administration experience.
- Administrative tools and configuration.

Adjust the phases based on actual implementation and dependencies.

For every phase, specify existing code to reuse, required backend changes, frontend changes, tests and risks.

## 9. Required report

Return:

1. Current workspace architecture.
2. Current role and scope resolution mechanism.
3. Current module-to-route map.
4. Proposed workspace architecture.
5. Workspace access and switching rules.
6. Module ownership and access matrix.
7. Site Coordinator workspace proposal.
8. Campaign Management workspace proposal.
9. Platform Administration workspace proposal.
10. Resolution of the four known UI gaps.
11. Phased implementation plan.
12. Exact files, APIs, policies and tests affected.
13. Architectural decisions requiring approval.

## Constraints

- Inspection and recommendations only.
- No code changes, migrations, permission changes or seed changes.
- Do not rebuild completed modules.
- Do not duplicate existing functionality into separate applications.
- Do not weaken backend authorization.
- Do not derive operational access from organisational affiliation alone.
- Do not assume one role per person.
- Keep the initial implementation proportionate to UU Mission's current stage.

The goal is one UU Mission platform with distinct, coherent workspaces for the different responsibilities people are authorised to perform.

**Assistant**

This is a large inspection-and-proposal task with no implementation. Let me gather the full architecture in parallel before writing the report.

I'll conduct a comprehensive audit of the Laravel backend. Let me start by exploring the project structure and then systematically investigate each area.

I'll conduct a comprehensive audit of the frontend codebase. Let me start by gathering all the necessary information in parallel.

Now I have collected most of the data. Let me get a few more pieces to complete the report.

Based on my comprehensive audit of the frontend codebase, I present the following detailed report:

---

# Frontend Architecture Audit Report

## 1. Route Map

### Group: `(missionary)` — Missionary Workspace Routes
**Base:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\(missionary)\`

| Route | File Path |
|-------|-----------|
| `/dashboard` | `(missionary)\dashboard\page.tsx` |
| `/mission` | `(missionary)\mission\page.tsx` |
| `/team` | `(missionary)\team\page.tsx` |
| `/fieldwork` | `(missionary)\fieldwork\page.tsx` |
| `/people` | `(missionary)\people\page.tsx` |
| `/outcomes` | `(missionary)\outcomes\page.tsx` |
| `/reports` | `(missionary)\reports\page.tsx` |
| `/resources` | `(missionary)\resources\page.tsx` |
| `/resources/my-resources` | `(missionary)\resources\my-resources\page.tsx` |
| `/resources/store` | `(missionary)\resources\store\page.tsx` |
| `/resources/reviews` | `(missionary)\resources\reviews\page.tsx` |
| `/resources/reader/[id]` | `(missionary)\resources\reader\[id]\page.tsx` |
| `/communication` | `(missionary)\communication\page.tsx` |
| `/media` | `(missionary)\media\page.tsx` |
| `/profile` | `(missionary)\profile\page.tsx` |
| `/briefings` | `(missionary)\briefings\page.tsx` |
| `/translator` | `(missionary)\translator\page.tsx` |

### Group: `admin` — Administrative Routes
**Base:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\admin\`

| Route | File Path |
|-------|-----------|
| `/admin` (redirects to /admin/campaigns) | `admin\page.tsx` |
| `/admin/campaigns` | `admin\campaigns\page.tsx` |
| `/admin/campaigns/[id]` | `admin\campaigns\[id]\page.tsx` |
| `/admin/campaigns/[id]/sites/[siteId]` | `admin\campaigns\[id]\sites\[siteId]\page.tsx` |
| `/admin/campaigns/new` | `admin\campaigns\new\page.tsx` |
| `/admin/people` | `admin\people\page.tsx` |
| `/admin/sites` | `admin\sites\page.tsx` |
| `/admin/teams` | `admin\teams\page.tsx` |
| `/admin/reports` | `admin\reports\page.tsx` |
| `/admin/outcomes` | `admin\outcomes\page.tsx` |

### Group: Auth/Public Routes (No Layout Group)
**Base:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\`

| Route | File Path |
|-------|-----------|
| `/` (root landing) | `page.tsx` |
| `/login` | `login\page.tsx` |
| `/register` | `register\page.tsx` |
| `/signup` | `signup\page.tsx` |
| `/verify-email` | `verify-email\page.tsx` |
| `/forgot-password` | `forgot-password\page.tsx` |
| `/reset-password` | `reset-password\page.tsx` |
| `/accept-invite` | `accept-invite\page.tsx` |
| `/welcome` | `welcome\page.tsx` |
| `/offline` | `offline\page.tsx` |

---

## 2. Layouts and Guards

### Root Layout
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\layout.tsx` (lines 1-35)

- Standard Next.js root layout
- No auth enforcement at this level
- Includes `ServiceWorkerRegistration` and `NetworkStatus` components

### Admin Layout
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\admin\layout.tsx` (lines 1-11)

```typescript
'use client';
import { AuthGuard } from '@/components/AuthGuard';
import { AppShell } from '@/components/AppShell';

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthGuard>
      <AppShell>{children}</AppShell>
    </AuthGuard>
  );
}
```

### Missionary Layout
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\(missionary)\layout.tsx` (lines 1-21)

```typescript
'use client';
import { useAuthStore } from '@/store/auth';
import { AuthGuard } from '@/components/AuthGuard';
import { MissionaryShell } from '@/components/MissionaryShell';

export default function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  const { user } = useAuthStore();
  const hasMissionaryProfile = user?.person?.missionary_profile !== null;

  return (
    <AuthGuard>
      {hasMissionaryProfile ? (
        <MissionaryShell showHeader={false}>{children}</MissionaryShell>
      ) : (
        <div className="min-h-screen bg-surface">
          {children}
        </div>
      )}
    </AuthGuard>
  );
}
```

### AuthGuard Component
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\components\AuthGuard.tsx`

**Authentication Enforcement:**
1. **Token Storage:** Token stored in `localStorage` under key `uu_token` (line 36-37)
2. **Initialization:** Calls `initialize()` from auth store on mount (line 12)
3. **Unauthenticated Redirect:** If `initialized && !user`, redirects to `/login` (lines 15-19)
4. **Role-Based Routing:** 
   - Admins (user.primary_role === 'admin') accessing non-admin routes are redirected to `/admin/campaigns` (lines 29-30)
   - Non-admins accessing admin routes are redirected to `/dashboard` (lines 34-35)

**No Explicit Permission Checks in AuthGuard:** The guard only checks authentication status and `primary_role` ('admin' vs others), not the granular `permissions` array.

---

## 3. Navigation Definitions

### Admin AppShell Navigation (Sidebar)
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\components\AppShell.tsx` (lines 12-19)

```typescript
const NAV = [
  { href: '/admin/campaigns',  label: 'Campaigns',      icon: Globe },
  { href: '/admin/people',     label: 'People',         icon: Users },
  { href: '/admin/sites',      label: 'Mission Sites',  icon: MapPin },
  { href: '/admin/teams',      label: 'Teams',          icon: Users },
  { href: '/admin/reports',    label: 'Field Reports',  icon: FileText },
  { href: '/admin/outcomes',   label: 'Outcomes',       icon: Target },
];
```

**No Permission Filtering:** The admin navigation is static and shown to all authenticated admin users. No permission-based filtering is applied.

### Missionary MissionaryShell Navigation
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\components\MissionaryShell.tsx` (lines 31-46)

```typescript
const MAIN_NAV: NavItem[] = [
  { href: '/dashboard', label: 'Home', icon: Home },
  { href: '/translator', label: 'Translate', icon: Languages, featured: true },
  { href: '/mission', label: 'Mission', icon: Globe, module: 'mission' },
  { href: '/team', label: 'Team', icon: Users, module: 'team' },
];

const MORE_NAV: NavItem[] = [
  { href: '/fieldwork', label: 'Fieldwork', icon: MapPin, module: 'fieldwork' },
  { href: '/reports', label: 'Reports', icon: ClipboardList, module: 'reports' },
  { href: '/outcomes', label: 'Mission Outcomes', icon: Target, module: 'outcomes' },
  { href: '/people', label: 'Outreach Contacts', icon: Users2, module: 'people' },
  { href: '/media', label: 'Media', icon: Image },
  { href: '/resources', label: 'Resources', icon: FileText },
  { href: '/communication', label: 'Communication', icon: MessageSquare },
];
```

**Permission-Based Filtering:** Navigation items are filtered using `canAccessModule()` (lines 70-74):

```typescript
const canSee = (item: NavItem) =>
  user ? canAccessModule(user, item.module ?? null) : true;

const mainNav = MAIN_NAV.filter(canSee);
const moreNav = MORE_NAV.filter(canSee);
```

Items without a `module` property (Home, Translate, Media, Resources, Communication) are always visible. Items with a `module` property are gated.

---

## 4. Auth + User Context

### Auth Store
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\store\auth.ts`

**State Shape:**
```typescript
interface AuthState {
  user: AuthUser | null;
  token: string | null;
  loading: boolean;
  initialized: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  initialize: () => Promise<void>;
  refreshUser: () => Promise<void>;
}
```

**AuthUser Type:**
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\lib\api.ts` (lines 231-261)

```typescript
export interface AuthUser {
  id: number;
  email: string;
  status: string;
  timezone: string;
  last_login_at: string;
  primary_role: 'admin' | 'missionary' | null;
  roles: Array<{
    code: string;
    label: string;
    scope_type: string;
  }>;
  /**
   * Effective permission codes — the union across all active scopes.
   * For UI affordances ONLY (navigation, control visibility). The backend
   * always re-authorises access to a specific resource against its real scope.
   */
  permissions: string[];
  /** Active role assignments with their scopes. */
  scopes: AuthScope[];
  person: {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    gender: string;
    phone: string | null;
    photo_path: string | null;
    missionary_profile: any | null;
  } | null;
}
```

**AuthScope Type:**
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\lib\api.ts` (lines 219-229)

```typescript
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
```

---

## 5. permissions.ts Summary

**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\lib\permissions.ts`

### WorkspaceModuleKey
```typescript
export type WorkspaceModuleKey = 'mission' | 'team' | 'fieldwork' | 'people' | 'outcomes' | 'reports';
```

### OPERATIONAL_PERMISSIONS (lines 29-61)
A readonly array of 31 permission codes including:
- `org.view`, `campaigns.view`, `campaigns.edit`
- `sites.view`, `sites.edit`
- `assignments.view`, `assignments.manage`
- `activities.view`, `activities.create`, `activities.update`, `activities.delete`
- `attendance.submit`, `attendance.verify`, `attendance.view`
- `report.submit`, `report.view`, `report.view_team`, `report.review`
- `outcomes.record`, `outcomes.view`, `outcomes.manage_followup`
- `people.view`, `people.create`, `people.edit`
- `outreach-contacts.view`, `outreach-contacts.create`, `outreach-contacts.edit`

### MODULE_PERMISSIONS (lines 69-92)
```typescript
const MODULE_PERMISSIONS: Record<WorkspaceModuleKey, readonly string[]> = {
  mission: OPERATIONAL_PERMISSIONS,
  team: OPERATIONAL_PERMISSIONS,
  fieldwork: ['activities.view', 'activities.create', 'activities.update', 'activities.delete', 'attendance.view', 'attendance.submit'],
  people: ['people.view', 'outreach-contacts.view'],
  reports: ['report.view', 'report.view_team', 'report.submit', 'report.review'],
  outcomes: ['outcomes.record'],
};
```

### Key Functions

**moduleForPath** (lines 100-109):
```typescript
export function moduleForPath(pathname: string): WorkspaceModuleKey | null {
  if (pathname === '/mission' || pathname.startsWith('/mission/')) return 'mission';
  if (pathname === '/team' || pathname.startsWith('/team/')) return 'team';
  if (pathname === '/fieldwork' || pathname.startsWith('/fieldwork/')) return 'fieldwork';
  if (pathname === '/people' || pathname.startsWith('/people/')) return 'people';
  if (pathname === '/outcomes' || pathname.startsWith('/outcomes/')) return 'outcomes';
  if (pathname === '/reports' || pathname.startsWith('/reports/')) return 'reports';
  return null;
}
```

**hasPermission** (lines 112-114):
```typescript
export function hasPermission(user: AuthUser | null | undefined, code: string): boolean {
  return user?.permissions?.includes(code) ?? false;
}
```

**hasAnyPermission** (lines 117-126):
```typescript
export function hasAnyPermission(
  user: AuthUser | null | undefined,
  codes: readonly string[]
): boolean {
  if (!user) return false;
  const granted = user.permissions ?? [];
  return codes.some((code) => granted.includes(code));
}
```

**canAccessModule** (lines 134-142):
```typescript
export function canAccessModule(
  user: AuthUser | null | undefined,
  module: WorkspaceModuleKey | null
): boolean {
  if (module === null) return true;
  if (!user) return false;
  return hasAnyPermission(user, MODULE_PERMISSIONS[module]);
}
```

---

## 6. Workspace/Role-Aware APIs

**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\lib\api.ts`

### authApi (lines 263-270)
```typescript
export const authApi = {
  login: (email: string, password: string, device_name?: string) =>
    api.post<{ token: string; expires_at: string; user: AuthUser }>(
      '/auth/login', { email, password, device_name }
    ),
  me:     () => api.get<{ user: AuthUser }>('/auth/me'),
  logout: () => api.post('/auth/logout'),
};
```

### workspaceApi (lines 1455-1476)
```typescript
export const workspaceApi = {
  myAssignment: () => api.get<MyAssignmentResponse>('/workspace/my-assignment'),
  myTeam: () => api.get<MyTeamResponse>('/workspace/my-team'),
  mySites: () => api.get<MySitesResponse>('/workspace/my-sites'),
  myProfile: () => api.get<WorkspaceProfile>('/workspace/my-profile'),
  updateMyProfile: (data: { bio?: string; training_notes?: string; home_org_unit_id?: number }) =>
    api.patch('/workspace/my-profile', data),
  updateMyPerson: (data: { first_name?: string; last_name?: string; phone?: string; date_of_birth?: string; gender?: string }) =>
    api.patch('/workspace/my-person', data),
  addMyLanguage: (data: { language_name: string; proficiency_level: string; is_native?: boolean; notes?: string }) =>
    api.post('/workspace/my-languages', data),
  removeMyLanguage: (languageCode: string) =>
    api.delete(`/workspace/my-languages/${languageCode}`),
  addMySkill: (data: { skill_category: string; skill_name: string; proficiency_level: string; years_experience?: number; notes?: string }) =>
    api.post('/workspace/my-skills', data),
  removeMySkill: (skillId: number) =>
    api.delete(`/workspace/my-skills/${skillId}`),
};
```

### refApi (lines 1003-1021)
```typescript
export const refApi = {
  outcomeTypes:         () => api.get<OutcomeType[]>('/ref/outcome-types'),
  programmeTypes:       () => api.get<{ code: string; label: string }[]>('/ref/programme-types'),
  programmes:           () => api.get<{ id: number; name: string }[]>('/ref/programmes'),
  teamTypes:            () => api.get<{ id: number; code: string; label: string }[]>('/ref/team-types'),
  activityTypes:        () => api.get<{ id: number; code: string; label: string; category: string }[]>('/ref/activity-types'),
  functionalRoleTypes:  () => api.get<{ id: number; code: string; label: string }[]>('/ref/functional-role-types'),
  systemRoles:          () => api.get<{ id: number; code: string; label: string; scope_type: string }[]>('/ref/system-roles'),
  officeTypes:           () => api.get<{ id: number; code: string; label: string; org_unit_type_id: number | null }[]>('/ref/office-types'),
  orgUnits:             () => api.get<{ id: number; name: string; type: { label: string } }[]>('/ref/org-units'),
  persons:              (search?: string) => { ... },
  encounterMethods:     () => api.get<EncounterMethod[]>('/ref/encounter-methods'),
  engagementStages:     () => api.get<{ id: number; code: string; label: string; color_key: string; sort_order: number; is_active: boolean }[]>('/ref/engagement-stages'),
};
```

### assignmentsApi (lines 515-525)
```typescript
export const assignmentsApi = {
  list:   (campaignId: number, params?: { site_id?: number; status?: string }) => {
    const q = buildQuery(params);
    return api.get<Assignment[]>(`/campaigns/${campaignId}/assignments${q}`);
  },
  create: (campaignId: number, data: { person_id: number; mission_site_id?: number; assigned_team_id?: number; functional_role_type_id?: number; system_role_id?: number; expected_arrival?: string; notes?: string }) =>
    api.post<Assignment>(`/campaigns/${campaignId}/assignments`, data),
  update: (campaignId: number, assignmentId: number, data: Partial<Assignment>) =>
    api.patch<Assignment>(`/campaigns/${campaignId}/assignments/${assignmentId}`, data),
};
```

### campaignsApi (lines 314-340)
```typescript
export const campaignsApi = {
  list:       (params?: Record<string, unknown>) => {
    const q = buildQuery(params);
    return api.get<{ data: Campaign[]; total: number }>(`/campaigns${q}`);
  },
  get:        (id: number) => api.get<Campaign>(`/campaigns/${id}`),
  stats:      (id: number) => api.get<CampaignStats>(`/campaigns/${id}/stats`),
  create:     (data: Partial<Campaign> & { programme_id: number; lead_org_unit_id: number }) =>
    api.post<Campaign>('/campaigns', data),
  update:     (id: number, data: Partial<Campaign>) =>
    api.patch<Campaign>(`/campaigns/${id}`, data),
  transition: (id: number, to: string) =>
    api.post<{ campaign: Campaign }>(`/campaigns/${id}/transition`, { to }),
  authorisedUsers: (campaignId: number, params?: { site_id?: number }) => { ... },
  objectives: { ... },
};
```

### sitesApi (lines 362-371)
```typescript
export const sitesApi = {
  list:   (campaignId: number) =>
    api.get<MissionSite[]>(`/campaigns/${campaignId}/sites`),
  get:    (campaignId: number, siteId: number) =>
    api.get<MissionSite>(`/campaigns/${campaignId}/sites/${siteId}`),
  create: (campaignId: number, data: Partial<MissionSite>) =>
    api.post<MissionSite>(`/campaigns/${campaignId}/sites`, data),
  update: (campaignId: number, siteId: number, data: Partial<MissionSite>) =>
    api.patch<MissionSite>(`/campaigns/${campaignId}/sites/${siteId}`, data),
};
```

### teamsApi (lines 393-406)
```typescript
export const teamsApi = {
  list:       (campaignId: number, params?: { site_id?: number; campaign_wide?: boolean }) => {
    const q = buildQuery(params);
    return api.get<Team[]>(`/campaigns/${campaignId}/teams${q}`);
  },
  get:        (campaignId: number, teamId: number) =>
    api.get<Team & { members: TeamMember[] }>(`/campaigns/${campaignId}/teams/${teamId}`),
  create:     (campaignId: number, data: { name: string; description?: string; mission_site_id?: number; team_type_id?: number; origin_org_unit_id?: number }) =>
    api.post<Team>(`/campaigns/${campaignId}/teams`, data),
  addMember:  (campaignId: number, teamId: number, data: { person_id: number; role_in_team: string }) =>
    api.post<TeamMember>(`/campaigns/${campaignId}/teams/${teamId}/members`, data),
  removeMember: (campaignId: number, teamId: number, memberId: number) =>
    api.delete(`/campaigns/${campaignId}/teams/${teamId}/members/${memberId}`),
};
```

---

## 7. Dashboards

### Missionary Dashboard
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\(missionary)\dashboard\page.tsx`

**Purpose:** Main landing page for authenticated missionaries with missionary profiles.

**Data Fetched:**
- `workspaceApi.myAssignment()` — user's current mission assignment
- `resourcesApi.progress.history()` — reading history
- `resourcesApi.saved.list()` — saved resources count
- `resourcesApi.distributionRequests()` — user's distribution requests
- `communicationsApi.requiringAcknowledgement()` — communications requiring acknowledgment
- `communicationsApi.unreadCount()` — unread communication count

**Permission Gates Used:**
- `canAccessModule()` for module visibility in quick actions
- `hasPermission(user, 'resources.create')` for resource creation buttons

### Admin Campaign Dashboard
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\admin\campaigns\[id]\page.tsx`

**Purpose:** Campaign management interface for administrators.

**Tabs Available:** overview, sites, activities, teams, assignments, reports, outcomes, departments

**Data Fetched:**
- `campaignsApi.get(cid)` — campaign details
- `campaignsApi.stats(cid)` — campaign statistics
- `sitesApi.list()`, `teamsApi.list()`, `assignmentsApi.list()`, etc. (per tab)

### Mission Page (Placeholder)
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\(missionary)\mission\page.tsx`

**Purpose:** Mission overview (currently shows placeholder statistics with 0 values).

---

## 8. Permission Usage as UI Gates

### Summary of Permission Checks Found

| Permission Code | File(s) | Context |
|-----------------|---------|---------|
| `outcomes.record` | `outcomes/page.tsx:80` | Shows/hides recording interface |
| `activities.update` | `fieldwork/page.tsx:183` | Can manage activity objectives |
| `activities.create` | `fieldwork/page.tsx:186` | Can create unplanned activities |
| `report.view`, `report.view_team` | `reports/page.tsx:529` | Can preview reports |
| `report.submit` | `reports/page.tsx:530` | Can submit reports |
| `resources.create` | `dashboard/page.tsx:398`, `resources/page.tsx:19`, `resources/my-resources/page.tsx:154` | Can create resources |
| `resources.approve_distribute` | `resources/page.tsx:20`, `resources/reviews/page.tsx:49` | Can review/approve resources |
| `resources.distribute` | `resources/my-resources/page.tsx:155` | Can distribute resources |
| `communications.publish` | `communication/page.tsx:38` | Can publish communications |

### MODULE_PERMISSIONS Mapping (from permissions.ts lines 69-92)

| Module | Required Permissions |
|--------|---------------------|
| `mission` | OPERATIONAL_PERMISSIONS (all 31) |
| `team` | OPERATIONAL_PERMISSIONS (all 31) |
| `fieldwork` | `activities.view`, `activities.create`, `activities.update`, `activities.delete`, `attendance.view`, `attendance.submit` |
| `people` | `people.view`, `outreach-contacts.view` |
| `reports` | `report.view`, `report.view_team`, `report.submit`, `report.review` |
| `outcomes` | `outcomes.record` |

### Permission Codes NEVER Used as UI Gates

From the backend list provided, the following permission codes are **never** referenced in `hasPermission()`, `hasAnyPermission()`, or `canAccessModule()` calls in the frontend:

**Admin/Organization Permissions:**
- `admin.users.manage`
- `admin.roles.manage`
- `admin.org.manage`
- `org.view` (listed in OPERATIONAL_PERMISSIONS but never checked directly)
- `org.manage`

**Campaign/Site/Assignment Management:**
- `campaigns.create`
- `campaigns.edit` (listed in OPERATIONAL_PERMISSIONS but never checked directly)
- `campaigns.view` (listed in OPERATIONAL_PERMISSIONS but never checked directly)
- `campaigns.archive`
- `sites.create`
- `sites.edit` (listed in OPERATIONAL_PERMISSIONS but never checked directly)
- `sites.view` (listed in OPERATIONAL_PERMISSIONS but never checked directly)
- `assignments.manage` (listed in OPERATIONAL_PERMISSIONS but never checked directly)
- `assignments.view` (listed in OPERATIONAL_PERMISSIONS but never checked directly)

**Activities (most never checked directly):**
- `activities.view`, `activities.create`, `activities.update`, `activities.delete` (only checked collectively via canAccessModule for fieldwork)
- `attendance.submit`, `attendance.verify`, `attendance.view` (only attendance.view and attendance.submit used in fieldwork module check)

**Reports:**
- `report.submit`, `report.review` (only report.view and report.view_team checked directly in reports page)
- `report.view_team` (checked but report.review is not)

**Outcomes:**
- `outcomes.view` (only outcomes.record is checked)
- `outcomes.manage_followup` (never checked)

**People:**
- `people.create`, `people.edit` (only people.view is checked via MODULE_PERMISSIONS)
- `outreach-contacts.view` (checked), `outreach-contacts.create`, `outreach-contacts.edit` (never checked)

**Resources:**
- `resources.view`, `resources.create` (checked), `resources.manage`, `resources.distribute` (checked), `resources.approve_distribute` (checked), `resources.publish`, `resources.archive` (never checked)

**Communications:**
- `communications.view`, `communications.publish` (checked), `communications.acknowledge`, `communications.revoke` (never checked)

---

## 9. Where Team Creation and Outreach Creation Happen in the UI

### Team Creation

**Admin Interface - Campaign Detail Page**
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\admin\campaigns\[id]\page.tsx` (lines 585-590)

```typescript
const newTeam = await teamsApi.create(campaignId, {
  name: data.name,
  description: data.description || undefined,
  mission_site_id: data.mission_site_id ? Number(data.mission_site_id) : undefined,
  team_type_id: data.team_type_id ? Number(data.team_type_id) : undefined,
  origin_org_unit_id: data.origin_org_unit_id ? Number(data.origin_org_unit_id) : undefined,
});
```

**Form Fields for Team Creation:**
- Name (required)
- Description (optional)
- Mission Site (dropdown - optional)
- Team Type (dropdown - optional)
- Origin Organization Unit (dropdown - optional)

**Team Member Addition**
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\lib\api.ts` (lines 402-403)
```typescript
addMember:  (campaignId: number, teamId: number, data: { person_id: number; role_in_team: string }) =>
  api.post<TeamMember>(`/campaigns/${campaignId}/teams/${teamId}/members`, data),
```

### Outreach Contact Creation

**Admin Interface - AddOutreachContactModal**
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\components\admin\AddOutreachContactModal.tsx` (lines 70-78)

```typescript
await outreachContactsApi.create({
  first_name: firstName.trim(),
  last_name: lastName.trim(),
  phone: phone.trim() || undefined,
  notes: notes.trim() || undefined,
  engagement_stage: stage,
  campaign_id: Number(campaignId),
  mission_site_id: Number(siteId),
});
```

**Form Fields:**
- First Name (required)
- Last Name (required)
- Phone (optional)
- Campaign (dropdown - required) - **Permission-filtered:** Lists campaigns from `campaignsApi.list()`
- Mission Site (dropdown - required) - **Permission-filtered:** Populated based on selected campaign via `sitesApi.list(campaignId)`
- Engagement Stage (dropdown - required) - Loaded from `refApi.engagementStages()`
- Notes (optional)

**Missionary Interface - People Page**
**File:** `e:\Year 2026\Codding\UU Mission\frontend\src\app\(missionary)\people\page.tsx` (lines 191-200)

```typescript
const contact = await outreachContactsApi.create(contactData);
```

The form includes assignment-scoped campaign/site pre-selection based on `workspaceApi.myAssignment()`.

---

## 10. Shared vs Duplicated Components

### Shared Component Library
**Location:** `e:\Year 2026\Codding\UU Mission\frontend\src\components/ui.tsx`

**Exported Components:**
- `cn()` — tailwind class merging utility
- `Skeleton` — loading placeholder
- `StatusBadge` — status indicator with predefined mappings
- `EmptyState` — empty state display
- `ErrorBanner` — error message display
- `Spinner` — loading spinner
- `Modal` — modal dialog (uses React portal)
- `StatCard` — statistics display card

**Status Mappings in StatusBadge (lines 18-30):**
```typescript
const STATUS_MAP: Record<string, string> = {
  planning:  'badge-planning',
  active:    'badge-active',
  completed: 'badge-completed',
  archived:  'badge-archived',
  draft:     'badge-draft',
  submitted: 'badge-submitted',
  reviewed:  'badge-reviewed',
  approved:  'badge-approved',
  confirmed: 'badge-confirmed',
  arrived:   'badge-arrived',
  pending:   'badge-pending',
};
```

### Resource-Related Shared Components
**Location:** `e:\Year 2026\Codding\UU Mission\frontend\src\components\resources\`

**Components:**
- `AudienceScopePicker.tsx`
- `AuthenticatedThumbnail.tsx`
- `EditResourceDialog.tsx`
- `ResourceStatusBadge.tsx`
- `ReviewRequestDetail.tsx`
- `SubmitDistributionDialog.tsx`

### Admin-Specific Components
**Location:** `e:\Year 2026\Codding\UU Mission\frontend\src\components\admin\`

**Components:**
- `AddOutreachContactModal.tsx`

### No Dedicated `ui/` Directory
The project does NOT have a `frontend/src/components/ui/` directory. All shared UI components are in `frontend/src/components/ui.tsx` (single file) and `frontend/src/components/resources/` for resource-specific components.

### Component Sharing Patterns

**Shared Across Workspaces:**
- `StatusBadge`, `EmptyState`, `ErrorBanner`, `Skeleton`, `Spinner`, `Modal`, `StatCard` from `ui.tsx`
- `MissionaryShell` and `AppShell` for layout

**Workspace-Specific:**
- Admin pages use `AppShell` with static NAV
- Missionary pages use `MissionaryShell` with permission-filtered navigation

**Duplication Analysis:**
- No significant component duplication found
- Both admin and missionary interfaces share the `Modal` component from `ui.tsx`
- Form patterns are similar but tailored to each context

---

## Summary

This audit reveals a well-structured Next.js frontend with:

1. **Clear Route Segregation:** Admin routes under `/admin/*`, missionary workspace under `/*` (with `(missionary)` group), and public auth routes.

2. **Permission-Based Navigation:** Missionary workspace uses `canAccessModule()` to filter navigation based on user's effective permissions. Admin navigation is static.

3. **Token-Based Auth:** Bearer token stored in `localStorage`, refreshed via `authApi.me()` on initialization.

4. **Scoped APIs:** Workspace APIs (`workspaceApi`) provide context-aware data (myAssignment, myTeam, mySites) scoped to the authenticated user's authorized contexts.

5. **UI Permission Gates:** Direct `hasPermission()` calls for specific actions (create resources, record outcomes, manage objectives, etc.).

6. **Unused Permissions:** Many backend permission codes are never checked in the frontend UI, relying instead on the backend to enforce authorization on API calls.

Now let me read a few more policy files to complete the authorization picture:

Now I have gathered comprehensive information about the authorization, role-assignment, and routing architecture. Let me compile the audit report.

---

# UU Mission Platform — Backend Authorization & Architecture Audit Report

**Audit Date:** 2026-11-08  
**Project Path:** `e:\Year 2026\Codding\UU Mission\backend`  
**Framework:** Laravel (PHP 8.3) with Sanctum authentication

---

## Executive Summary

This audit documents a sophisticated, multi-layered authorization architecture featuring:

1. **Scope-based RBAC** with 6 scope types (global, org_unit, campaign, site, team)
2. **Immutable permission versioning** for role definitions
3. **Permission delegation chains** for administrative authority
4. **Hierarchical organization structure** (Union → Conference → District → Zone → Local Church)
5. **Dual assignment models**: Campaign deployments vs. system role assignments

---

## 1. ROUTE MAP

**File:** `e:\Year 2026\Codding\UU Mission\backend\routes\api.php`

### 1.1 Middleware Groups

| Group | Routes | Description |
|-------|--------|-------------|
| **Public** | `/auth/login`, `/auth/register`, `/auth/verify-email`, `/auth/forgot-password`, `/auth/reset-password`, `/invitations/{token}/accept` | No authentication required |
| **Authenticated** (auth:sanctum) | All other routes | Requires valid Sanctum token |

### 1.2 Route Inventory by Resource

#### Authentication (`/auth/*`)
| Method | URL | Controller@Method | Middleware |
|--------|-----|-------------------|------------|
| POST | `/auth/login` | AuthController@login | public |
| POST | `/auth/register` | AuthController@register | public |
| GET/POST | `/auth/verify-email` | AuthController@verifyEmail | public |
| POST | `/auth/forgot-password` | AuthController@forgotPassword | public |
| POST | `/auth/reset-password` | AuthController@resetPassword | public |
| GET | `/auth/me` | AuthController@me | auth:sanctum |
| POST | `/auth/logout` | AuthController@logout | auth:sanctum |
| POST | `/auth/logout-all` | AuthController@logoutAll | auth:sanctum |
| POST | `/auth/change-password` | AuthController@changePassword | auth:sanctum |

#### Campaigns (`/campaigns/*`)
| Method | URL | Controller@Method | Notes |
|--------|-----|-------------------|-------|
| GET | `/campaigns` | CampaignController@index | List all |
| POST | `/campaigns` | CampaignController@store | Create new |
| GET | `/campaigns/{campaign}` | CampaignController@show | Single campaign |
| PATCH | `/campaigns/{campaign}` | CampaignController@update | Update campaign |
| GET | `/campaigns/{campaign}/stats` | CampaignController@stats | Campaign statistics |
| POST | `/campaigns/{campaign}/transition` | CampaignController@transition | Lifecycle transition |
| POST | `/campaigns/{campaign}/media` | CampaignController@attachMedia | Attach media |
| DELETE | `/campaigns/{campaign}/media/{media_uuid}` | CampaignController@detachMedia | Detach media |

#### Objectives (`/campaigns/{campaign}/objectives/*`)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/objectives` | CampaignObjectiveController@index |
| POST | `/campaigns/{campaign}/objectives` | CampaignObjectiveController@store |
| PATCH | `/campaigns/{campaign}/objectives/{objective}` | CampaignObjectiveController@update |
| DELETE | `/campaigns/{campaign}/objectives/{objective}` | CampaignObjectiveController@destroy |

#### Sites (`/campaigns/{campaign}/sites/*`)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/sites` | MissionSiteController@index |
| POST | `/campaigns/{campaign}/sites` | MissionSiteController@store |
| GET | `/campaigns/{campaign}/sites/{site}` | MissionSiteController@show |
| PATCH | `/campaigns/{campaign}/sites/{site}` | MissionSiteController@update |
| DELETE | `/campaigns/{campaign}/sites/{site}` | MissionSiteController@destroy |
| GET | `/campaigns/{campaign}/sites/{site}/readiness` | MissionSiteController@readiness |
| GET | `/campaigns/{campaign}/sites/{site}/logistics` | SiteLogisticsController@show |
| PUT | `/campaigns/{campaign}/sites/{site}/logistics` | SiteLogisticsController@upsert |
| POST | `/campaigns/{campaign}/sites/{site}/media` | MissionSiteController@attachMedia |
| DELETE | `/campaigns/{campaign}/sites/{site}/media/{media_uuid}` | MissionSiteController@detachMedia |

#### Teams (`/campaigns/{campaign}/teams/*`)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/teams` | TeamController@index |
| POST | `/campaigns/{campaign}/teams` | TeamController@store |
| GET | `/campaigns/{campaign}/teams/{team}` | TeamController@show |
| PATCH | `/campaigns/{campaign}/teams/{team}` | TeamController@update |
| POST | `/campaigns/{campaign}/teams/{team}/members` | TeamController@addMember |
| DELETE | `/campaigns/{campaign}/teams/{team}/members/{member}` | TeamController@removeMember |
| POST | `/campaigns/{campaign}/teams/{team}/media` | TeamController@attachMedia |
| DELETE | `/campaigns/{campaign}/teams/{team}/media/{media_uuid}` | TeamController@detachMedia |

#### Activities (`/campaigns/{campaign}/activities/*`)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/activities` | ActivityController@index |
| POST | `/campaigns/{campaign}/activities` | ActivityController@store |
| GET | `/campaigns/{campaign}/activities/{activity}` | ActivityController@show |
| PATCH | `/campaigns/{campaign}/activities/{activity}` | ActivityController@update |
| DELETE | `/campaigns/{campaign}/activities/{activity}` | ActivityController@destroy |
| POST | `/campaigns/{campaign}/activities/{activity}/media` | ActivityController@attachMedia |
| DELETE | `/campaigns/{campaign}/activities/{activity}/media/{media_uuid}` | ActivityController@detachMedia |

#### Activity Participants (`/campaigns/{campaign}/activities/{activity}/participants/*`)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/activities/{activity}/participants` | ActivityParticipantController@index |
| POST | `/campaigns/{campaign}/activities/{activity}/participants` | ActivityParticipantController@store |
| DELETE | `/campaigns/{campaign}/activities/{activity}/participants/{participant}` | ActivityParticipantController@destroy |

#### Activity Objectives (`/campaigns/{campaign}/activities/{activity}/objectives/*`)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/activities/{activity}/objectives` | ActivityObjectiveController@index |
| POST | `/campaigns/{campaign}/activities/{activity}/objectives` | ActivityObjectiveController@store |
| PATCH | `/campaigns/{campaign}/activities/{activity}/objectives/{objective}` | ActivityObjectiveController@update |
| DELETE | `/campaigns/{campaign}/activities/{activity}/objectives/{objective}` | ActivityObjectiveController@destroy |

#### Assignments (`/campaigns/{campaign}/assignments/*`)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/assignments` | AssignmentController@index |
| POST | `/campaigns/{campaign}/assignments` | AssignmentController@store |
| GET | `/campaigns/{campaign}/assignments/{assignment}` | AssignmentController@show |
| PATCH | `/campaigns/{campaign}/assignments/{assignment}` | AssignmentController@update |
| DELETE | `/campaigns/{campaign}/assignments/{assignment}` | AssignmentController@destroy |

#### Role Assignments (`/campaigns/{campaign}/role-assignments/*`, etc.)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/role-assignments` | UserRoleAssignmentController@indexForCampaign |
| GET | `/campaigns/{campaign}/sites/{site}/role-assignments` | UserRoleAssignmentController@indexForSite |
| GET | `/campaigns/{campaign}/teams/{team}/role-assignments` | UserRoleAssignmentController@indexForTeam |
| GET | `/org-units/{orgUnit}/role-assignments` | UserRoleAssignmentController@indexForOrgUnit |

#### Field Reports (`/campaigns/{campaign}/reports/*`)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/reports` | FieldReportController@index |
| POST | `/campaigns/{campaign}/reports` | FieldReportController@store |
| GET | `/campaigns/{campaign}/reports/{report}` | FieldReportController@show |
| PATCH | `/campaigns/{campaign}/reports/{report}` | FieldReportController@update |
| GET | `/campaigns/{campaign}/reports/{report}/preparation` | FieldReportController@preparation |
| POST | `/campaigns/{campaign}/reports/{report}/prepare` | FieldReportController@prepare |
| POST | `/campaigns/{campaign}/reports/{report}/submit` | FieldReportController@submit |
| POST | `/campaigns/{campaign}/reports/{report}/review` | FieldReportController@review |
| POST | `/campaigns/{campaign}/reports/{report}/forward` | FieldReportController@forward |
| POST | `/campaigns/{campaign}/reports/{report}/consolidate` | FieldReportController@consolidate |
| POST | `/campaigns/{campaign}/reports/{report}/recover` | FieldReportController@recover |
| GET | `/campaigns/{campaign}/reports/{report}/events` | FieldReportController@events |

#### Mission Outcomes (`/campaigns/{campaign}/outcomes/*`)
| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/campaigns/{campaign}/outcomes` | MissionOutcomeController@index |
| POST | `/campaigns/{campaign}/outcomes` | MissionOutcomeController@store |
| GET | `/campaigns/{campaign}/outcomes/{outcome}` | MissionOutcomeController@show |
| POST | `/campaigns/{campaign}/outcomes/{outcome}/follow-up` | MissionOutcomeController@assignFollowUp |
| POST | `/campaigns/{campaign}/outcomes/{outcome}/complete-follow-up` | MissionOutcomeController@completeFollowUp |

### 1.3 Workspace Routes (`/workspace/*`)

| Method | URL | Controller@Method | Description |
|--------|-----|-------------------|-------------|
| GET | `/workspace/my-assignment` | WorkspaceController@myAssignment | Current deployment |
| GET | `/workspace/my-team` | WorkspaceController@myTeam | Team details |
| GET | `/workspace/my-sites` | WorkspaceController@mySites | Authorized sites |
| GET | `/workspace/my-profile` | WorkspaceController@myProfile | Full profile |
| PATCH | `/workspace/my-profile` | WorkspaceController@updateMyProfile | Update profile |
| PATCH | `/workspace/my-person` | WorkspaceController@updateMyPerson | Update person |
| POST | `/workspace/my-languages` | WorkspaceController@addMyLanguage | Add language |
| DELETE | `/workspace/my-languages/{languageCode}` | WorkspaceController@removeMyLanguage | Remove language |
| POST | `/workspace/my-skills` | WorkspaceController@addMySkill | Add skill |
| DELETE | `/workspace/my-skills/{skillId}` | WorkspaceController@removeMySkill | Remove skill |
| POST | `/workspace/my-mission-experience` | WorkspaceController@addMyMissionExperience | Add experience |
| DELETE | `/workspace/my-mission-experience/{experienceId}` | WorkspaceController@removeMyMissionExperience | Remove experience |
| POST | `/workspace/my-training` | WorkspaceController@addMyTraining | Add training |
| DELETE | `/workspace/my-training/{trainingId}` | WorkspaceController@removeMyTraining | Remove training |
| POST | `/workspace/my-profile-photo` | WorkspaceController@uploadProfilePhoto | Upload photo |
| POST | `/workspace/my-cover-photo` | WorkspaceController@uploadCoverPhoto | Upload cover |

### 1.4 Communications (`/communications/*`)

| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/communications` | CommunicationController@index |
| GET | `/communications/unread-count` | CommunicationController@unreadCount |
| GET | `/communications/requiring-acknowledgement` | CommunicationController@requiringAcknowledgement |
| POST | `/communications` | CommunicationController@store |
| GET | `/communications/{communication}` | CommunicationController@show |
| PATCH | `/communications/{communication}` | CommunicationController@update |
| DELETE | `/communications/{communication}` | CommunicationController@destroy |
| POST | `/communications/{communication}/read` | CommunicationController@read |
| POST | `/communications/{communication}/acknowledge` | CommunicationController@acknowledge |

### 1.5 Global Role Assignments (`/role-assignments/*`)

| Method | URL | Controller@Method |
|--------|-----|-------------------|
| POST | `/role-assignments` | UserRoleAssignmentController@store |
| GET | `/role-assignments/{assignment}` | UserRoleAssignmentController@show |
| PATCH | `/role-assignments/{assignment}` | UserRoleAssignmentController@update |
| DELETE | `/role-assignments/{assignment}` | UserRoleAssignmentController@destroy |
| PUT | `/role-assignments/{assignment}/permission-overrides` | UserRoleAssignmentController@setPermissionOverride |
| DELETE | `/role-assignments/{assignment}/permission-overrides/{permission}` | UserRoleAssignmentController@removePermissionOverride |

### 1.6 RBAC Administration (`/rbac/*`)

| Method | URL | Controller@Method |
|--------|-----|-------------------|
| GET | `/rbac/roles` | SystemRoleAdministrationController@index |
| GET | `/rbac/roles/{role}/permission-versions` | SystemRoleAdministrationController@versions |
| GET | `/rbac/permission-delegations` | SystemRoleAdministrationController@delegations |
| PUT | `/rbac/roles/{role}/permission-versions` | SystemRoleAdministrationController@publishPermissions |
| PUT | `/rbac/roles/{role}/compatible-scopes` | SystemRoleAdministrationController@setCompatibleScopes |
| POST | `/rbac/permission-delegations` | SystemRoleAdministrationController@createDelegation |
| DELETE | `/rbac/permission-delegations/{delegation}` | SystemRoleAdministrationController@revokeDelegation |

### 1.7 Reference Data (`/ref/*`)

| Method | URL | Data Source |
|--------|-----|-------------|
| GET | `/ref/skill-categories` | SkillCategory model |
| GET | `/ref/languages` | Language model |
| GET | `/ref/programme-types` | ProgrammeType model |
| GET | `/ref/org-unit-types` | OrgUnitType model |
| GET | `/ref/team-types` | TeamType model |
| GET | `/ref/activity-types` | ActivityType model |
| GET | `/ref/functional-role-types` | FunctionalRoleType model |
| GET | `/ref/system-roles` | SystemRole model |
| GET | `/ref/office-types` | OfficeType model |
| GET | `/ref/org-units` | OrgUnit model |
| GET | `/ref/outcome-types` | OutcomeType model |
| GET | `/ref/programmes` | Programme model |
| GET | `/ref/persons` | Person model (searchable) |
| GET | `/ref/encounter-methods` | EncounterMethod model |
| GET | `/ref/engagement-stages` | EngagementStage model |

### 1.8 Additional Operational Routes

| Category | Routes |
|----------|--------|
| **People** | `/people/*` (CRUD + photo upload) |
| **Org Memberships** | `/org-memberships/*` (approval workflow) |
| **Org Hierarchy** | `/org-hierarchy`, `/org-units/search`, `/org-units/{orgUnit}` |
| **Onboarding** | `/onboarding/register`, `/onboarding/people/{person}` |
| **Outreach Encounters** | `/outreach-encounters/*` (CRUD + complete/reschedule) |
| **Outreach Contacts** | `/outreach-contacts/*` (CRUD) |
| **Baptism Records** | `/baptism-records/*` (store + verify) |
| **Attendance Registers** | `/attendance-registers/*` (full workflow) |
| **Media** | `/media/*` (library management) |
| **Resources** | `/resources/*` (extensive resource library) |
| **AI** | `/ai/*`, `/translate/*`, `/transcribe`, `/synthesize` |
| **Offices** | `/offices/*`, `/office-types` |
| **Departments** | `/departments/*`, `/campaigns/{campaign}/departments`, etc. |
| **Invitations** | `/invitations/*` (admin only) |

---

## 2. AUTHORIZATIONSERVICE

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Services\AuthorizationService.php` (1106 lines)

### 2.1 Public Method Inventory

| Line | Method Signature | Description |
|------|------------------|-------------|
| 50 | `hasPermissionForCampaign(User $user, string\|array $permissions, Campaign $campaign): bool` | Check permission at campaign scope |
| 67 | `hasPermissionForSite(User $user, string\|array $permissions, MissionSite $site, bool $includeTeamScopedAssignments = true): bool` | Check permission at site scope; `$includeTeamScopedAssignments` controls whether team-scoped assignments count for site access |
| 84 | `hasPermissionForTeam(User $user, string\|array $permissions, Team $team): bool` | Check permission at team scope |
| 95 | `hasGlobalPermission(User $user, string $permission): bool` | Check global-level permission |
| 107 | `hasPermissionForOrgUnit(User $user, string $permission, int $orgUnitId): bool` | Check permission for org unit (checks hierarchy) |
| 126 | `hasAnyOrgUnitPermission(User $user, string $permission): bool` | Check if user has permission at ANY org unit |
| 145 | `hasPermissionAtAssignmentScope(User $user, string $permission, string $scopeType, ?int $scopeId): bool` | Resolve permission at a specific assignment scope |
| 167 | `getActiveAssignmentsGranting(User $user, string $permission): Collection` | Get active assignments that grant a specific permission |
| 177 | `canViewCampaign(User $user, Campaign $campaign): bool` | Check if user can see campaign (any scoping) |
| 186 | `canViewSite(User $user, MissionSite $site): bool` | Check if user can see site (any scoping) |
| 195 | `canViewTeam(User $user, Team $team): bool` | Check if user can see team (any scoping) |
| 218 | `getEffectivePermissions(User $user): array` | Returns flat, de-duplicated permission codes (UI-only) |
| 237 | `getEffectiveScopes(User $user): array` | Returns active assignments with scope info |
| 266 | `campaignIdsForOrgUnit(int $orgUnitId): array` | Get campaign IDs led by org unit or descendants |
| 315 | `getAuthorisedScopes(User $user, string\|array\|null $permissions = null, bool $includeTeamScopedAssignments = true): array` | Resolve authorized (campaign, site) scope pairs |
| 461 | `teamIdsWithPermission(User $user, string\|array $permissions): array` | Get team IDs user has permission for |
| 495 | `hasAuthorisedScopeInCampaign(User $user, string\|array\|null $permissions, Campaign $campaign): bool` | Check if authorized scope exists in campaign |
| 557 | `assignmentScope(UserRoleAssignment\|array $source): array` | Resolve assignment scope type and ID |
| 589 | `hasPermissionDelegation(string $permissionCode, string $targetType, ?int $targetId): bool` | Check if delegation exists for permission at scope |
| 795 | `scopeQuery(Builder $query, array $scopes, string $campaignColumn = 'campaign_id', ?string $siteColumn = null): Builder` | Constrain query to authorized scopes |
| 848 | `resolveEffectiveAssignments(User $user, Campaign $campaign, ?MissionSite $site = null, ?Team $team = null, bool $includeTeamScopedAssignments = true): Collection` | **CORE:** Resolve active assignments covering resource |

### 2.2 `resolveEffectiveAssignments` Logic (Lines 848-883)

This is the **core authorization matching engine**. The method filters a user's active assignments to find those that cover a given resource (campaign + optional site/team).

**Matching Rules by Scope Type:**

| Scope Type | Match Condition |
|------------|-----------------|
| `global` | Always matches (returns `true`) |
| `org_unit` | `orgUnitCoversOrgUnit($assignment->scoped_org_unit_id, $campaign->lead_org_unit_id)` - checks if the assignment's org unit is an ancestor of the campaign's lead org unit |
| `campaign` | `(int) $assignment->scoped_campaign_id === (int) $campaign->id` AND (`$site === null` OR campaign matches) |
| `site` | `$site !== null` AND `(int) $assignment->scoped_site_id === (int) $site->id` |
| `team` | `$includeTeamScopedAssignments` is `true` AND `$assignment->scoped_team_id !== null` AND (either: `$team !== null` AND team ID matches, OR `$team === null` AND `$site !== null` AND the team belongs to the site) |

**Key Design Principle:** Team-scoped assignments normally grant access to the SITE that the team belongs to (for loading site context), but NOT to sibling teams. The `$includeTeamScopedAssignments` parameter allows callers to disable this behavior when team inheritance is inappropriate (e.g., for report submission authority).

---

## 3. ROLE + ASSIGNMENT MODELS

### 3.1 UserRoleAssignment Model

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\UserRoleAssignment.php`

**$fillable (Lines 18-24):**
```php
'user_id', 'system_role_id', 'scope_type',
'scoped_org_unit_id', 'scoped_campaign_id', 'scoped_site_id', 'scoped_team_id',
'valid_from', 'valid_until', 'granted_by_user_id',
'role_permission_version_id', 'status', 'revoked_by_user_id', 'revoked_at', 'revocation_reason',
'suspended_by_user_id', 'suspended_at', 'suspension_reason',
```

**$casts (Lines 26-34):**
```php
'valid_from' => 'date',
'valid_until' => 'date',
'revoked_at' => 'datetime',
'suspended_at' => 'datetime',
```

**Relationships:**
- `user()` → User (belongsTo)
- `role()` → SystemRole (belongsTo)
- `grantedBy()` → User (belongsTo)
- `permissionVersion()` → RolePermissionVersion (belongsTo)
- `permissionOverrides()` → UserRoleAssignmentOverride (hasMany)
- `scopedOrgUnit()` → OrgUnit (belongsTo)
- `scopedCampaign()` → Campaign (belongsTo)
- `scopedSite()` → MissionSite (belongsTo)
- `scopedTeam()` → Team (belongsTo)

**Scope Type Values:** `global`, `org_unit`, `campaign`, `site`, `team`

**Expired/Revoked Determination (Lines 99-106):**
```php
public function isActive(): bool
{
    $today = now()->toDateString();
    $fromOk = $this->valid_from === null || $this->valid_from->toDateString() <= $today;
    $untilOk = $this->valid_until === null || $this->valid_until->toDateString() >= $today;

    return $this->status === 'active' && $this->role?->is_active === true && $fromOk && $untilOk;
}
```

An assignment is **EXPIRED** when:
- `status !== 'active'`, OR
- `role->is_active !== true`, OR
- `valid_from > today`, OR
- `valid_until < today`

An assignment is **REVOKED** when:
- `status === 'revoked'` (explicit revocation), OR
- `revoked_at` is not null, OR
- `revoked_by_user_id` is not null

### 3.2 SystemRole Model

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\SystemRole.php`

**$fillable (Line 20):**
```php
'code', 'label', 'scope_type', 'is_active', 'current_permission_version_id'
```

**Relationships:**
- `permissions()` → Permission (belongsToMany via role_permissions)
- `roleAssignments()` → UserRoleAssignment (hasMany)
- `currentPermissionVersion()` → RolePermissionVersion (belongsTo)
- `permissionVersions()` → RolePermissionVersion (hasMany)

**Scope Types:** `global`, `org_unit`, `campaign`, `site`, `team`

### 3.3 RolePermissionVersion Model

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\RolePermissionVersion.php`

**$fillable (Line 12):**
```php
'system_role_id', 'version', 'created_by_user_id', 'change_reason', 'published_at'
```

**$casts (Lines 28-30):**
```php
'published_at' => 'datetime'
```

**Key Feature:** Published versions are **IMMUTABLE** (Lines 16-25):
```php
static::updating(function (self $version): void {
    if ($version->getOriginal('published_at') !== null) {
        throw new \LogicException('Published role permission versions are immutable.');
    }
});
static::deleting(function (self $version): void {
    if ($version->published_at !== null) {
        throw new \LogicException('Published role permission versions cannot be deleted.');
    }
});
```

### 3.4 Permission Model

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\Permission.php`

**$fillable (Line 11):**
```php
'code', 'label', 'module'
```

**No timestamps** (`public $timestamps = false`)

### 3.5 UserRoleAssignmentOverride Model

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\UserRoleAssignmentOverride.php`

**$fillable (Line 11):**
```php
'user_role_assignment_id', 'permission_id', 'effect', 'created_by_user_id', 'reason'
```

**Effect values:** `grant`, `deny`

---

## 4. ROLE DEFINITIONS + SCOPE TYPES + PERMISSIONS

**File:** `e:\Year 2026\Codding\UU Mission\backend\database\seeders\DatabaseSeeder.php` (Lines 683-946)

### 4.1 System Roles (Lines 773-878)

| Role Code | Label | Scope Type | Description |
|-----------|-------|------------|-------------|
| `platform_admin` | Platform Administrator | `global` | Full platform access |
| `union_director` | Union Mission Director | `org_unit` | Union-level oversight |
| `campaign_leader` | Campaign Leader | `campaign` | Campaign management |
| `site_secretary` | Site Secretary | `site` | Site-level administration |
| `site_coordinator` | Site Coordinator | `site` | Site leadership |
| `team_leader` | Team Leader | `team` | Team leadership |
| `team_secretary` | Team Secretary | `team` | Team administration |
| `missionary` | Missionary / Field Worker | `site` | Field operations |

### 4.2 Permission Codes by Module (Lines 688-762)

| Module | Permission Codes |
|--------|------------------|
| **admin** | `admin.users.manage`, `admin.roles.manage`, `admin.org.manage` |
| **org** | `org.view`, `org.manage` |
| **campaigns** | `campaigns.create`, `campaigns.edit`, `campaigns.view`, `campaigns.archive` |
| **sites** | `sites.create`, `sites.edit`, `sites.view` |
| **assignments** | `assignments.manage`, `assignments.view` |
| **activities** | `activities.view`, `activities.create`, `activities.update`, `activities.delete` |
| **attendance** | `attendance.submit`, `attendance.verify`, `attendance.view` |
| **reporting** | `report.submit`, `report.review`, `report.view`, `report.view_team` |
| **outcomes** | `outcomes.record`, `outcomes.view`, `outcomes.manage_followup` |
| **people** | `people.create`, `people.edit`, `people.view` |
| **outreach-contacts** | `outreach-contacts.create`, `outreach-contacts.edit`, `outreach-contacts.view` |
| **resources** | `resources.view`, `resources.create`, `resources.manage`, `resources.distribute`, `resources.approve_distribute`, `resources.publish`, `resources.archive` |
| **communications** | `communications.view`, `communications.publish`, `communications.acknowledge`, `communications.revoke` |

### 4.3 Role-Scope Compatibility

The `role_scope_compatibilities` table enforces which scope types each role can be assigned at. This is auto-populated when a role is created (Lines 28-42 in SystemRole model).

### 4.4 Permission Delegations

The `permission_delegations` table records where permissions can be granted. A delegation must exist before a role assignment can be made at that scope.

---

## 5. PERMISSION-LOADING APIs

### 5.1 AuthController::login() / AuthController::me()

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\Auth\AuthController.php`

Returns JSON with (Lines 565-643):
```php
[
    'id' => $user->id,
    'email' => $user->email,
    'status' => $user->status,
    'timezone' => $user->timezone,
    'last_login_at' => $user->last_login_at,
    'primary_role' => $primaryRole, // 'admin', 'missionary', or null
    'roles' => [ // Array of assigned roles
        ['code' => '...', 'label' => '...', 'scope_type' => '...']
    ],
    'permissions' => $this->auth->getEffectivePermissions($user), // FLAT union of all effective permission codes
    'scopes' => $this->auth->getEffectiveScopes($user), // Active assignments with scope details
    'person' => [...] // Full person profile if linked
]
```

### 5.2 WorkspaceController::myAssignment()

Returns the authenticated user's current assignment context with campaign, site, and team details.

### 5.3 Reference Endpoint: `/ref/system-roles`

Returns all active system roles for dropdown selection.

### 5.4 SystemRoleAdministrationController::index()

**Admin only.** Returns all roles with their current permission versions and compatible scope types.

---

## 6. CAMPAIGN/SITE/TEAM RELATIONSHIPS

### 6.1 Campaign Model

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\Campaign.php`

**Relationships:**
- `programme()` → Programme (belongsTo)
- `leadOrgUnit()` → OrgUnit (belongsTo)
- `sites()` → MissionSite (hasMany)
- `teams()` → Team (hasMany)
- `objectives()` → CampaignObjective (hasMany)
- `assignments()` → CampaignAssignment (hasMany)
- `activities()` → Activity (hasMany)
- `fieldReports()` → FieldReport (hasMany)
- `outcomes()` → MissionOutcome (hasMany)

### 6.2 MissionSite Model

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\MissionSite.php`

**Relationships:**
- `campaign()` → Campaign (belongsTo)
- `missionArea()` → MissionArea (belongsTo)
- `teams()` → Team (hasMany)
- `activities()` → Activity (hasMany)
- `attendanceRegisters()` → AttendanceRegister (hasMany)
- `fieldReports()` → FieldReport (hasMany)
- `outcomes()` → MissionOutcome (hasMany)
- `assignments()` → CampaignAssignment (hasMany)
- `logistics()` → SiteLogistics (hasOne)

### 6.3 Team Model

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\Team.php`

**Key Feature:** Teams may be campaign-wide (`mission_site_id = null`) or site-specific.

**Relationships:**
- `campaign()` → Campaign (belongsTo)
- `site()` → MissionSite (belongsTo)
- `teamType()` → TeamType (belongsTo)
- `originOrgUnit()` → OrgUnit (belongsTo)
- `members()` → TeamMember (hasMany)

**Method:**
```php
public function isCampaignWide(): bool
{
    return $this->mission_site_id === null;
}
```

### 6.4 CampaignAssignment Model

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Models\CampaignAssignment.php`

**Key Columns:**
- `campaign_id` → Campaign
- `person_id` → Person (the missionary)
- `mission_site_id` → MissionSite (optional)
- `assigned_team_id` → Team (optional)
- `functional_role_type_id` → FunctionalRoleType (e.g., preacher, bible worker)
- `system_role_id` → SystemRole (optional - grants system permissions)
- `status` → Workflow status (confirmed, arrived, completed, etc.)
- `expected_arrival`, `actual_arrival`, `departure` → Deployment dates

**Key Design:** A deployment may grant a system role via `system_role_id`, creating a linked `UserRoleAssignment` record.

---

## 7. ASSIGNMENT LIFECYCLE

### 7.1 Creating Assignments

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\AssignmentController.php`

**Method:** `store()` (Lines 68-136)

1. **Authorization:** Checks `assignments.manage` permission for the campaign via `CampaignAssignmentPolicy::create()`
2. **Validation:** Validates person, site (optional), team (optional), functional role, system role
3. **Scope Validation:** Ensures site/team belong to the campaign
4. **Uniqueness Check:** One assignment per person per campaign
5. **User Account Check:** If granting a system role, the person must have a linked user account (`assertPersonCanHoldSystemRole`)
6. **Database Transaction:**
   - Creates the `CampaignAssignment`
   - Creates linked `UserRoleAssignment` if system role requested
   - Records event in `user_role_assignment_events`

**Policy Check:** `CampaignAssignmentPolicy::create()` (Lines 35-38):
```php
public function create(User $user, Campaign $campaign): bool
{
    return $this->auth->hasPermissionForCampaign($user, 'assignments.manage', $campaign);
}
```

### 7.2 Updating Assignments

**Method:** `update()` (Lines 158-255)

1. **Authorization:** Checks `assignments.manage` via `CampaignAssignmentPolicy::update()`
2. **Scope Validation:** Re-validates site/team bounds after update
3. **Role Change Handling:**
   - Detects if `system_role_id` changed
   - Revokes old `UserRoleAssignment` (status → 'revoked', record event)
   - Creates new `UserRoleAssignment` with new role
4. **Database Transaction:** Ensures atomicity

### 7.3 Revoking/Deleting Assignments

**Method:** `destroy()` (Lines 263-294)

1. **Authorization:** Checks `assignments.manage` via `CampaignAssignmentPolicy::delete()`
2. **Deletable Status Check:** Only `confirmed` status is deletable (pre-arrival)
3. **Role Assignment Cleanup:** Finds and revokes linked `UserRoleAssignment`
4. **Event Recording:** Records revocation in `user_role_assignment_events`
5. **Soft Delete:** `CampaignAssignment` is soft-deleted; `UserRoleAssignment` is marked revoked (not deleted - preserves history)

### 7.4 System Role Assignment Lifecycle (UserRoleAssignment)

**File:** `e:\Year 2026\Codding\UU Mission\backend\app\Policies\UserRoleAssignmentPolicy.php`

**Key Policy Methods:**

| Method | Logic |
|--------|-------|
| `view()` (Lines 59-70) | Match based on assignment scope type; uses respective `hasPermissionFor*` methods |
| `create()` (Lines 77-131) | 1. Prevent self-assignment<br>2. Check `assignments.manage` at target scope<br>3. Verify role-scope compatibility<br>4. Verify permission version matches current<br>5. Check all permissions have delegations |
| `update()` (Lines 137-152) | Prevent self-modification; same scope checks as create |
| `delete()` (Lines 172-180) | Prevent self-revocation; same scope checks |

**Event Recording:** All lifecycle actions record to `user_role_assignment_events` table with:
- `user_role_assignment_id`
- `action` (created, revoked, suspended, reactivated, version_changed, permission_override_added, permission_override_removed)
- `actor_user_id`
- `reason`
- `changes` (JSON)
- `created_at`

---

## 8. POLICY INVENTORY

**Location:** `e:\Year 2026\Codding\UU Mission\backend\app\Policies\`

### 8.1 Policy Files and Methods

| Policy File | Methods | Key Permission Codes Used |
|-------------|---------|---------------------------|
| `ActivityPolicy.php` | viewAny, view, create, update, delete | `activities.view`, `activities.create`, `activities.update`, `activities.delete` |
| `AttendanceRegisterPolicy.php` | viewAny, view, create, update, delete, submit, verify, reopen | `attendance.view`, `attendance.submit`, `attendance.verify` |
| `BaptismRecordPolicy.php` | create, verify | Implicit through person/outreach access |
| `CampaignAssignmentPolicy.php` | viewAny, view, create, update, delete | `assignments.view`, `assignments.manage` |
| `CampaignPolicy.php` | viewAny, view, viewContext, create, update, transition, delete, viewStats | `campaigns.view`, `campaigns.create`, `campaigns.edit`, `campaigns.archive` |
| `CommunicationPolicy.php` | viewAny, view, create, update, delete, acknowledge, revoke | `communications.view`, `communications.publish`, `communications.acknowledge`, `communications.revoke` |
| `DepartmentPolicy.php` | viewForCampaign, viewForSite, create, update, delete, addMember, removeMember | Implicit through campaign/site access |
| `FieldReportPolicy.php` | viewAny, view, create, update, submit, review, forward, consolidate, recover | `report.view`, `report.view_team`, `report.submit`, `report.review` |
| `MissionOutcomePolicy.php` | viewAny, view, create, update, delete, assignFollowUp, completeFollowUp | `outcomes.record`, `outcomes.view`, `outcomes.manage_followup` |
| `MissionSitePolicy.php` | viewAny, view, create, update, delete | `sites.view`, `sites.create`, `sites.edit` |
| `OrgMembershipPolicy.php` | view, create, update, approve, reject, delete | Implicit through org management |
| `OutreachContactPolicy.php` | viewAny, view, create, update, delete | `outreach-contacts.view`, `outreach-contacts.create`, `outreach-contacts.edit` |
| `OutreachEncounterPolicy.php` | view, create, complete, reschedule | Implicit through outreach contact access |
| `PersonPolicy.php` | viewAny, view, create, update, delete | `people.view`, `people.create`, `people.edit` |
| `ResourcePolicy.php` | (Extensive resource permissions) | `resources.view`, `resources.create`, `resources.manage`, `resources.distribute`, `resources.approve_distribute`, `resources.publish`, `resources.archive` |
| `TeamPolicy.php` | viewAny, view, create, update, manageMember | `assignments.manage` at team/site/campaign scope |
| `UserRoleAssignmentPolicy.php` | viewAnyForCampaign, viewAnyForSite, viewAnyForTeam, viewAnyForOrgUnit, view, create, update, updateVersion, delete | `assignments.manage`, `admin.roles.manage` |

### 8.2 Policies with Missing Implementations

All expected policies exist. No gaps detected in policy coverage.

---

## 9. ADMIN/PLATFORM VS OPERATIONAL SEPARATION

### 9.1 Platform Administration Routes

| Category | Routes | Permission Requirements |
|----------|--------|------------------------|
| **User Management** | `/auth/*` (admin functions), `/invitations/*` | `admin.users.manage` |
| **RBAC Administration** | `/rbac/*`, `/role-assignments/*` | `admin.roles.manage` (global) |
| **Org Hierarchy** | `/org-hierarchy`, `/org-units/*` | `org.manage`, `admin.org.manage` |
| **Reference Data** | `/ref/*` | Various - mostly public or low-privilege |

### 9.2 Operational Routes

| Category | Routes |
|----------|--------|
| **Campaign Management** | `/campaigns/*` |
| **Site Operations** | `/campaigns/{campaign}/sites/*` |
| **Team Management** | `/campaigns/{campaign}/teams/*` |
| **Activity Scheduling** | `/campaigns/{campaign}/activities/*` |
| **Field Reports** | `/campaigns/{campaign}/reports/*` |
| **Mission Outcomes** | `/campaigns/{campaign}/outcomes/*` |
| **Attendance** | `/attendance-registers/*` |
| **Outreach** | `/outreach-contacts/*`, `/outreach-encounters/*` |
| **Baptism Records** | `/baptism-records/*` |
| **Workspace** | `/workspace/*` |
| **Communications** | `/communications/*` |
| **Resources** | `/resources/*` |

### 9.3 Separation Enforcement

**Design Principles:**
1. **Controller-level separation:** Admin controllers (SystemRoleAdministrationController, InvitationController) vs. operational controllers (CampaignController, MissionSiteController, etc.)
2. **Permission-based gating:** Admin actions require `admin.*` permissions; operational actions require resource-specific permissions
3. **Scope enforcement:** Operational controllers always validate scope (campaign/site/team) before processing
4. **No mixed controllers:** Each controller handles either admin or operational concerns, not both

**No identified mixing:** The architecture cleanly separates platform administration from field operations.

---

## 10. KEY ARCHITECTURAL FINDINGS

### 10.1 Strengths

1. **Comprehensive Scope Hierarchy:** Well-designed 5-level scope system (global → org_unit → campaign → site → team)
2. **Immutable Permission Versions:** Prevents accidental permission drift; changes require explicit new versions
3. **Permission Delegation Chain:** Administrative authority can be traced through delegations
4. **Event Sourcing for Assignments:** All lifecycle events recorded with actor and reason
5. **Dual Assignment Model:** Clean separation between operational deployments (CampaignAssignment) and system permissions (UserRoleAssignment)
6. **Hierarchical Org Structure:** Supports complex organizational hierarchies (Union → Conference → District → Zone → Church)
7. **Policy-Based Authorization:** Every controller action protected by appropriate policy

### 10.2 Complexity Points

1. **Team-Scoped Assignment Inheritance:** Team assignments grant site access for context loading but not for authority - this nuanced behavior requires careful `$includeTeamScopedAssignments` parameter management
2. **Permission Version Pinned at Assignment:** Assignments store `role_permission_version_id` - when role permissions change, existing assignments keep their pinned version until explicitly updated
3. **Delegation Requirement:** Every permission granted must have an active delegation at the target scope - this adds administrative overhead but ensures traceability

### 10.3 Security Notes

1. **No Self-Escalation:** UserRoleAssignmentPolicy prevents users from assigning themselves roles (Line 85-87)
2. **No Self-Modification:** Users cannot modify or revoke their own assignments (Line 140-141, 174-177)
3. **Scope Validation:** All mutations validate that sites/teams belong to the correct campaign
4. **Fail-Closed:** `scopeQuery()` returns no rows when scopes are empty (Line 801-803)
5. **Deny Overrides Win:** In `effectivePermissionDecision()`, any deny in the applicable set prevents access (Lines 966-968)

---

## Appendices

### A. File Paths Summary

| Component | Path |
|-----------|------|
| Routes | `e:\Year 2026\Codding\UU Mission\backend\routes\api.php` |
| AuthorizationService | `e:\Year 2026\Codding\UU Mission\backend\app\Services\AuthorizationService.php` |
| UserRoleAssignment Model | `e:\Year 2026\Codding\UU Mission\backend\app\Models\UserRoleAssignment.php` |
| SystemRole Model | `e:\Year 2026\Codding\UU Mission\backend\app\Models\SystemRole.php` |
| RolePermissionVersion Model | `e:\Year 2026\Codding\UU Mission\backend\app\Models\RolePermissionVersion.php` |
| Permission Model | `e:\Year 2026\Codding\UU Mission\backend\app\Models\Permission.php` |
| Campaign Model | `e:\Year 2026\Codding\UU Mission\backend\app\Models\Campaign.php` |
| MissionSite Model | `e:\Year 2026\Codding\UU Mission\backend\app\Models\MissionSite.php` |
| Team Model | `e:\Year 2026\Codding\UU Mission\backend\app\Models\Team.php` |
| CampaignAssignment Model | `e:\Year 2026\Codding\UU Mission\backend\app\Models\CampaignAssignment.php` |
| AssignmentController | `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\AssignmentController.php` |
| UserRoleAssignmentPolicy | `e:\Year 2026\Codding\UU Mission\backend\app\Policies\UserRoleAssignmentPolicy.php` |
| CampaignAssignmentPolicy | `e:\Year 2026\Codding\UU Mission\backend\app\Policies\CampaignAssignmentPolicy.php` |
| AuthController | `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\Auth\AuthController.php` |
| WorkspaceController | `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\WorkspaceController.php` |
| SystemRoleAdministrationController | `e:\Year 2026\Codding\UU Mission\backend\app\Http\Controllers\SystemRoleAdministrationController.php` |
| DatabaseSeeder | `e:\Year 2026\Codding\UU Mission\backend\database\seeders\DatabaseSeeder.php` |

---

**End of Audit Report**

Let me verify the most decisive facts myself — workspace selection is the crux of the proposal.

I have everything I need. Let me check the workspace root for an existing docs convention.

Convention confirmed — the project keeps root-level Markdown reports. Let me write the assessment there.

Report written: [Workspace Architecture Assessment and Proposal.md](computer://e:\Year 2026\Codding\UU Mission\Workspace Architecture Assessment and Proposal.md). No code, migration, permission or seed changes were made.

## Headline findings

**The backend is already correct and needs no authorization change.** Every controller authorizes through a policy, and every policy resolves the resource's real scope via `AuthorizationService`. There are no policy gaps.

**The frontend is where scope is lost.** Three facts decide the whole design:

1. **Workspace selection is binary.** [AuthController.php](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Http/Controllers/Auth/AuthController.php#L580-L595) sets `primary_role = 'admin'` only for `platform_admin`/`union_director`; everyone else gets `'missionary'`. [AuthGuard.tsx](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/components/AuthGuard.tsx#L22-L37) then forces non-admins away from `/admin`. So **`campaign_leader`, `site_coordinator`, `site_secretary`, `team_leader`, `team_secretary` all land in the missionary workspace and cannot reach `/admin` at all** — including campaign leaders, who cannot reach campaign administration.

2. **The frontend already has the data it needs.** `AuthUser.scopes` ([api.ts](file:///e:/Year%202026/Codding/UU%20Mission/frontend/src/lib/api.ts#L219-L229)) already carries `scope_type` + `campaign_id`/`site_id`/`team_id` from `getEffectiveScopes()`. **No code reads it.** Workspace selection ignores it entirely.

3. **`/admin` contains zero platform administration.** All six `/admin` pages are field-operational. Accounts, roles, org hierarchy and reference data have **no frontend at all** — yet `/rbac/*`, `/invitations/*`, `/org-hierarchy` all exist in the API. The label and the content are inverted.

**Why a site coordinator can't be fixed with a campaign console:** [resolveEffectiveAssignments](file:///e:/Year%202026/Codding/UU%20Mission/backend/app/Services/AuthorizationService.php#L848-L883) only matches a `site` assignment when a concrete site is supplied — so `hasPermissionForCampaign` can *never* succeed for a site-scoped holder. Site roles must be routed to a **site-contextual** workspace, not a campaign-wide one.

## Recommendation

Introduce a real `workspace` concept derived from **effective, active (`isActive()`-qualifying) role assignments**, and generalise the two shells into one context-driven `WorkspaceShell`:

- Four workspaces: Missionary, Site Operations (`/site/[siteId]/*`), Campaign Management (`/campaign/[campaignId]/*`), Platform Administration (`/admin/*`, narrowed).
- Derive `workspaces[]` **on the backend** in `/auth/me` to keep the rule in one place.
- Replace `AuthGuard`'s binary redirect with descriptor-based routing; switcher only when >1; site/campaign selector for multiple contexts; never separate accounts.
- The four gaps are resolved structurally — team creation is re-homed to Site Operations with the site **fixed by the route** (no campaign-wide option reachable), nav becomes per-workspace permission-filtered, and the outreach form takes context from the workspace rather than arbitrary IDs.

**Two extra issues surfaced** (beyond the four): `workspaceApi.mySites()` is keyed on `outcomes.record` — an unrelated permission — so site holders without it see no sites; and `myAssignment()` returns only the *first* context, with no multi-site selection.

No migration is needed for Phases 1–4; every existing module, policy and API is reused.

Section 13 lists 10 decisions needing your approval before implementation — chiefly: where the derivation rule lives (backend vs client), path-segment vs query-param routes, whether to keep `primary_role` as a transition shim, and whether the operational `/admin/*` URLs move with redirects.

---

**User**

# UU Mission — Implement Role-Based Operational Workspaces (Phase 1)

We are now proceeding with the implementation of **Role-Based Operational Workspaces** in UU Mission.

The goal is to move beyond the current binary distinction between `admin` and `missionary` and introduce operational workspaces that reflect what each authenticated person is authorised and responsible for.

This is an important architectural improvement. Implement it carefully, incrementally, and without destabilising existing functionality.

## 1. Target Workspace Architecture

The long-term architecture should support four workspaces:

1. **Missionary Workspace** — personal missionary activities, assignments, resources, learning, communications and fieldwork.
2. **Site Operations Workspace** — operational management of an assigned mission site.
3. **Campaign Management Workspace** — oversight and coordination of campaigns and their sites.
4. **Platform Administration Workspace** — platform-wide accounts, RBAC, organisation hierarchy, configuration and reference data.

These are distinct operational experiences, not simply four role labels.

A person may qualify for more than one workspace. They should retain one account and switch workspaces when multiple authorised workspaces are available.

However, **do not attempt to implement all four workspaces at once.**

## 2. Critical Authorisation Principles

These principles are mandatory.

### A. Backend authorisation remains authoritative

Use the existing `AuthorizationService`, effective role assignments, permission versions and scope resolution as the source of truth.

Do not create a parallel authorisation system.

Workspace availability must be derived from active, valid, effective role assignments and their actual permission authority.

Consider:
- Assignment status.
- Role status.
- `valid_from` and `valid_until`.
- Revocation.
- Role permission versions.
- Scope type and scope identifiers.
- The permissions actually granted within that scope.

Do not derive operational authority from deployment assignments alone.

### B. Do not confuse organisational authority with platform administration

An `org_unit`-scoped role does not automatically qualify someone for Platform Administration.

For example, a Union Director may have authority to oversee campaigns within their organisational jurisdiction without having platform-wide RBAC or account-management authority.

Platform Administration must require explicit platform-administration authority, such as the appropriate global platform permissions.

Similarly, organisational officers should receive Campaign Management access only where their actual permissions and organisational scope authorise that responsibility.

### C. Respect team boundaries

A Team Leader or Team Secretary may see the parent site's identity as context.

However, a team-scoped assignment must never become site-wide authority merely because the team belongs to that site.

The scope of every operation must remain enforced by the backend.

### D. Different roles have different responsibilities

Site Coordinator, Site Secretary, Team Leader and Team Secretary may use the Site Operations framework, but they must not automatically receive identical navigation, dashboards or capabilities.

Their interfaces must reflect their actual responsibilities and effective permissions.

### E. Preserve identity and assignment separation

Do not change the established distinction between:
- Person/account identity.
- Missionary profile.
- Organisational affiliation.
- Campaign/site/team deployment.
- Authorised system-role assignments.

Joining a campaign or being deployed to a site does not independently grant operational permissions.

## 3. Implementation Scope — First Milestone

Implement the workspace foundation and the first operational workspace.

### Phase 1: Workspace resolution and routing foundation

1. Inspect the current authentication response and `AuthUser` frontend type.
2. Introduce a backend workspace resolver based on effective role assignments and permission scopes.
3. Expose a clear workspace descriptor through the appropriate authentication endpoint, preferably `/auth/me`.
4. Ensure multiple authorised workspaces and contexts can be represented rather than returning only the first assignment.
5. Replace binary `primary_role === 'admin' | 'missionary'` routing with workspace-aware routing.
6. Retain `primary_role` as a compatibility field during migration. Do not remove it until existing consumers have been safely migrated.
7. Introduce a workspace switcher only when the user has more than one available workspace.
8. If the user has no operational workspace, display a neutral, helpful state rather than incorrectly forcing them into an administrative or missionary workspace.

### Phase 2: Site Operations Workspace

Prioritise the **Site Coordinator** experience first.

Create a dedicated Site Operations shell and overview that answers:

- Is my mission site operating effectively?
- What is happening today?
- What needs my attention?
- Which operational responsibilities are awaiting my action?

The initial Site Coordinator navigation should cover:

- Overview
- People
- Teams
- Activities
- Attendance
- Outreach
- Reports
- Outcomes
- Logistics / Site Readiness, where existing functionality supports it

Use the existing pages, APIs, components and functionality wherever possible. Reuse and reorganise existing modules rather than creating duplicate implementations.

The overview should surface useful operational information, including:

- Personnel and team coverage.
- Teams and their leaders.
- Today's scheduled or ongoing activities.
- Attendance awaiting verification.
- Outreach contacts and follow-ups.
- Reports awaiting review.
- Outcomes and follow-up responsibilities.
- Site readiness or logistics issues, where available.

Do not fill the dashboard with invented statistics or decorative cards. Use real data and meaningful empty states.

### Phase 3: Permission-aware navigation and actions

Introduce or improve a frontend permission helper that considers both permission and the active scope/context.

Conceptually:

`can(user, permission, context)`

Do not rely on a flat union of permissions to decide whether a user can perform an operation in a particular site, campaign or team.

The backend must continue enforcing every operation independently. Frontend permission checks are for navigation and user experience, not security.

For this milestone:
- Implement the Site Coordinator experience correctly.
- Preserve the existing Missionary Workspace.
- Keep other roles compatible with the existing application until their workspace experiences are implemented.
- Do not prematurely give every role a new dashboard.

## 4. Existing Routing and Functionality

Current `/admin/*` pages contain field-operational functionality, not just platform administration.

Do not break these routes or move every page in one operation.

Preserve existing routes while introducing the new Site Operations experience. Where an existing page is reused, make sure its API calls, permissions and context are correct.

Only introduce redirects when the replacement destination is implemented and verified.

Do not remove existing functionality simply because its current location is architecturally imperfect.

## 5. Important Existing Issue to Inspect

Review `WorkspaceController::mySites()`.

The current implementation reportedly uses `outcomes.record` to determine site visibility. That permission is unrelated to general site access and may exclude legitimate site-role holders.

Correct this only in a way that respects actual effective site scopes and authorisation. Do not replace it with a broad permission or expose sites the user is not authorised to access.

Also inspect the existing first-assignment-only behaviour in workspace context resolution. Users with multiple authorised sites or roles must not silently lose access to their other contexts.

## 6. Testing Requirements

Add focused backend and frontend tests.

At minimum, verify:

**Backend**
- Active Site Coordinator receives Site Operations access for the authorised site.
- Expired and revoked assignments do not produce workspace access.
- A user with multiple site assignments can access all authorised site contexts.
- A team-scoped role cannot exercise site-wide permissions.
- An organisationally scoped officer does not automatically receive Platform Administration.
- A campaign leader receives Campaign Management only when their actual authority and scope qualify.
- A missionary retains the existing Missionary Workspace.
- A user without a qualifying workspace receives a neutral no-workspace response.
- Permission-version changes do not silently alter existing pinned assignments.

**Frontend**
- Workspace resolution handles multiple workspaces and contexts.
- Workspace switching appears only when appropriate.
- Site Coordinator navigation reflects the available permissions.
- A user cannot access a site context merely by changing a URL.
- Existing missionary routing and experience remain functional.
- Existing `/admin` functionality is not accidentally broken.

Run relevant tests, TypeScript checks and production build. Clearly distinguish tests actually executed from tests not executed.

## 7. Scope Discipline

Do not:
- Redesign the entire application.
- Rebuild all four workspaces now.
- Introduce unnecessary database tables or migrations.
- Duplicate existing operational modules.
- Weaken backend policies to make the frontend work.
- Treat every organisational officer as a platform administrator.
- Give team-scoped users site-wide access.
- Remove the existing missionary experience.
- Perform unrelated cleanup or refactoring.

A database migration should only be introduced if the existing data model genuinely cannot support the required workspace foundation. Explain the need before making such a change.

## 8. Implementation Workflow

Work incrementally.

**Checkpoint 1 — Workspace foundation**
- Inspect and implement workspace resolution.
- Update the authentication response and frontend types.
- Introduce workspace-aware routing.
- Preserve existing behaviour.
- Run relevant backend and frontend tests.

**Checkpoint 2 — Site Operations**
- Implement the Site Operations shell.
- Implement the Site Coordinator overview.
- Connect the existing operational modules.
- Implement permission-aware navigation and context selection.
- Verify actual site-level boundaries.

At each checkpoint, report:
1. Files created or modified.
2. Backend changes.
3. Frontend changes.
4. Tests executed and their results.
5. Any unresolved issues.
6. Any decisions requiring approval.

Do not claim browser verification unless you actually perform it.

## 9. Definition of Success

The first milestone is successful when:

- A Site Coordinator logs in and enters a dedicated Site Operations experience.
- The coordinator sees the correct authorised site context.
- The coordinator can navigate the operational modules relevant to their responsibilities.
- The interface reflects actual permissions.
- Site-level boundaries remain enforced.
- Missionaries continue using their existing workspace without regression.
- Existing administrative functionality remains available.
- The architecture can accommodate Campaign Management and Platform Administration later without another fundamental routing redesign.

**Begin with a focused inspection of the current implementation, then implement Checkpoint 1 followed by Checkpoint 2. Do not stop at another proposal or architecture document. Report any genuine blocker before making a potentially destructive or scope-expanding change.**

**Assistant**

I'll implement this. Let me set up tracking and inspect the decisive files first.

I'll verify the exact current state of the files I need to modify before implementing.

Now let me read the AuthorizationService key methods.

Inspection complete. Now implementing Checkpoint 1.

Now the workspace resolver service.

Now the `mySites()` scope fix.

Backend complete. Now the frontend types.

Now the workspace switcher.

I'll verify the current state of the frontend files before continuing with Checkpoint 2.

Frontend foundation is in place. Now let me inspect the admin layout and API surfaces to build the Site Operations pages.

---

