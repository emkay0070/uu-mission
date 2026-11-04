<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attendance registers — occurrence-accurate uniqueness.
 *
 * WHY THIS MIGRATION EXISTS
 * -------------------------
 * The original constraint was a single unique index on
 *   (mission_site_id, register_date, register_type, register_name)
 * which ignored activity_id entirely. Two consequences followed:
 *
 *   a. Two DIFFERENT activities held on the same day at the same site could not
 *      each have their own register ("Morning Service" twice in one day).
 *   b. Nothing prevented the same activity from being opened twice on the same
 *      date under a different register_type, because activity_id was not part
 *      of the key at all.
 *
 * A register is an OCCURRENCE. Where it belongs to an activity, that activity
 * and the date are the real identity of the occurrence. Where it does not (a
 * standalone roll call), the site/date/type/name tuple is the identity.
 *
 * Two PARTIAL unique indexes express exactly that, and neither can be bypassed
 * by controller validation alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE attendance_registers DROP CONSTRAINT IF EXISTS ar_unique_register');

        // Activity-linked occurrence: one register per activity per date per name.
        DB::statement("
            CREATE UNIQUE INDEX ar_unique_activity_register
            ON attendance_registers (activity_id, register_date, register_name)
            WHERE activity_id IS NOT NULL
        ");

        // Standalone occurrence: one register per site/date/type/name.
        DB::statement("
            CREATE UNIQUE INDEX ar_unique_standalone_register
            ON attendance_registers (mission_site_id, register_date, register_type, register_name)
            WHERE activity_id IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ar_unique_activity_register');
        DB::statement('DROP INDEX IF EXISTS ar_unique_standalone_register');

        DB::statement('
            ALTER TABLE attendance_registers
            ADD CONSTRAINT ar_unique_register
            UNIQUE (mission_site_id, register_date, register_type, register_name)
        ');
    }
};
