<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U9: one notes model for ملاحظات الطلبة، الملاحظات العامة، ملاحظات المستويات and ملاحظات مواد المستويات.
 * A note is about a student (optionally in the context of a class), the whole term (general), a level of the term,
 * or a level subject (a row of مواد المستويات).
 *
 * Every non-empty students.notes text becomes one student note of the current term (author unknown). The
 * students.notes column itself is kept unchanged; the student form still reads and writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            $table->string('scope', 20); // student | general | level | level_subject
            $table->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete(); // the class it was written in
            $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete();
            $table->foreignId('level_subject_id')->nullable()->constrained('level_subjects')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope', 'academic_term_id']);
            $table->index(['student_id', 'scope']);
        });

        $term = DB::table('academic_terms')->where('is_current', true)->orderByDesc('id')->value('id');
        DB::table('students')->whereNotNull('notes')->where('notes', '!=', '')->orderBy('id')
            ->select(['id', 'notes', 'created_at', 'updated_at'])
            ->chunk(500, function ($students) use ($term) {
                $rows = [];
                foreach ($students as $s) {
                    if (trim((string) $s->notes) === '') {
                        continue;
                    }
                    $rows[] = [
                        'academic_term_id' => $term, 'scope' => 'student', 'student_id' => $s->id, 'body' => $s->notes,
                        'pinned' => false, 'author_id' => null, 'created_at' => $s->updated_at ?? now(), 'updated_at' => $s->updated_at ?? now(),
                    ];
                }
                if ($rows) {
                    DB::table('notes')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
