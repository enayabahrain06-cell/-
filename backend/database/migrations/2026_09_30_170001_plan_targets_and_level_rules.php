<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U4: a Quran plan item can carry how many ayahs the week should add; the level's Quran plan then drives
 *     "behind plan" (the student's own yearly target still wins; the package's plan_ayahs is the fallback).
 * U6: a level can state who belongs in it (age range, memorization levels) — used by توزيع المستويات.
 *     Memorization level, age group and level stay three separate things.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_items', function (Blueprint $table) {
            $table->unsignedInteger('target_ayahs')->nullable();
        });

        Schema::table('levels', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->text('memorization_levels')->nullable(); // JSON list of MemorizationLevel values; null = any
        });
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->dropColumn(['min_age', 'max_age', 'memorization_levels']);
        });

        Schema::table('plan_items', function (Blueprint $table) {
            $table->dropColumn('target_ayahs');
        });
    }
};
