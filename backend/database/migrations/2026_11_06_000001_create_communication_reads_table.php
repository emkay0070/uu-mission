<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication reads — per-user read and acknowledgement tracking.
 *
 * One row per (communication_id, user_id), created lazily when the user
 * first reads or acknowledges a communication. created_at is when the
 * personal tracking record was created — it is NOT a delivery register.
 * read_at and acknowledged_at are immutable after first assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('communication_id')
                ->constrained('communications')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['communication_id', 'user_id'], 'comm_reads_unique');
            $table->index('user_id');
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_reads');
    }
};
