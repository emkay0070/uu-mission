<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Org memberships — a person's formal relationship with an organizational unit.
 *
 * A person can be a member of multiple units (e.g. member of a local church
 * and also an associate at a district office).
 * ended_on = NULL means currently active membership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('org_unit_id')->constrained('org_units')->cascadeOnDelete();
            $table->string('membership_type', 30)->default('member')
                  ->comment('member | associate | partner');
            $table->date('joined_on')->nullable();
            $table->date('ended_on')->nullable()
                  ->comment('NULL = currently active');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['person_id', 'org_unit_id']);
            $table->index(['org_unit_id', 'membership_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_memberships');
    }
};
