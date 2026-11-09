<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permission_delegation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_delegation_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('role_scope_compatibility_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('scope_types');
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE user_role_assignments ADD CONSTRAINT ura_status_check CHECK (status IN ('active','suspended','revoked'))");
            DB::statement("ALTER TABLE user_role_assignment_overrides ADD CONSTRAINT ura_override_effect_check CHECK (effect IN ('grant','deny'))");
            DB::statement("ALTER TABLE permission_delegations ADD CONSTRAINT permission_delegation_scope_check CHECK (
                (scope_type = 'global' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL) OR
                (scope_type = 'org_unit' AND scoped_org_unit_id IS NOT NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL) OR
                (scope_type = 'campaign' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NOT NULL AND scoped_site_id IS NULL AND scoped_team_id IS NULL) OR
                (scope_type = 'site' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NOT NULL AND scoped_team_id IS NULL) OR
                (scope_type = 'team' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL AND scoped_team_id IS NOT NULL)
            )");
        }

        // Publish the system default as a new version. Existing assignments stay
        // pinned to their prior version until an authorized audited update.
        $role = DB::table('system_roles')->where('code', 'missionary')->first();
        $createPermission = DB::table('permissions')->where('code', 'resources.create')->first();
        if ($role && $createPermission) {
            $currentVersionId = $role->current_permission_version_id;
            $alreadyGranted = $currentVersionId && DB::table('role_permission_version_items')
                ->where('role_permission_version_id', $currentVersionId)
                ->where('permission_id', $createPermission->id)->exists();
            if (! $alreadyGranted) {
                $version = (int) DB::table('role_permission_versions')->where('system_role_id', $role->id)->max('version') + 1;
                $newId = DB::table('role_permission_versions')->insertGetId([
                    'system_role_id' => $role->id, 'version' => $version,
                    'change_reason' => 'Missionary default includes resources.view and resources.create',
                    'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($currentVersionId) {
                    DB::table('role_permission_version_items')->insertUsing(
                        ['role_permission_version_id', 'permission_id'],
                        DB::table('role_permission_version_items')->selectRaw('? as role_permission_version_id, permission_id', [$newId])->where('role_permission_version_id', $currentVersionId)
                    );
                }
                DB::table('role_permission_version_items')->insertOrIgnore(['role_permission_version_id' => $newId, 'permission_id' => $createPermission->id]);
                DB::table('system_roles')->where('id', $role->id)->update(['current_permission_version_id' => $newId]);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $createPermission->id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_scope_compatibility_events');
        Schema::dropIfExists('permission_delegation_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE user_role_assignments DROP CONSTRAINT IF EXISTS ura_status_check');
            DB::statement('ALTER TABLE user_role_assignment_overrides DROP CONSTRAINT IF EXISTS ura_override_effect_check');
            DB::statement('ALTER TABLE permission_delegations DROP CONSTRAINT IF EXISTS permission_delegation_scope_check');
        }
    }
};
