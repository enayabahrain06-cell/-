<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U5: evaluations of any subject. The four Quran score columns become nullable (an evaluation of fiqh has none of
 * them; its scores live in evaluation_scores), and an evaluation may name the division (التقسيمات) it was given in.
 * Existing rows keep their values.
 *
 * Runs outside a transaction: on SQLite the column change rebuilds the table, and foreign keys must be switched off
 * for that (a PRAGMA that has no effect inside a transaction), so rows pointing at evaluations are left untouched.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const COLUMNS = ['memorization', 'tajweed', 'revision', 'behavior'];

    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            foreach (self::COLUMNS as $c) {
                $table->unsignedTinyInteger($c)->nullable()->change();
            }
        });
        Schema::table('evaluations', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('division_id');
        });
        // Evaluations of other subjects have no Quran scores; the old schema needs a number there.
        foreach (self::COLUMNS as $c) {
            DB::table('evaluations')->whereNull($c)->update([$c => 0]);
        }
        Schema::table('evaluations', function (Blueprint $table) {
            foreach (self::COLUMNS as $c) {
                $table->unsignedTinyInteger($c)->nullable(false)->change();
            }
        });
    }
};
