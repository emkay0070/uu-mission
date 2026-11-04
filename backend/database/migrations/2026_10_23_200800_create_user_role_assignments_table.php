<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User role assignments — scope-safe authorization (Correction 1).
 *
 * PROBLEM SOLVED: A single polymorphic `scope_id` cannot have a real FK
 * to multiple tables, making it impossible to enforce referential integrity
 * or write efficient indexed queries.
 *
 * SOLUTION: Three explicit nullable FK columns — one per scope entity type.
 * Exactly one of these (or none, for global scope) must be set.
 * A CHECK constraint enforces mutual exclusivity at the database level.
 *
 * This guarantees that:
 *  - A site_secretary at Site 7 CANNOT access Site 8 — the DB has no record.
 *  - A campaign_leader at Campaign A CANNOT see Campaign B's restricted data.
 *  - A union_admin scoped to org_unit:Uganda_Union CAN see all child org units
 *    (hierarchy traversal is resolved in the AuthorizationService, not schema).
 *
 * valid_from / valid_until support time-bounded role grants (e.g. for a campaign).
 * granted_by_user_id provides an audit trail for every role assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('system_role_id')->constrained('system_roles')->cascadeOnDelete();

            // Scope type label — drives application-layer hierarchy resolution
            $table->string('scope_type', 20)
                  ->comment('global | org_unit | campaign | site');

            // Explicit FK columns — only one is non-null per assignment (enforced below)
            $table->foreignId('scoped_org_unit_id')
                  ->nullable()
                  ->constrained('org_units')
                  ->nullOnDelete();
            // Note: campaign and site FKs are added after those tables exist (see later migrations)
            // They are added as deferred columns to maintain migration dependency order.
            // For now they are plain nullable bigints; FKs are added in 2026_10_23_201200.

            $table->unsignedBigInteger('scoped_campaign_id')->nullable();
            $table->unsignedBigInteger('scoped_site_id')->nullable();

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('granted_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            // Prevent duplicate assignments for the same scope
            $table->unique(['user_id', 'system_role_id', 'scoped_org_unit_id', 'scoped_campaign_id', 'scoped_site_id'],
                           'ura_unique_assignment');

            $table->index(['user_id', 'scope_type']);
            $table->index(['scope_type', 'scoped_org_unit_id']);
            $table->index(['scope_type', 'scoped_campaign_id']);
            $table->index(['scope_type', 'scoped_site_id']);
        });

        // CHECK: for non-global scope, exactly one of the scope FK columns must be set
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE user_role_assignments
                ADD CONSTRAINT chk_ura_scope_exclusive CHECK (
                    (scope_type = 'global' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL)
                    OR
                    (scope_type = 'org_unit' AND scoped_org_unit_id IS NOT NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NULL)
                    OR
                    (scope_type = 'campaign' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NOT NULL AND scoped_site_id IS NULL)
                    OR
                    (scope_type = 'site' AND scoped_org_unit_id IS NULL AND scoped_campaign_id IS NULL AND scoped_site_id IS NOT NULL)
                )
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_assignments');
    }
};
