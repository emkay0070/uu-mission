<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_memberships', function (Blueprint $table) {
            // Basic membership tracking
            $table->string('membership_number', 50)->nullable()->comment('Church membership number');
            $table->date('baptism_date')->nullable();
            $table->string('baptism_location', 200)->nullable();
            
            // Approval workflow
            $table->string('approval_status', 20)->default('pending')->comment('pending | approved | rejected')->after('membership_type');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('org_memberships', function (Blueprint $table) {
            $table->dropForeign(['approved_by_user_id']);
            $table->dropColumn([
                'membership_number',
                'baptism_date',
                'baptism_location',
                'approval_status',
                'approved_by_user_id',
                'approved_at',
                'rejection_reason',
            ]);
        });
    }
};
