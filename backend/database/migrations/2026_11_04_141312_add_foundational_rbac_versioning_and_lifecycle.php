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
        Schema::create('role_permission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_role_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['system_role_id', 'version']);
        });
        Schema::create('role_permission_version_items', function (Blueprint $table) {
            $table->foreignId('role_permission_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['role_permission_version_id', 'permission_id']);
        });
        Schema::table('system_roles', function (Blueprint $table) {
            $table->foreignId('current_permission_version_id')->nullable()->constrained('role_permission_versions')->nullOnDelete();
        });
        Schema::create('role_scope_compatibilities', function (Blueprint $table) {
            $table->foreignId('system_role_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 20);
            $table->primary(['system_role_id', 'scope_type']);
        });
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->foreignId('role_permission_version_id')->nullable()->constrained('role_permission_versions')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->foreignId('suspended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
        });
        Schema::create('user_role_assignment_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_role_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->string('effect', 10);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->unique(['user_role_assignment_id', 'permission_id']);
        });
        Schema::create('user_role_assignment_override_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_role_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->string('effect', 10)->nullable();
            $table->string('action', 10);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('permission_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->string('scope_type', 20);
            $table->foreignId('scoped_org_unit_id')->nullable()->constrained('org_units')->cascadeOnDelete();
            $table->unsignedBigInteger('scoped_campaign_id')->nullable();
            $table->unsignedBigInteger('scoped_site_id')->nullable();
            $table->unsignedBigInteger('scoped_team_id')->nullable();
            $table->foreignId('delegated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['permission_id', 'scope_type', 'is_active']);
        });
        Schema::create('user_role_assignment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_role_assignment_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Freeze each existing role's live defaults as its initial immutable set.
        foreach (DB::table('system_roles')->orderBy('id')->get() as $role) {
            $versionId = DB::table('role_permission_versions')->insertGetId([
                'system_role_id' => $role->id, 'version' => 1,
                'change_reason' => 'Initial version frozen from existing role defaults',
                'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_permission_version_items')->insertUsing(
                ['role_permission_version_id', 'permission_id'],
                DB::table('role_permissions')->selectRaw('? as role_permission_version_id, permission_id', [$versionId])->where('role_id', $role->id)
            );
            DB::table('system_roles')->where('id', $role->id)->update(['current_permission_version_id' => $versionId]);
            $compatible = match ($role->scope_type) {
                'global' => ['global'],
                'org_unit' => ['org_unit'],
                'campaign' => ['org_unit', 'campaign'],
                'site' => ['org_unit', 'campaign', 'site'],
                'team' => ['org_unit', 'campaign', 'site', 'team'],
                default => [$role->scope_type],
            };
            foreach ($compatible as $scopeType) {
                DB::table('role_scope_compatibilities')->insert(['system_role_id' => $role->id, 'scope_type' => $scopeType]);
            }
        }
        DB::table('user_role_assignments')->update(['role_permission_version_id' => DB::raw('(select current_permission_version_id from system_roles where system_roles.id = user_role_assignments.system_role_id)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_role_assignment_events');
        Schema::dropIfExists('permission_delegations');
        Schema::dropIfExists('user_role_assignment_override_events');
        Schema::dropIfExists('user_role_assignment_overrides');
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_permission_version_id');
            $table->dropConstrainedForeignId('revoked_by_user_id');
            $table->dropConstrainedForeignId('suspended_by_user_id');
            $table->dropColumn(['status', 'revoked_at', 'revocation_reason', 'suspended_at', 'suspension_reason']);
        });
        Schema::dropIfExists('role_scope_compatibilities');
        Schema::table('system_roles', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_permission_version_id'));
        Schema::dropIfExists('role_permission_version_items');
        Schema::dropIfExists('role_permission_versions');
    }
};
