<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('type', 32)->index();
            $table->date('exam_date')->index();
            $table->dateTime('opens_at')->index();
            $table->dateTime('closes_at');
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('total_marks');
            $table->unsignedInteger('pass_mark');
            $table->text('syllabus')->nullable();
            $table->boolean('randomize')->default(true);
            $table->string('status', 32)->default('draft')->index();
            $table->dateTime('reminder_day_sent_at')->nullable();
            $table->dateTime('reminder_hour_sent_at')->nullable();
            $table->dateTime('results_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('location_bookings', function (Blueprint $table) {
            $table->foreign('exam_id')->references('id')->on('exams')->nullOnDelete();
        });

        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('prompt');
            $table->text('options')->nullable();        // JSON
            $table->text('correct_answer')->nullable(); // JSON
            $table->unsignedInteger('marks');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['exam_id', 'sort_order']);
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->string('status', 32)->default('in_progress')->index();
            $table->text('question_order')->nullable(); // JSON list of question ids
            $table->unsignedInteger('auto_score')->nullable();
            $table->unsignedInteger('manual_score')->nullable();
            $table->unsignedInteger('total_score')->nullable();
            $table->boolean('passed')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
        });

        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->text('answer')->nullable(); // JSON
            $table->unsignedInteger('score')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('grader_note')->nullable();
            $table->dateTime('saved_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_attempt_id', 'exam_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('exam_questions');
        Schema::table('location_bookings', function (Blueprint $table) {
            $table->dropForeign(['exam_id']);
        });
        Schema::dropIfExists('exams');
    }
};
