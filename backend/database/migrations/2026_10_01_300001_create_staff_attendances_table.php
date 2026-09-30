<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حضور المشرفين / حضور المعلمين: one row per staff member, night and role (supervisor or teacher) in a term. Who is
 * expected on a night is not stored here: supervisors come from مشرفو الليالي (night_supervisors), teachers from the
 * timetable periods of that night's sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role_kind', 20); // supervisor | teacher
            $table->date('attendance_date');
            $table->string('status', 20); // present | absent | late | excused
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'attendance_date', 'role_kind']);
            $table->index(['academic_term_id', 'role_kind', 'attendance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendances');
    }
};
