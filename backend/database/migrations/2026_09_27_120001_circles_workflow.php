<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Circles first, then enrollment into a circle (workflow clarification of 2026-09-27):
 * - age_groups: editable age bands used by circles, quick enrollment and reports (track null = both).
 * - lessons.age_group_id + min_age / max_age: each circle has its own age range (copied from the group).
 * - lesson_students keeps one row per stay in a circle: moving closes the old row (left_at, moved_by, reason)
 *   and opens a new one, so the (lesson, student) unique pair is replaced by an index. status 'active'
 *   still means "currently in the circle"; one active row per student is enforced in CircleEnrollmentService.
 * - registration_requests.lesson_id: the circle picked on acceptance; status accepted → enrolled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('age_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->unsignedTinyInteger('min_age');
            $table->unsignedTinyInteger('max_age')->nullable(); // null = and above (e.g. 15+)
            $table->string('track', 16)->nullable();            // male / female / mixed; null = both tracks
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('age_group_id')->nullable()->constrained('age_groups')->nullOnDelete();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
        });

        Schema::table('lesson_students', function (Blueprint $table) {
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignId('moved_to_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->index(['lesson_id', 'student_id']);
            $table->index(['student_id', 'status']);
        });
        Schema::table('lesson_students', function (Blueprint $table) {
            $table->dropUnique(['lesson_id', 'student_id']);
        });

        Schema::table('registration_requests', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
        });
        DB::table('registration_requests')->where('status', 'accepted')->update(['status' => 'enrolled']);

        // Existing circles take their package's age range.
        foreach (DB::table('packages')->get(['id', 'min_age', 'max_age']) as $p) {
            DB::table('lessons')->where('package_id', $p->id)->update(['min_age' => $p->min_age, 'max_age' => $p->max_age]);
        }
    }

    public function down(): void
    {
        DB::table('registration_requests')->where('status', 'enrolled')->update(['status' => 'accepted']);
        DB::table('registration_requests')->where('status', 'pending_lottery')->update(['status' => 'pending']);
        Schema::table('registration_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_id');
        });

        Schema::table('lesson_students', function (Blueprint $table) {
            $table->unique(['lesson_id', 'student_id']);
        });
        Schema::table('lesson_students', function (Blueprint $table) {
            $table->dropIndex(['lesson_id', 'student_id']);
            $table->dropIndex(['student_id', 'status']);
            $table->dropConstrainedForeignId('moved_to_lesson_id');
            $table->dropConstrainedForeignId('moved_by');
            $table->dropColumn('reason');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('age_group_id');
            $table->dropColumn(['min_age', 'max_age']);
        });

        Schema::dropIfExists('age_groups');
    }
};
