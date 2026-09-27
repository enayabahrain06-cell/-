<?php

use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Student;

it('lets a teacher message only students in their own circles', function () {
    $teacher = actingAsRole('teacher');
    $lesson = Lesson::factory()->create(['teacher_id' => $teacher->id]);
    $mine = Student::factory()->create();
    $notMine = Student::factory()->create();
    LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $mine->id, 'joined_at' => now()->toDateString(), 'status' => 'active']);

    $this->postJson('/api/messages/send', ['student_ids' => [$mine->id], 'body' => 'تذكير بالمراجعة'])
        ->assertOk()->assertJsonPath('count', 1);

    $log = MessageLog::first();
    expect($log->type->value)->toBe('custom')
        ->and($log->body)->toBe('تذكير بالمراجعة')
        ->and($log->recipient_phone)->toBe($mine->guardian_phone)
        ->and($log->status->value)->toBe('sent');

    $this->postJson('/api/messages/send', ['student_ids' => [$mine->id, $notMine->id], 'body' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('student_ids');

    // Free-form phones need messages.manage.
    $this->postJson('/api/messages/send', ['phones' => ['36000000'], 'body' => 'x'])->assertForbidden();
});

it('lets a supervisor message any student and raw phones', function () {
    actingAsRole('supervisor');
    $s = Student::factory()->create(['student_phone' => '+97336005555']);

    $this->postJson('/api/messages/send', ['student_ids' => [$s->id], 'to' => 'both', 'body' => 'x'])->assertOk()->assertJsonPath('count', 2);
    $this->postJson('/api/messages/send', ['phones' => ['36007777'], 'body' => 'x', 'locale' => 'en'])->assertOk()->assertJsonPath('count', 1);

    expect(MessageLog::where('recipient_phone', '+97336007777')->first()->locale)->toBe('en');
});
