<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance entries — individual attendance records within a register.
 *
 * SEPARATE MIGRATION FROM REGISTERS (Correction 3):
 * One entry = one person present at one register session.
 * Entries depend on registers existing, so this migration runs after.
 *
 * attendee_type distinguishes between missionaries (whose profiles are tracked
 * in this system) and community members (walk-ins whose details may be captured
 * ad hoc as a name + phone, without a full people record).
 *
 * For missionaries: person_id is set, visitor_name/phone are null.
 * For community visitors: person_id is null, visitor_name is captured.
 *
 * A CHECK constraint enforces that at least one identifier is always present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_register_id')
                  ->constrained('attendance_registers')
                  ->cascadeOnDelete();
            $table->string('attendee_type', 20)->default('missionary')
                  ->comment('missionary | visitor | local_member');
            $table->foreignId('person_id')
                  ->nullable()
                  ->constrained('people')
                  ->nullOnDelete();
            // Used when person_id is null (walk-in community member)
            $table->string('visitor_name', 200)->nullable();
            $table->string('visitor_phone', 30)->nullable();
            $table->string('visitor_gender', 20)->nullable()
                  ->comment('male | female | unspecified');
            $table->boolean('is_first_time')->default(false);
            $table->string('decision_made', 60)->nullable()
                  ->comment('accepted_christ | requested_bible_study | joined_church | none');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('attendance_register_id');
            $table->index(['attendance_register_id', 'attendee_type']);
            $table->index('person_id');
        });

        // At least one identifier must be present
        DB::statement("
            ALTER TABLE attendance_entries
            ADD CONSTRAINT chk_ae_identifier CHECK (
                person_id IS NOT NULL OR visitor_name IS NOT NULL
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_entries');
    }
};
