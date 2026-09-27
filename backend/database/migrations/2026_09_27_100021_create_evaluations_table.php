<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('lesson_session_id')->nullable()->constrained('lesson_sessions')->nullOnDelete();
            $table->string('type', 32)->index();
            $table->date('evaluated_on')->index();
            $table->string('period', 7)->nullable()->index(); // YYYY-MM for monthly
            $table->unsignedTinyInteger('memorization');
            $table->unsignedTinyInteger('tajweed');
            $table->unsignedTinyInteger('revision');
            $table->unsignedTinyInteger('behavior');
            $table->text('note')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sent_to_guardian_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
