<?php

use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Student;

it('sends pre-lesson reminders only for sessions starting inside the window, once', function () {
    $tz = config('ahl.display_timezone');
    $lesson = Lesson::factory()->create(['name' => 'حلقة الفجر']);
    $student = Student::factory()->create(['student_phone' => null, 'guardian_phone' => '+97336300001']);
    LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $student->id, 'joined_at' => today(), 'status' => 'active', 'current_memorization' => 'سورة الملك']);

    $soon = now()->setTimezone($tz)->addMinutes(90);
    $later = now()->setTimezone($tz)->addHours(5);
    $passed = now()->setTimezone($tz)->subMinutes(30);

    // Separate lessons so same-day sessions do not violate the (lesson, date) unique key.
    $lessonLater = Lesson::factory()->create(['name' => 'حلقة العصر']);
    $lessonGone = Lesson::factory()->create(['name' => 'حلقة الظهر']);
    foreach ([$lessonLater, $lessonGone] as $l) {
        LessonStudent::create(['lesson_id' => $l->id, 'student_id' => $student->id, 'joined_at' => today(), 'status' => 'active']);
    }

    $inWindow = LessonSession::factory()->create(['lesson_id' => $lesson->id, 'session_date' => $soon->toDateString(), 'start_time' => $soon->format('H:i:s'), 'end_time' => $soon->copy()->addHour()->format('H:i:s')]);
    $outside = LessonSession::factory()->create(['lesson_id' => $lessonLater->id, 'session_date' => $later->toDateString(), 'start_time' => $later->format('H:i:s'), 'end_time' => $later->copy()->addHour()->format('H:i:s')]);
    $gone = LessonSession::factory()->create(['lesson_id' => $lessonGone->id, 'session_date' => $passed->toDateString(), 'start_time' => $passed->format('H:i:s'), 'end_time' => $passed->copy()->addHour()->format('H:i:s')]);

    $this->artisan('lessons:send-reminders', ['--hours' => 2])->assertSuccessful();

    expect($inWindow->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($outside->fresh()->reminder_sent_at)->toBeNull()
        ->and($gone->fresh()->reminder_sent_at)->toBeNull();

    // Section 23: the long reminder (due 2 h before) goes out now; the short one (1 h before) is scheduled.
    $logs = MessageLog::where('type', 'attendance_reminder_long')->get();
    expect(MessageLog::where('type', 'attendance_reminder_short')->where('status', 'scheduled')->count())->toBe(1);
    expect($logs)->toHaveCount(1)
        ->and($logs->first()->recipient_phone)->toBe('+97336300001')
        ->and($logs->first()->body)->toContain('حلقة الفجر')->toContain('سورة الملك')->toContain($soon->format('H:i'));

    $this->artisan('lessons:send-reminders', ['--hours' => 2])->assertSuccessful();
    expect(MessageLog::where('type', 'attendance_reminder_long')->count())->toBe(1)
        ->and(MessageLog::where('type', 'attendance_reminder_short')->count())->toBe(1);
});
