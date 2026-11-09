<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_saved', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('resource_version_id')->nullable()->constrained('resource_versions')->nullOnDelete();

            $table->timestamp('saved_at')->useCurrent()->comment('When the user saved this resource');

            $table->timestamps();

            // Unique constraint on (user_id, resource_id) - one save per resource per user
            $table->unique(['user_id', 'resource_id'], 'unique_saved_per_user_resource');

            $table->index('user_id');
            $table->index('resource_id');
            $table->index('saved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_saved');
    }
};
