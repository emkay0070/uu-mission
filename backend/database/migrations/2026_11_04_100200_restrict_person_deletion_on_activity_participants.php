<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Activity participants — protect historical participation on person removal.
 *
 * WHY THIS MIGRATION EXISTS
 * -------------------------
 * activity_participants.person_id was declared ON DELETE CASCADE. A person is
 * meant to be removed only by SOFT deletion (people.deleted_at), but any hard
 * delete — a maintenance script, a cascade from another table, a future admin
 * tool — would have silently destroyed every planned-roster record that person
 * ever had, including on activities that are already completed.
 *
 * The planned roster IS a historical record ("this person was on the roster for
 * the Kato crusade"). It must outlive an administrative removal of the person,
 * exactly as attendance entries do.
 *
 * ON DELETE RESTRICT makes the protection explicit at the database level: the
 * database refuses the removal instead of quietly discarding mission history.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE activity_participants DROP CONSTRAINT IF EXISTS activity_participants_person_id_foreign');

        DB::statement('
            ALTER TABLE activity_participants
            ADD CONSTRAINT ap_person_id_foreign
            FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE RESTRICT
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE activity_participants DROP CONSTRAINT IF EXISTS ap_person_id_foreign');

        DB::statement('
            ALTER TABLE activity_participants
            ADD CONSTRAINT activity_participants_person_id_foreign
            FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE
        ');
    }
};
