<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدرجات (Phase 6):
 * - grade_components (توزيع الدرجات): the parts a level subject of a term is graded on, with their marks and weight.
 *   A component of kind "exam" links one exam; its scores stay in exam_attempts.total_score and are read from there.
 * - grade_entries: scores of the other components, one per component and student.
 * - exam_required_lessons (الدروس المطلوبة): the subject lessons (دروس المواد) an exam covers.
 *
 * Only new tables; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_subject_id')->constrained('level_subjects')->restrictOnDelete();
            $table->string('name_ar', 150);
            $table->string('name_en', 150)->nullable();
            $table->string('kind', 20); // exam, homework, participation, attendance, project, other
            $table->decimal('max_marks', 7, 2);
            $table->decimal('weight', 5, 2); // percent share of the subject's total
            $table->foreignId('exam_id')->nullable()->unique()->constrained('exams')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['level_subject_id', 'sort']);
        });

        Schema::create('grade_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_component_id')->constrained('grade_components')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->decimal('score', 7, 2);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('entered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['grade_component_id', 'student_id']);
        });

        Schema::create('exam_required_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('subject_lesson_id')->constrained('subject_lessons')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'subject_lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_required_lessons');
        Schema::dropIfExists('grade_entries');
        Schema::dropIfExists('grade_components');
    }
};
