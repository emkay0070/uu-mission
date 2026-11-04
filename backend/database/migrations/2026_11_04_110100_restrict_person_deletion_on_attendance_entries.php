<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attendance history must survive the deletion of a Person.
 *
 * THE PROBLEM
 * -----------
 * attendance_entries.person_id was created with `nullOnDelete()`. Deleting a
 * Person therefore sets the referenced attendance rows' person_id to NULL.
 *
 * But an attendance row is required by CHECK constraints (chk_ae_identifier,
 * chk_ae_anonymous) to carry exactly one identity form:
 *
 *     identified             person_id set
 *     named but unidentified visitor_name set, person_id null
 *     anonymous              is_anonymous = true, person_id AND visitor_name null
 *
 * An IDENTIFIED row has visitor_name = NULL and is_anonymous = false. Setting
 * its person_id to NULL leaves it with no identity at all:
 *   - chk_ae_identifier fails (person_id, visitor_name and is_anonymous all empty), and
 *   - chk_ae_anonymous fails (is_anonymous is false while person_id/visitor_name are NULL).
 *
 * The result is not a graceful refusal: the DELETE raises a raw constraint
 * violation, and — worse — the historical attendance record is left in an
 * inconsistent, half-destroyed state.
 *
 * THE POLICY
 * ----------
 * People are SOFT-deleted (Person uses the SoftDeletes trait); a person record
 * is never routinely hard-deleted. Historical attendance, roster and encounter
 * rows must remain readable afterwards.
 *
 * RESTRICT makes that policy explicit at the database level: while a Person is
 * referenced by attendance history, the database refuses to hard-delete them.
 * Soft deletion is unaffected — it only sets people.deleted_at, which is not a
 * DELETE and so is never blocked by this foreign key.
 *
 * This mirrors the identical decision already taken for activity_participants
 * in 2026_11_04_100200_restrict_person_deletion_on_activity_participants.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the SET NULL behaviour and replace it with RESTRICT. The
        // constraint keeps its Laravel-generated name so nothing else needs to
        // change.
        DB::statement('ALTER TABLE attendance_entries DROP CONSTRAINT IF EXISTS attendance_entries_person_id_foreign');

        DB::statement('
            ALTER TABLE attendance_entries
            ADD CONSTRAINT attendance_entries_person_id_foreign
            FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attendance_entries DROP CONSTRAINT IF EXISTS attendance_entries_person_id_foreign');

        DB::statement('
            ALTER TABLE attendance_entries
            ADD CONSTRAINT attendance_entries_person_id_foreign
            FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL
        ');
    }
};
