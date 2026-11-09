<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_learning_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('resource_version_id')->constrained('resource_versions')->cascadeOnDelete();
            $table->foreignId('saved_explanation_id')->nullable()->constrained('resource_saved_explanations')->nullOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained('resource_conversation_messages')->nullOnDelete();

            $table->enum('note_type', ['understanding', 'reflection', 'chapter_summary', 'book_summary', 'important_passage', 'question'])->comment('Type of learning note');
            $table->string('title')->comment('Note title');
            $table->text('content')->comment('Note content in user\'s own words');
            $table->string('chapter')->nullable()->comment('Chapter anchor if applicable');
            $table->integer('page_number')->nullable()->comment('Page anchor');
            $table->text('passage_text')->nullable()->comment('Passage anchor text');
            $table->json('passage_position')->nullable()->comment('Passage coordinates for highlighting');

            $table->timestamps();

            $table->index('user_id');
            $table->index('resource_id');
            $table->index('note_type');
            $table->index('chapter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_learning_notes');
    }
};
