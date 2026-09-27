<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotteries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('status', 32)->default('draft')->index();
            $table->boolean('balance_ages')->default(false);
            $table->boolean('keep_siblings')->default(false);
            $table->boolean('balance_levels')->default(false);
            $table->string('seed', 40)->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->dateTime('run_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lottery_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lottery_id')->constrained('lotteries')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->unsignedInteger('capacity');
            $table->timestamps();

            $table->unique(['lottery_id', 'teacher_id']);
        });

        Schema::create('lottery_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lottery_id')->constrained('lotteries')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lottery_id', 'student_id']);
        });

        Schema::create('lottery_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lottery_id')->constrained('lotteries')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->unsignedInteger('run_no');
            $table->dateTime('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['lottery_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_results');
        Schema::dropIfExists('lottery_students');
        Schema::dropIfExists('lottery_teachers');
        Schema::dropIfExists('lotteries');
    }
};
