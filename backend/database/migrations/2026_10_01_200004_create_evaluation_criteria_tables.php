<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U5 التقييمات: the criteria a subject is evaluated on, and one score per criterion of an evaluation.
 *
 * Quran gets its four system criteria (memorization, tajweed, revision, behavior; 0–10), which can be renamed or
 * reordered but never deleted or switched off. Every existing evaluation's four columns are copied into scores.
 * The columns stay and are still written (dual write), so every existing reader keeps working unchanged.
 */
return new class extends Migration
{
    private const SYSTEM = [
        ['key' => 'memorization', 'name_ar' => 'الحفظ', 'name_en' => 'Memorization'],
        ['key' => 'tajweed', 'name_ar' => 'التجويد', 'name_en' => 'Tajweed'],
        ['key' => 'revision', 'name_ar' => 'المراجعة', 'name_en' => 'Revision'],
        ['key' => 'behavior', 'name_ar' => 'السلوك', 'name_en' => 'Behavior'],
    ];

    public function up(): void
    {
        Schema::create('evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->string('key', 40)->nullable(); // system criteria only
            $table->string('name_ar', 120);
            $table->string('name_en', 120);
            $table->unsignedTinyInteger('max_score')->default(10);
            $table->unsignedSmallInteger('weight')->default(1);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['subject_id', 'key']);
        });

        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('evaluations')->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('evaluation_criteria')->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->timestamps();

            $table->unique(['evaluation_id', 'criterion_id']);
            $table->index('criterion_id');
        });

        $quran = DB::table('subjects')->where('code', 'quran')->value('id');
        if (! $quran) {
            return;
        }
        $now = now();
        $ids = [];
        foreach (self::SYSTEM as $i => $c) {
            $ids[$c['key']] = DB::table('evaluation_criteria')->insertGetId($c + [
                'subject_id' => $quran, 'max_score' => 10, 'weight' => 1, 'is_system' => true, 'sort' => $i + 1, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Existing evaluations are Quran's (U8 set their subject); copy their four scores, in chunks.
        DB::table('evaluations')->where(fn ($q) => $q->where('subject_id', $quran)->orWhereNull('subject_id'))
            ->select(['id', 'memorization', 'tajweed', 'revision', 'behavior', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($evaluations) use ($ids) {
                $rows = [];
                foreach ($evaluations as $e) {
                    foreach ($ids as $key => $criterionId) {
                        if ($e->{$key} === null) {
                            continue;
                        }
                        $rows[] = ['evaluation_id' => $e->id, 'criterion_id' => $criterionId, 'score' => (int) $e->{$key}, 'created_at' => $e->created_at, 'updated_at' => $e->updated_at];
                    }
                }
                foreach (array_chunk($rows, 400) as $chunk) {
                    DB::table('evaluation_scores')->insert($chunk);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('evaluation_criteria');
    }
};
