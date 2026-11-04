<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the core authentication tables.
 *
 * Uses Laravel's conventional `password` column throughout — no divergence.
 * Status column enforces account lifecycle: pending → active → suspended/deactivated.
 * Timezone defaults to Africa/Kampala for all UU users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');    // Laravel convention — bcrypt via Hash::make()
            $table->rememberToken();
            $table->string('status', 20)->default('pending')
                  ->comment('pending | active | suspended | deactivated');
            $table->string('timezone', 60)->default('Africa/Kampala');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
