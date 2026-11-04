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
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('cover_photo_media_id')->nullable()->after('media_id')->constrained('media')->nullOnDelete();
            $table->index('cover_photo_media_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['cover_photo_media_id']);
            $table->dropIndex(['cover_photo_media_id']);
            $table->dropColumn('cover_photo_media_id');
        });
    }
};
