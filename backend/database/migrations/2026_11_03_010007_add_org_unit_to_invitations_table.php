<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            // Organisational context for invitation
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete()->after('person_id');
            $table->string('membership_type', 30)->nullable()->comment('member | associate | partner')->after('org_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropForeign(['org_unit_id']);
            $table->dropColumn(['org_unit_id', 'membership_type']);
        });
    }
};
