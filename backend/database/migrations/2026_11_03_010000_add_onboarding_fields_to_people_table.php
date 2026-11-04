<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // Personal identity extensions
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('preferred_name', 100)->nullable()->after('last_name');

            // Contact information (separate from User.email)
            $table->string('email', 255)->nullable()->after('phone');
            $table->string('whatsapp_phone', 30)->nullable()->after('phone');
            $table->string('alternative_phone', 30)->nullable()->after('phone');
            $table->string('preferred_contact_method', 20)->nullable()->comment('phone | whatsapp | email')->after('alternative_phone');

            // Emergency contact (structured)
            $table->string('emergency_contact_name', 100)->nullable();
            $table->string('emergency_contact_relationship', 50)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();

            // Professional
            $table->string('occupation', 200)->nullable();

            // Residence (basic)
            $table->text('address')->nullable()->comment('Physical address or directions');

            // National ID (optional, access-controlled)
            $table->string('national_id_type', 50)->nullable()->comment('passport | national_id');
            $table->string('national_id_number', 50)->nullable();

            // Add indexes
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropColumn([
                'middle_name',
                'preferred_name',
                'email',
                'whatsapp_phone',
                'alternative_phone',
                'preferred_contact_method',
                'emergency_contact_name',
                'emergency_contact_relationship',
                'emergency_contact_phone',
                'occupation',
                'address',
                'national_id_type',
                'national_id_number',
            ]);
        });
    }
};
