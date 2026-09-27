<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('certificate_no', 30)->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->foreignId('exam_id')->nullable()->constrained('exams')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('title', 200);
            $table->date('issued_on')->index();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            // PDF file lives in media (collection = certificate)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
