<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance entries — explicit identity states + idempotency key.
 *
 * WHY THIS MIGRATION EXISTS
 * -------------------------
 * An attendance entry records that SOMEBODY was present. That somebody may be:
 *
 *   1. Identified              — person_id points at a permanent Person record.
 *   2. Named but unidentified  — visitor_name only ("Grace from the next village").
 *                                No Person record is invented for them.
 *   3. Anonymous               — neither name nor Person. A head was counted,
 *                                nothing more is known. is_anonymous = true.
 *
 * The previous CHECK (person_id OR visitor_name) made state 3 impossible, which
 * forced callers to fabricate placeholder names. It is replaced below.
 *
 * is_walk_in is a HISTORICAL SNAPSHOT taken when the row is written: it states
 * that the attendee was not on the planned roster (activity_participants) at
 * that moment. It is deliberately NOT derived from current roster membership,
 * because rosters change after the event and the register must keep telling
 * what actually happened on the day.
 *
 * entry_uuid is the client-generated idempotency key. The client mints a UUID
 * per attendance row so that a retried request updates the same row instead of
 * creating a duplicate. It is the ONLY safe identity for anonymous rows (a name
 * or phone number is neither unique nor guaranteed to be supplied).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_entries', function (Blueprint $table) {
            // Idempotency key — supplied by the client, backfilled below for any
            // pre-existing development rows.
            $table->uuid('entry_uuid')->nullable()->after('attendance_register_id');
            // Historical snapshot: attendee was not on the planned roster.
            $table->boolean('is_walk_in')->default(false)->after('person_id');
            // Explicit "no identity known" flag — never inferred from nulls.
            $table->boolean('is_anonymous')->default(false)->after('is_walk_in');
        });

        // Backfill a UUID for rows created before this column existed, then make
        // the column mandatory. gen_random_uuid() is core in PostgreSQL 13+.
        DB::statement('UPDATE attendance_entries SET entry_uuid = gen_random_uuid() WHERE entry_uuid IS NULL');
        DB::statement('ALTER TABLE attendance_entries ALTER COLUMN entry_uuid SET NOT NULL');
        DB::statement('ALTER TABLE attendance_entries ADD CONSTRAINT ae_entry_uuid_unique UNIQUE (entry_uuid)');

        // ── Identity constraints ──────────────────────────────────────────────
        // The old constraint is dropped by its historical name.
        DB::statement('ALTER TABLE attendance_entries DROP CONSTRAINT IF EXISTS chk_ae_identifier');

        // At least one identity statement must exist: a person, a name, or an
        // explicit declaration that the attendee is anonymous.
        DB::statement("
            ALTER TABLE attendance_entries
            ADD CONSTRAINT chk_ae_identifier CHECK (
                person_id IS NOT NULL
                OR visitor_name IS NOT NULL
                OR is_anonymous = true
            )
        ");

        // Anonymity is a positive statement, not the absence of data: a row may
        // only be flagged anonymous when it genuinely carries no identity, and
        // any row carrying an identity must NOT be flagged anonymous. This is
        // what separates a deliberate anonymous count from an incomplete row.
        DB::statement("
            ALTER TABLE attendance_entries
            ADD CONSTRAINT chk_ae_anonymous CHECK (
                is_anonymous = (person_id IS NULL AND visitor_name IS NULL)
            )
        ");

        // ── Classification becomes optional ───────────────────────────────────
        // attendee_type (missionary | visitor | local_member) is recorded only
        // when it is actually known. Forcing every attendance row to be stamped
        // 'missionary' previously mislabelled walk-ins and community members.
        DB::statement('ALTER TABLE attendance_entries ALTER COLUMN attendee_type DROP DEFAULT');
        DB::statement('ALTER TABLE attendance_entries ALTER COLUMN attendee_type DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attendance_entries DROP CONSTRAINT IF EXISTS chk_ae_anonymous');
        DB::statement('ALTER TABLE attendance_entries DROP CONSTRAINT IF EXISTS chk_ae_identifier');
        DB::statement('ALTER TABLE attendance_entries DROP CONSTRAINT IF EXISTS ae_entry_uuid_unique');

        DB::statement("
            ALTER TABLE attendance_entries
            ADD CONSTRAINT chk_ae_identifier CHECK (
                person_id IS NOT NULL OR visitor_name IS NOT NULL
            )
        ");

        DB::statement("ALTER TABLE attendance_entries ALTER COLUMN attendee_type SET DEFAULT 'missionary'");

        Schema::table('attendance_entries', function (Blueprint $table) {
            $table->dropColumn(['entry_uuid', 'is_walk_in', 'is_anonymous']);
        });
    }
};
