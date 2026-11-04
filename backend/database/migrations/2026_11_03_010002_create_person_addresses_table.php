<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('country', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('district', 100)->nullable()->comment('Geographical district');
            $table->string('county_municipality', 100)->nullable();
            $table->string('parish_town', 100)->nullable();
            $table->string('village_area', 200)->nullable();
            $table->text('physical_address')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            
            $table->index(['person_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_addresses');
    }
};
