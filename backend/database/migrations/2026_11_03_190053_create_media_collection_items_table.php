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
        Schema::create('media_collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_collection_id')->constrained('media_collections')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0)->comment('Display order within collection');
            $table->timestamps();
            
            $table->unique(['media_collection_id', 'media_id'], 'unique_collection_media');
            $table->index('media_collection_id');
            $table->index('media_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_collection_items');
    }
};
