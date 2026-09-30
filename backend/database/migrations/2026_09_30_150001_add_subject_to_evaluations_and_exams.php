<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U8: evaluations and exams name their subject. Every existing row is Quran (the only subject until now).
 * New rows default to Quran unless another subject is given (see the models). The memorization ledger
 * (student_progress) stays Quran by definition and is not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['evaluations', 'exams'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            });
        }

        $quran = DB::table('subjects')->where('code', 'quran')->value('id');
        if ($quran) {
            DB::table('evaluations')->whereNull('subject_id')->update(['subject_id' => $quran]);
            DB::table('exams')->whereNull('subject_id')->update(['subject_id' => $quran]);
        }
    }

    public function down(): void
    {
        foreach (['exams', 'evaluations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('subject_id');
            });
        }
    }
};
