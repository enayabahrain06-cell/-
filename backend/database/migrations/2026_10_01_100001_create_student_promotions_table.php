<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ترفيع الطلبة / تحديث المستوى: one row per decision about a student's level (promote, repeat, graduate, level
 * change). The enrollment itself stays in lesson_students (history kept); this table only records who decided what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('from_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            $table->foreignId('from_level_id')->nullable()->constrained('levels')->nullOnDelete();
            $table->foreignId('from_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('to_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
            $table->foreignId('to_level_id')->nullable()->constrained('levels')->nullOnDelete();
            $table->foreignId('to_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('decision', 20); // promote | repeat | graduate | level_change
            $table->string('reason', 255)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['from_term_id', 'from_level_id']);
            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_promotions');
    }
};
