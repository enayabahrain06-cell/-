<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الليالي: the weekdays (nights) the centre runs, with default start and end times. One row per weekday,
 * seeded here; staff switch nights on or off and set their times. Term setup offers only active nights.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nights', function (Blueprint $table) {
            $table->id();
            $table->string('weekday', 3)->unique();
            $table->boolean('is_active')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach (['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'] as $i => $day) {
            DB::table('nights')->insert(['weekday' => $day, 'is_active' => true, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nights');
    }
};
