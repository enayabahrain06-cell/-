<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('name_ar', 150)->nullable();
            $table->string('name_en', 150)->nullable();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('min_age');
            $table->unsignedTinyInteger('max_age');
            $table->string('gender', 10)->index();
            $table->unsignedInteger('seats');
            $table->unsignedInteger('price_fils')->default(0);
            $table->text('days'); // JSON list of weekday keys, e.g. ["sat","mon","wed"]
            $table->time('start_time');
            $table->time('end_time');
            $table->date('start_date')->index();
            $table->date('end_date')->nullable();
            $table->string('term', 60)->nullable();
            $table->unsignedInteger('plan_ayahs')->default(0);
            $table->string('status', 32)->default('draft')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
