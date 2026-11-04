<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team types — the configurable taxonomy for team classification (Correction 4).
 *
 * PROBLEM SOLVED: Teams in a campaign have two independent dimensions:
 *   - ORIGIN: where do the team members come from? (Central Uganda Conference, UU, local church)
 *   - FUNCTION: what does the team do? (Outreach, Secretariat, Communications, Welfare)
 *
 * These are NOT the same concept. A "Central Uganda Conference Outreach Team" has
 * both an origin (CUC) AND a function (Outreach). Neither overrides the other.
 *
 * SOLUTION: team_types classify by functional dimension.
 *            teams.origin_org_unit_id captures the organizational origin separately.
 * Both are optional — a team can be purely functional with no specific org origin,
 * or origin-only (e.g. a conference delegation team with no specific functional role).
 *
 * dimension = 'origin' | 'function' | 'ministry' — indicates what the type describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique()
                  ->comment('e.g. outreach | secretariat | communications | leadership | welfare | media');
            $table->string('label', 150);
            $table->string('dimension', 20)->default('function')
                  ->comment('origin | function | ministry');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_types');
    }
};
