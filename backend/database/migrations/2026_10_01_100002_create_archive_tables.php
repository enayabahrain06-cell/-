<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رفع الأرشيف / عرض الأرشيف: records of previous years imported from Excel. A batch is one upload; its rows are
 * matched to students by CPR, student number, or exact name and birth date (student_id stays null when unmatched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 255);
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('archive_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_batch_id')->constrained('archive_batches')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('cpr', 9)->nullable()->index();
            $table->string('student_no', 30)->nullable()->index();
            $table->string('full_name', 200);
            $table->string('academic_year', 30)->index();
            $table->string('term_label', 100)->nullable();
            $table->string('level_label', 100)->nullable();
            $table->string('class_label', 100)->nullable();
            $table->string('subject', 100)->nullable();
            $table->string('result', 100)->nullable();
            $table->string('grade', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_records');
        Schema::dropIfExists('archive_batches');
    }
};
