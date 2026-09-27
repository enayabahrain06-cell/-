<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 14 — Competitions (مسابقات): organized events with registration, jury, rounds and prizes.
 * Every competition belongs to one gender track. JSON lists are text columns with Eloquent casts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            $table->string('name_en', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('gender', 32)->index();               // track: male / female
            $table->string('type', 32)->index();                 // memorization / tajweed / recitation / knowledge
            $table->string('scope', 32);                         // circle / package / authority
            $table->foreignId('scope_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('scope_package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->dateTime('registration_opens_at')->index();
            $table->dateTime('registration_closes_at')->index();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->unsignedInteger('max_participants')->nullable();
            $table->string('status', 32)->default('draft')->index(); // draft / open / running / judging / finished / cancelled
            $table->text('criteria');                             // JSON [{key, name_ar, name_en, weight, max}]
            $table->string('tie_break', 32)->default('last_round'); // last_round / criterion_order / age_younger / registration_order
            $table->dateTime('results_published_at')->nullable();
            $table->foreignId('results_published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('registration_open_notified_at')->nullable();
            $table->dateTime('registration_close_notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('competition_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('competitions')->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('round_date')->index();
            $table->time('start_time')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->text('criteria')->nullable();                 // JSON override of the competition criteria
            $table->string('status', 32)->default('scheduled')->index(); // scheduled / judging / closed
            $table->dateTime('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['competition_id', 'sort_order']);
        });

        Schema::create('competition_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('competitions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->dateTime('registered_at');
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('registered')->index(); // registered / withdrawn / eliminated / finalist / winner
            $table->unsignedInteger('seed_no')->nullable();
            $table->unsignedInteger('final_rank')->nullable();
            $table->unsignedInteger('final_score_x100')->nullable();
            $table->dateTime('rewarded_at')->nullable();           // prizes, badges and points written exactly once
            $table->dateTime('result_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['competition_id', 'student_id']);
        });

        Schema::create('competition_judges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('competitions')->cascadeOnDelete();
            $table->foreignId('round_id')->nullable()->constrained('competition_rounds')->cascadeOnDelete(); // null = every round
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['competition_id', 'round_id', 'user_id']);
        });

        Schema::create('competition_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('competition_participants')->cascadeOnDelete();
            $table->foreignId('round_id')->constrained('competition_rounds')->cascadeOnDelete();
            $table->foreignId('judge_id')->constrained('users')->cascadeOnDelete();
            $table->text('criteria_scores');                      // JSON {criterion_key: integer score}
            $table->unsignedInteger('total_x100');                // weighted total ×100
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['participant_id', 'round_id', 'judge_id']);
            $table->index(['round_id', 'judge_id']);
        });

        Schema::create('competition_prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('competitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('certificate_template', 60)->nullable();
            $table->foreignId('badge_id')->nullable()->constrained('badges')->nullOnDelete();
            $table->unsignedInteger('points')->default(0);
            $table->timestamps();

            $table->unique(['competition_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_prizes');
        Schema::dropIfExists('competition_scores');
        Schema::dropIfExists('competition_judges');
        Schema::dropIfExists('competition_participants');
        Schema::dropIfExists('competition_rounds');
        Schema::dropIfExists('competitions');
    }
};
