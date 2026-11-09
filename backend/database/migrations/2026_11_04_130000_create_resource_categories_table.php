<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('e.g. bible_studies, evangelism, mission_training');
            $table->string('label', 150);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0)->comment('Lower = higher in display order');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index('code');
            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_categories');
    }
};
