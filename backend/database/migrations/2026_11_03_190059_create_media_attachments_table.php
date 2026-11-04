<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            
            // Polymorphic relationship to attachable entities
            $table->morphs('attachable');
            
            $table->foreignId('attached_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('attachment_type')->default('general')->comment('Type of attachment (profile, cover, documentation, etc.)');
            $table->timestamps();
            
            // Ensure same media isn't attached to same entity twice
            $table->unique(['media_id', 'attachable_type', 'attachable_id', 'attachment_type'], 'unique_media_attachment');
            
            $table->index('media_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
    }
};
