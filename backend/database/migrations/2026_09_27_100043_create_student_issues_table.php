<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('evaluation_id')->nullable()->constrained('evaluations')->nullOnDelete();
            $table->string('category', 32)->index();
            $table->string('subcategory', 32)->nullable();
            $table->text('description');
            $table->text('action_plan')->nullable();
            $table->string('severity', 32)->index();
            $table->string('status', 32)->default('open')->index();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('opened_at')->index();
            $table->dateTime('resolved_at')->nullable()->index();
            $table->date('next_follow_up_date')->nullable()->index();
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });

        Schema::create('issue_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_issue_id')->constrained('student_issues')->cascadeOnDelete();
            $table->text('note');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('noted_on')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_notes');
        Schema::dropIfExists('student_issues');
    }
};
