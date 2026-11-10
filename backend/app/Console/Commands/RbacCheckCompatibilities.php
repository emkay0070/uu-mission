<?php

namespace App\Console\Commands;

use App\Models\SystemRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Check and optionally repair role-scope compatibility records.
 *
 * Detects system roles that are missing expected role_scope_compatibilities
 * rows. Without these rows, UserRoleAssignmentPolicy denies every role
 * assignment with 403 "This action is unauthorized", even for users who
 * otherwise hold the required permission.
 *
 * Usage:
 *   php artisan rbac:check-compatibilities        # report only
 *   php artisan rbac:check-compatibilities --repair # report and repair
 */
class RbacCheckCompatibilities extends Command
{
    protected $signature = 'rbac:check-compatibilities
                            {--repair : Repair missing compatibility records}';

    protected $description = 'Check system roles for missing role-scope compatibility records';

    public function handle(): int
    {
        $missing = [];
        $checked = 0;

        foreach (SystemRole::orderBy('id')->get() as $role) {
            $checked++;
            $expected = SystemRole::scopeCompatibilities($role->scope_type);

            foreach ($expected as $scopeType) {
                $exists = DB::table('role_scope_compatibilities')
                    ->where('system_role_id', $role->id)
                    ->where('scope_type', $scopeType)
                    ->exists();

                if (! $exists) {
                    $missing[] = [
                        'role' => $role,
                        'scope_type' => $role->scope_type,
                        'missing_scope' => $scopeType,
                    ];
                }
            }
        }

        $this->info("Checked {$checked} system role(s).");

        if ($missing === []) {
            $this->info('All roles have their expected compatibility records.');

            return 0;
        }

        $this->warn('Found '.count($missing).' missing compatibility record(s):');

        $rows = [];
        foreach ($missing as $item) {
            $rows[] = [
                $item['role']->code,
                $item['scope_type'],
                $item['missing_scope'],
            ];
        }

        $this->table(
            ['Role code', 'Role scope', 'Missing compatibility scope'],
            $rows
        );

        if (! $this->option('repair')) {
            $this->warn('Re-run with --repair to insert missing records.');

            return 1;
        }

        foreach ($missing as $item) {
            $item['role']->syncScopeCompatibilities();
        }

        $this->info('Repaired '.count($missing).' missing compatibility record(s).');

        return 0;
    }
}
