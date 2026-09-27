<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 14 — Challenges (تحديات): self-paced goals tracked automatically from the existing ledgers
 * (attendance, evaluations, student_progress). Nothing is entered manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            $table->string('name_en', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('gender', 32)->index();                 // track: male / female
            $table->string('scope', 32);                           // circle / package / authority
            $table->foreignId('scope_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('scope_package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->string('goal_type', 32)->index();              // memorize_range / attendance_days / revision_range / score_streak / points
            $table->unsignedInteger('goal_value');                 // ayahs, days, sessions or points
            $table->unsignedTinyInteger('surah_number')->nullable();
            $table->unsignedSmallInteger('from_ayah')->nullable();
            $table->unsignedSmallInteger('to_ayah')->nullable();
            $table->unsignedTinyInteger('min_score')->nullable();   // score_streak threshold (0–10)
            $table->string('score_criterion', 32)->nullable();     // memorization / tajweed / revision / behavior / total
            $table->date('starts_at')->index();
            $table->date('ends_at')->index();
            $table->foreignId('reward_badge_id')->nullable()->constrained('badges')->nullOnDelete();
            $table->unsignedInteger('reward_points')->default(0);
            $table->string('status', 32)->default('draft')->index(); // draft / active / finished / cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('challenge_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained('challenges')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->dateTime('joined_at');
            $table->foreignId('joined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('progress_value')->default(0);
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->string('status', 32)->default('joined')->index(); // joined / completed / failed
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('rewarded_at')->nullable();              // badge + points exactly once
            $table->dateTime('progress_computed_at')->nullable();
            $table->dateTime('nudged_half_at')->nullable();           // 50% nudge
            $table->dateTime('nudged_deadline_at')->nullable();       // 3 days before the end
            $table->timestamps();

            $table->unique(['challenge_id', 'student_id']);
        });

        // Certificates can now come from the honor board and competitions as well.
        Schema::table('certificates', function (Blueprint $table) {
            $table->foreignId('honor_period_id')->nullable()->constrained('honor_periods')->nullOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained('competitions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('competition_id');
            $table->dropConstrainedForeignId('honor_period_id');
        });
        Schema::dropIfExists('challenge_participants');
        Schema::dropIfExists('challenges');
    }
};
