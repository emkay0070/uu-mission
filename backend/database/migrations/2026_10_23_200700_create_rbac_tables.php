<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC — system roles, permissions and their pivot.
 *
 * scope_type on system_roles indicates what kind of scope this role applies to.
 * This is a hint for UI and validation — actual enforcement happens in
 * user_role_assignments via explicit scope FK columns (Correction 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Reusable role definitions
        Schema::create('system_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique()
                  ->comment('e.g. platform_admin | campaign_leader | site_secretary | missionary');
            $table->string('label', 150);
            $table->string('scope_type', 20)->default('campaign')
                  ->comment('global | org_unit | campaign | site — indicates natural scope');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        // Individual capability codes
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique()
                  ->comment('e.g. attendance.submit | report.view | campaign.create');
            $table->string('label', 200);
            $table->string('module', 60)
                  ->comment('e.g. attendance | campaigns | reporting | admin');
            $table->timestamp('created_at')->useCurrent();

            $table->index('module');
        });

        // Which permissions belong to which roles
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('system_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('system_roles');
    }
};
