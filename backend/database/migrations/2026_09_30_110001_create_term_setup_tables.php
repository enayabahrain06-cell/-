<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Term setup (اعدادات الفصل):
 * - level_subjects (مواد المستويات): which subjects a level studies in a term, with its default teacher.
 * - subject_lessons (دروس المواد): the curriculum of a subject, for one level or all levels; reused every term.
 * - plan_items (الخطة): a level subject's lessons spread over the term's weeks.
 * - night_supervisors (مشرفو الليالي): supervisors on duty per weekday in a term.
 * - timetable_slots (الجدول الدراسي): level (or one of its circles) × weekday × time × subject.
 *
 * Only new tables; nothing existing changes. Parents are restricted from deletion here and the controllers
 * refuse with a message instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('level_id')->constrained('levels')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('weekly_sessions')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['academic_term_id', 'level_id', 'subject_id']);
        });

        Schema::create('subject_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('levels')->restrictOnDelete(); // null = every level
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['subject_id', 'level_id']);
        });

        Schema::create('plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_subject_id')->constrained('level_subjects')->cascadeOnDelete();
            $table->unsignedTinyInteger('week_no');
            $table->foreignId('subject_lesson_id')->nullable()->constrained('subject_lessons')->nullOnDelete();
            $table->string('title', 200)->nullable(); // free item when no curriculum lesson is chosen
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['level_subject_id', 'week_no']);
        });

        Schema::create('night_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->string('weekday', 3);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['academic_term_id', 'weekday', 'user_id']);
        });

        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('level_id')->constrained('levels')->restrictOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete(); // one circle; null = the whole level
            $table->string('weekday', 3);
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['academic_term_id', 'weekday']);
            $table->index(['academic_term_id', 'level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_slots');
        Schema::dropIfExists('night_supervisors');
        Schema::dropIfExists('plan_items');
        Schema::dropIfExists('subject_lessons');
        Schema::dropIfExists('level_subjects');
    }
};
