<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_stage_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->onDelete('cascade');
            $table->enum('from_stage', ['new_contact', 'interested', 'studying', 'decision_made', 'baptism_candidate', 'baptized', 'member', 'inactive', 'lost_contact'])->nullable();
            $table->enum('to_stage', ['new_contact', 'interested', 'studying', 'decision_made', 'baptism_candidate', 'baptized', 'member', 'inactive', 'lost_contact']);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->index(['person_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_stage_histories');
    }
};
