<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('tag', 100)->comment('Keyword or tag for categorization');
            $table->timestamp('created_at')->useCurrent();

            $table->index('resource_id');
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_tags');
    }
};
