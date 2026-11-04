<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguish PLANNED from UNPLANNED activities.
 *
 * A mission site runs two kinds of activity in practice:
 *
 *   planned   — agreed in advance by the site leadership, scheduled on the
 *               campaign programme. A secretary opening a register for such an
 *               activity MUST select the existing record (and inherit its
 *               objectives) rather than create a second one.
 *
 *   unplanned — arose on the day (an impromptu gathering, a visit that turned
 *               into a meeting). It has no prior record, so an authorised user
 *               creates it at the moment attendance is taken.
 *
 * Both are ordinary rows in `activities`; this flag is the single, explicit
 * fact that tells them apart. It is persisted (not inferred from timestamps or
 * status) because a planned activity that has since been completed is still,
 * historically, a planned activity — and reports must be able to say so.
 *
 * DEFAULT true: every activity authored through the existing planning screen is
 * planned. The unplanned flow must opt out explicitly, so an accidental omission
 * never silently downgrades a planned activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->boolean('is_planned')->default(true)->after('status')
                  ->comment('true = scheduled in advance; false = created ad hoc at attendance time');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('is_planned');
        });
    }
};
