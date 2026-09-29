<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Placement tests reuse the exams system: an exam of type "placement" on a package, taken during public
 * registration before any student exists. Its attempts are anonymous (student_id null) and reached by a
 * one-time token until the registration request, and later the accepted student, is attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            // JSON list of {min: percent, level: MemorizationLevel}, highest band first. Placement exams only.
            $table->text('level_bands')->nullable()->after('pass_mark');
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->string('category', 60)->nullable()->after('marks');
            $table->string('difficulty', 16)->nullable()->after('category');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->change();
            $table->foreignId('registration_request_id')->nullable()->after('student_id')->constrained('registration_requests')->nullOnDelete();
            $table->string('access_token', 64)->nullable()->unique()->after('registration_request_id'); // sha256 of the public token
            $table->string('candidate_name', 150)->nullable()->after('access_token');
            $table->string('candidate_phone', 20)->nullable()->after('candidate_name');
            $table->unsignedSmallInteger('attempt_no')->nullable()->after('candidate_phone');
            $table->string('recommended_level', 40)->nullable()->after('passed');

            $table->index(['exam_id', 'candidate_phone']);
        });

        Schema::table('registration_requests', function (Blueprint $table) {
            $table->foreignId('placement_attempt_id')->nullable()->after('lesson_id')->constrained('exam_attempts')->nullOnDelete();
            $table->string('recommended_level', 40)->nullable()->after('memorization_level');
            // The level staff confirmed on acceptance (memorization_level stays what the family declared).
            $table->string('final_level', 40)->nullable()->after('recommended_level');
            $table->foreignId('level_confirmed_by')->nullable()->after('final_level')->constrained('users')->nullOnDelete();
            $table->dateTime('level_confirmed_at')->nullable()->after('level_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('registration_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('placement_attempt_id');
            $table->dropConstrainedForeignId('level_confirmed_by');
            $table->dropColumn(['recommended_level', 'final_level', 'level_confirmed_at']);
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex(['exam_id', 'candidate_phone']);
            $table->dropConstrainedForeignId('registration_request_id');
            $table->dropUnique(['access_token']);
            $table->dropColumn(['access_token', 'candidate_name', 'candidate_phone', 'attempt_no', 'recommended_level']);
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropColumn(['category', 'difficulty']);
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('level_bands');
        });
    }
};
