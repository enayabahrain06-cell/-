<?php

use App\Support\Quran;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reference table: 114 surahs, seeded here once so every environment (and every test run) has it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quran_surahs', function (Blueprint $table) {
            $table->unsignedTinyInteger('number')->primary();
            $table->string('name_ar', 40);
            $table->string('name_en', 40);
            $table->unsignedSmallInteger('ayah_count');
            $table->unsignedTinyInteger('juz_start')->index();
        });

        DB::table('quran_surahs')->insert(Quran::surahRows());
    }

    public function down(): void
    {
        Schema::dropIfExists('quran_surahs');
    }
};
