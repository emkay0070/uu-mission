<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity objectives — what a single field activity intends to achieve.
 *
 * WHY A SEPARATE TABLE
 * --------------------
 * An activity (a programme actually conducted at a mission site — liquid soap
 * making, family life education, health demonstration, evangelistic preaching)
 * can declare SEVERAL things it sets out to accomplish. Campages already model
 * this with `campaign_objectives`; activities now mirror that shape so an
 * activity is not limited to a single free-text "objective" column.
 *
 * This is deliberately NOT a workflow engine. Objectives are lightweight
 * statements of intent: a title, an optional description and a display order.
 * They carry no status machine and no approval lifecycle, because the thing
 * that records achievement is a Mission Outcome (a measured result), not the
 * objective itself.
 *
 * Objectives are read in context: when a secretary opens (or creates) an
 * activity's attendance register the activity's objectives travel with it, so
 * the register is taken against a stated purpose rather than in a vacuum. They
 * are also surfaced while recording outcomes and preparing reports.
 *
 * CASCADE: objectives are owned by the activity and have no meaning without it,
 * so deleting the activity removes them. Activity history itself is protected
 * elsewhere (ActivityController::destroy refuses while registers/outcomes exist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->string('title', 250);
            $table->text('description')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('activity_id');
            $table->index(['activity_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_objectives');
    }
};
