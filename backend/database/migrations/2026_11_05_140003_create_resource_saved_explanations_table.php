<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_saved_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained('resource_conversation_messages')->nullOnDelete();

            $table->string('title')->comment('User-defined title for saved explanation');
            $table->text('content')->comment('The AI explanation content (snapshot)');
            $table->integer('page_number')->nullable()->comment('Page reference');
            $table->text('selected_text')->nullable()->comment('Original passage being explained');

            $table->timestamps();

            // Unique constraint to prevent duplicate saves of same message for same user
            $table->unique(['user_id', 'conversation_message_id'], 'unique_saved_explanation_per_message');

            $table->index('user_id');
            $table->index('resource_id');
            $table->index('conversation_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_saved_explanations');
    }
};
