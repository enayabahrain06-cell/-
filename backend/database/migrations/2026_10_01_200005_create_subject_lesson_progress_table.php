<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تحديث دروس المواد: which plan items (الخطة of the class's level subject) a class has actually been taught, when,
 * by whom, and optionally in which session. One row per class and plan item; no row = not taught yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('plan_item_id')->constrained('plan_items')->cascadeOnDelete();
            $table->date('taught_on');
            $table->foreignId('taught_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lesson_session_id')->nullable()->constrained('lesson_sessions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'plan_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_lesson_progress');
    }
};
