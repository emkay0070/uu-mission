<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('resource_conversations')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant'])->comment('Message sender');
            $table->text('content')->comment('Message content');
            $table->integer('page_number')->nullable()->comment('Page context when message sent');
            $table->text('selected_text')->nullable()->comment('Text selected by user for context');
            $table->json('context_metadata')->nullable()->comment('Additional context: chapter, passage, etc.');
            $table->json('provider_metadata')->nullable()->comment('Provider, model, tokens, latency');
            $table->string('status', 20)->default('completed')->comment('pending, completed, failed');
            $table->text('error_message')->nullable()->comment('Error details if failed');
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_conversation_messages');
    }
};
