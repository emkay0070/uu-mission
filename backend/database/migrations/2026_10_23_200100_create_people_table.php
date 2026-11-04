<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People — the human being behind every platform interaction.
 *
 * Decoupled from users: a person can exist without an account (registered
 * by a leader, invited later). One user maps to at most one person.
 * Emergency contact is stored encrypted at the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->nullable()
                  ->unique()                          // one person per account
                  ->constrained('users')
                  ->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('gender', 20)->nullable()
                  ->comment('male | female | unspecified');
            $table->date('date_of_birth')->nullable();
            $table->char('nationality', 2)->nullable()
                  ->comment('ISO 3166-1 alpha-2');
            $table->string('phone', 30)->nullable()
                  ->comment('E.164 format');
            $table->string('photo_path')->nullable();
            // Encrypted at app layer before storage — never stored plaintext
            $table->text('emergency_contact_encrypted')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('last_name');
            $table->index(['first_name', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
