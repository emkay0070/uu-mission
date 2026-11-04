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
        Schema::create('media_collections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('External identifier');
            $table->string('name')->comment('Collection name');
            $table->text('description')->nullable()->comment('Collection description');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('visibility', ['private', 'organisational', 'public'])->default('private');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('created_by_user_id');
            $table->index('visibility');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_collections');
    }
};
