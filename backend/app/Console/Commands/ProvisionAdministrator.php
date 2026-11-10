<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Person;
use App\Models\RolePermissionVersion;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ProvisionAdministrator extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:provision
                            {--email= : Email address of the administrator}
                            {--name= : Full name of the administrator}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision a platform administrator account interactively.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Ensure RBAC seed data exists so the platform_admin role is available.
        $this->ensureRoleAndPermissionSeedData();

        // Resolve administrator email.
        $email = $this->option('email') ?? $this->ask('Administrator email address');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');
            return 1;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email '{$email}' already exists.");
            return 1;
        }

        // Resolve administrator name.
        $name = $this->option('name') ?? $this->ask('Administrator full name');

        if (trim($name) === '') {
            $this->error('Name is required.');
            return 1;
        }

        [$firstName, $lastName] = $this->splitName($name);

        // Generate a secure random password.
        $password = Str::random(20);

        $user = User::create([
            'email' => $email,
            'password' => Hash::make($password),
            'status' => 'active',
        ]);

        Person::create([
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'unspecified',
        ]);

        $platformAdminRole = SystemRole::where('code', 'platform_admin')->first();

        if (! $platformAdminRole) {
            $this->error('platform_admin role not found. Run php artisan db:seed first.');
            return 1;
        }

        // Pin the role's current published permission version to the
        // assignment so its effective permissions resolve even if the
        // getActiveAssignments() fallback path is not reached.
        $permissionVersion = $platformAdminRole->permissionVersions()
            ->whereNotNull('published_at')
            ->orderByDesc('version')
            ->first();

        UserRoleAssignment::create([
            'user_id' => $user->id,
            'system_role_id' => $platformAdminRole->id,
            'scope_type' => 'global',
            'role_permission_version_id' => $permissionVersion?->id,
            'valid_from' => null,
            'valid_until' => null,
            'granted_by_user_id' => null,
        ]);

        $this->newLine();
        $this->info('Platform administrator provisioned successfully.');
        $this->newLine();
        $this->info('Email:    ' . $email);
        $this->info('Password: ' . $password);
        $this->newLine();
        $this->warn('Save this password securely. It will not be shown again.');
        $this->newLine();

        return 0;
    }

    /**
     * Ensure RBAC reference data (permissions, roles, permission versions,
     * global delegations) exists so the platform_admin role can be assigned.
     */
    private function ensureRoleAndPermissionSeedData(): void
    {
        if (Permission::count() === 0) {
            $this->warn('No permissions found. Please run `php artisan db:seed` first.');
            return;
        }

        $platformAdminRole = SystemRole::where('code', 'platform_admin')->first();

        if (! $platformAdminRole) {
            $this->warn('platform_admin role not found. Please run `php artisan db:seed` first.');
            return;
        }

        // Ensure the platform_admin role has a published permission version
        // covering ALL permissions, so that administrator role assignments can
        // resolve their effective permissions even when no explicit version is
        // pinned to the assignment row.
        $currentVersion = $platformAdminRole->permissionVersions()
            ->whereNotNull('published_at')
            ->orderByDesc('version')
            ->first();

        $allPermissionIds = Permission::all()->pluck('id')->sort()->values()->all();
        $currentIds = $currentVersion?->permissions->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all() ?? [];

        if ($currentIds !== $allPermissionIds) {
            $versionNumber = (int) $platformAdminRole->permissionVersions()->max('version') + 1;
            $version = RolePermissionVersion::create([
                'system_role_id' => $platformAdminRole->id,
                'version' => $versionNumber,
                'change_reason' => 'Published during admin provisioning',
                'published_at' => null,
            ]);
            $version->permissions()->sync($allPermissionIds);
            $version->update(['published_at' => now()]);
            $platformAdminRole->update(['current_permission_version_id' => $version->id]);
        }

        // Ensure global delegations exist for all permissions so the new
        // administrator's role assignment is authorised.
        foreach (Permission::all() as $permission) {
            $exists = \Illuminate\Support\Facades\DB::table('permission_delegations')
                ->where('permission_id', $permission->id)
                ->where('scope_type', 'global')
                ->where('is_active', true)
                ->exists();

            if (! $exists) {
                $delegationId = \Illuminate\Support\Facades\DB::table('permission_delegations')->insertGetId([
                    'permission_id' => $permission->id,
                    'scope_type' => 'global',
                    'is_active' => true,
                    'reason' => 'Auto-created during admin provisioning',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (\Illuminate\Support\Facades\Schema::hasTable('permission_delegation_events')) {
                    \Illuminate\Support\Facades\DB::table('permission_delegation_events')->insert([
                        'permission_delegation_id' => $delegationId,
                        'action' => 'created',
                        'actor_user_id' => null,
                        'reason' => 'Auto-created during admin provisioning',
                        'created_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Split a full name into first and last name.
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [
            $parts[0] ?? '',
            $parts[1] ?? '',
        ];
    }
}
