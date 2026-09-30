<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U1: الجدول الدراسي becomes the only source of when a class meets.
 *
 * - A period may belong to one class without a level (level_id nullable); `source` = 'class' marks the periods that
 *   are the class's own simple schedule (written by the class form, or created here from its old days/times).
 * - Every class whose package has an academic term gets one Quran period per meeting day, copied from its
 *   days/start/end (teacher and room left empty = the class's teacher and room). Its sessions are not touched here;
 *   `php artisan sessions:sync --dry-run` shows what the schedule would change, and `sessions:sync` applies it.
 * - lessons.days / start_time / end_time stay, as a copy written from the timetable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_slots', function (Blueprint $table) {
            $table->foreignId('level_id')->nullable()->change();
            $table->string('source', 10)->nullable()->index();
        });

        $quran = DB::table('subjects')->where('code', 'quran')->value('id');
        if (! $quran) {
            return;
        }
        $now = now();
        $classes = DB::table('lessons')->join('packages', 'packages.id', '=', 'lessons.package_id')
            ->whereNotNull('packages.academic_term_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('timetable_slots')->whereColumn('timetable_slots.lesson_id', 'lessons.id'))
            ->orderBy('lessons.id')
            ->get(['lessons.id', 'lessons.level_id', 'lessons.days', 'lessons.start_time', 'lessons.end_time', 'packages.academic_term_id']);

        foreach ($classes as $c) {
            $days = json_decode((string) $c->days, true) ?: [];
            foreach (array_unique($days) as $day) {
                DB::table('timetable_slots')->insert([
                    'academic_term_id' => $c->academic_term_id,
                    'level_id' => $c->level_id,
                    'lesson_id' => $c->id,
                    'weekday' => $day,
                    'start_time' => $c->start_time,
                    'end_time' => $c->end_time,
                    'subject_id' => $quran,
                    'source' => 'class',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Periods without a level cannot exist in the old shape; the class keeps its days/times copy.
        DB::table('timetable_slots')->whereNull('level_id')->delete();

        Schema::table('timetable_slots', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
        Schema::table('timetable_slots', function (Blueprint $table) {
            $table->foreignId('level_id')->nullable(false)->change();
        });
    }
};
