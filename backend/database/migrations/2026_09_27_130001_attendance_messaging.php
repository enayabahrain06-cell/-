<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic WhatsApp attendance messaging (section 23):
 * - users.notifications_enabled: "إيقاف / Stop" turns it off, "تشغيل / Start" back on.
 * - message_logs gains the session it belongs to, a planned send time (status "scheduled" until due),
 *   a de-duplication key (unique: one template per recipient per session) and delivery timestamps.
 * - attendance_confirmations: "حاضر / Yes" replies (the second reminder is skipped for that student).
 * - attendance_excuses: "عذر / Excuse" replies; applied directly before attendance is taken, pending
 *   for the teacher to approve afterwards.
 * - inbound_messages: every inbound WhatsApp text; unrecognised ones form the supervisor inbox.
 * - phone_statuses: consecutive delivery failures (invalid after N) and opt-outs for numbers with no user.
 * - lesson_messaging_rules: per-circle overrides (reminders on/off, second reminder on/off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notifications_enabled')->default(true);
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->foreignId('lesson_session_id')->nullable()->constrained('lesson_sessions')->nullOnDelete();
            $table->dateTime('scheduled_for')->nullable()->index();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->index(['lesson_session_id', 'template_key']);
        });

        Schema::create('attendance_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_session_id')->constrained('lesson_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->dateTime('confirmed_at');
            $table->string('via', 20)->default('whatsapp');
            $table->string('phone', 20)->nullable();
            $table->timestamps();

            $table->unique(['lesson_session_id', 'student_id']);
        });

        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('from_phone', 20)->index();
            $table->text('body')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('provider_message_id', 120)->nullable()->unique();
            $table->string('intent', 20)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('lesson_session_id')->nullable()->constrained('lesson_sessions')->nullOnDelete();
            $table->string('status', 20)->default('processed')->index(); // processed | open | resolved
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('handled_at')->nullable();
            $table->dateTime('received_at')->index();
            $table->timestamps();
        });

        Schema::create('attendance_excuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_session_id')->constrained('lesson_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->foreignId('inbound_message_id')->nullable()->constrained('inbound_messages')->nullOnDelete();
            $table->string('phone', 20)->nullable();
            $table->text('body')->nullable();
            $table->string('source', 20)->default('whatsapp');
            $table->string('status', 20)->index(); // applied | pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['lesson_session_id', 'student_id']);
        });

        Schema::create('phone_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->dateTime('invalid_at')->nullable();
            $table->dateTime('opted_out_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('lesson_messaging_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->unique()->constrained('lessons')->cascadeOnDelete();
            $table->boolean('reminders_enabled')->default(true);
            $table->boolean('second_reminder_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_messaging_rules');
        Schema::dropIfExists('phone_statuses');
        Schema::dropIfExists('attendance_excuses');
        Schema::dropIfExists('inbound_messages');
        Schema::dropIfExists('attendance_confirmations');

        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['lesson_session_id', 'template_key']);
            $table->dropUnique(['dedupe_key']);
            $table->dropIndex(['scheduled_for']);
            $table->dropConstrainedForeignId('lesson_session_id');
            $table->dropColumn(['scheduled_for', 'dedupe_key', 'delivered_at', 'read_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_enabled');
        });
    }
};
