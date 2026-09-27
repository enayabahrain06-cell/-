<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised cache of the position computed from student_progress (the source of truth).
 * Recomputed by ProgressService on every ledger change; used by lists and the "students by juz" report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_surah')->nullable();
            $table->unsignedSmallInteger('progress_ayah')->nullable();
            $table->unsignedTinyInteger('progress_juz')->nullable()->index();
            $table->unsignedSmallInteger('memorized_ayahs')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['progress_juz']);
            $table->dropColumn(['progress_surah', 'progress_ayah', 'progress_juz', 'memorized_ayahs']);
        });
    }
};
