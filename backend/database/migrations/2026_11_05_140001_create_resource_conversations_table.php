<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->cascadeOnDelete();

            $table->string('title')->nullable()->comment('Auto-generated or user-defined conversation title');
            $table->timestamp('last_message_at')->nullable()->comment('For sorting conversations');

            $table->timestamps();

            // Note: No unique constraint on (user_id, resource_id, resource_version_id)
            // Users can create multiple conversations for the same book/version

            $table->index('user_id');
            $table->index('resource_id');
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_conversations');
    }
};
