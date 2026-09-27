<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 13 — Honor board (لوحة التميز).
 *
 * - honor_periods: one row per month and gender track (rankings never mix); holds the weights snapshot,
 *   the honoring action state and the circle of the month.
 * - honor_rankings: the monthly snapshot per student (history kept; a new month starts a new period).
 *   No decimal columns: fractional values are stored as integers ×100 (e.g. points_x100 = 8734 → 87.34).
 * - badges / student_badges: configurable rules, awarded by a scheduled job (unique per student, badge, period).
 * - honor_points: bonus points from competitions and challenges; unique per source so rewards land exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('honor_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7);                    // YYYY-MM
            $table->string('gender', 32);                   // male / female
            $table->string('status', 32)->default('open')->index(); // open / finalized / honored
            $table->text('weights');                        // JSON snapshot {attendance:40, evaluation:40, memorization:20}
            $table->foreignId('circle_of_month_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->boolean('published_to_students')->default(false);
            $table->dateTime('finalized_at')->nullable();
            $table->dateTime('honored_at')->nullable();
            $table->foreignId('honored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period', 'gender']);
            $table->index('gender');
        });

        Schema::create('honor_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('honor_period_id')->constrained('honor_periods')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->unsignedSmallInteger('attendance_pct')->default(0);       // 0–100
            $table->unsignedSmallInteger('evaluation_avg_x100')->default(0);  // 0–1000 (0.00–10.00)
            $table->unsignedInteger('new_ayahs')->default(0);
            $table->unsignedInteger('attendance_points_x100')->default(0);
            $table->unsignedInteger('evaluation_points_x100')->default(0);
            $table->unsignedInteger('memorization_points_x100')->default(0);
            $table->unsignedInteger('bonus_points_x100')->default(0);         // competitions & challenges
            $table->unsignedInteger('points_x100')->default(0)->index();
            $table->unsignedInteger('rank_in_track')->nullable();
            $table->unsignedInteger('rank_in_package')->nullable();
            $table->unsignedInteger('rank_in_circle')->nullable();
            $table->integer('points_change_x100')->nullable();               // vs previous month (most improved)
            $table->timestamps();

            $table->unique(['honor_period_id', 'student_id']);
            $table->index(['honor_period_id', 'lesson_id']);
            $table->index(['honor_period_id', 'package_id']);
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name_ar', 120);
            $table->string('name_en', 120);
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('icon', 40)->default('star');
            $table->string('rule_type', 32)->index();   // completed_juz / full_attendance / tajweed_average / most_improved / points_min / competition / challenge / manual
            $table->integer('rule_value')->nullable();   // e.g. juz 30, attendance 100, tajweed ×100 = 900
            $table->text('rule_params')->nullable();     // JSON for extra parameters
            $table->boolean('repeatable_monthly')->default(false);
            $table->unsignedInteger('bonus_points')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('student_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('badges')->cascadeOnDelete();
            $table->string('period', 7)->default('once');   // YYYY-MM for monthly badges, "once" for lifetime badges
            $table->dateTime('awarded_at')->index();
            $table->string('source_type', 150)->nullable(); // honor period, competition, challenge
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'badge_id', 'period']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('honor_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('period', 7)->index();
            $table->integer('points_x100');
            $table->string('source_type', 150);
            $table->unsignedBigInteger('source_id');
            $table->string('reason', 200)->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('honor_points');
        Schema::dropIfExists('student_badges');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('honor_rankings');
        Schema::dropIfExists('honor_periods');
    }
};
