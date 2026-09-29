<?php

use App\Models\Certificate;
use App\Models\Exam;
use App\Models\InboundMessage;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $this->tz = config('ahl.display_timezone', 'Asia/Bahrain');
    $this->maleTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->boysPkg = Package::factory()->create(['term' => 'T-boys', 'end_date' => now($this->tz)->addDays(5)->toDateString()]);
    $this->girlsPkg = Package::factory()->girls()->create(['term' => 'T-girls', 'end_date' => now($this->tz)->addDays(10)->toDateString()]);
    $this->boysLesson = Lesson::factory()->create(['name' => 'حلقة البنين', 'package_id' => $this->boysPkg->id, 'teacher_id' => $this->maleTeacher->id]);
    $this->girlsLesson = Lesson::factory()->create(['name' => 'حلقة البنات', 'package_id' => $this->girlsPkg->id]);
    $this->boy = Student::factory()->male()->create();
    $this->girl = Student::factory()->female()->create();
    foreach ([[$this->boysLesson, $this->boy], [$this->girlsLesson, $this->girl]] as [$l, $s]) {
        LessonStudent::create(['lesson_id' => $l->id, 'student_id' => $s->id, 'joined_at' => today(), 'status' => 'active']);
    }

    $at = fn (int $days) => now($this->tz)->startOfDay()->addDays($days)->setTime(16, 0)->utc();
    $this->boysExam = Exam::factory()->create(['name' => 'اختبار البنين', 'lesson_id' => $this->boysLesson->id, 'status' => 'published', 'opens_at' => $at(2)]);
    $this->girlsExam = Exam::factory()->create(['name' => 'اختبار البنات', 'lesson_id' => $this->girlsLesson->id, 'status' => 'published', 'opens_at' => $at(3)]);
    Exam::factory()->create(['name' => 'بعيد', 'lesson_id' => $this->boysLesson->id, 'status' => 'published', 'opens_at' => $at(8)]);
    Exam::factory()->create(['name' => 'انتهى', 'lesson_id' => $this->boysLesson->id, 'status' => 'published', 'opens_at' => $at(-1)]);
    Exam::factory()->create(['name' => 'مغلق', 'lesson_id' => $this->boysLesson->id, 'status' => 'closed', 'opens_at' => $at(1)]);
    // Paper exam: package-wide, still a draft.
    $this->paper = Exam::factory()->create(['name' => 'اختبار ورقي', 'type' => 'paper', 'lesson_id' => null, 'package_id' => $this->boysPkg->id,
        'status' => 'draft', 'opens_at' => $at(1), 'closes_at' => $at(1)->addHours(2), 'exam_date' => now($this->tz)->addDay()->toDateString()]);

    foreach ([1, 2] as $i) {
        Certificate::create(['recipient_type' => $this->girl->getMorphClass(), 'recipient_id' => $this->girl->id, 'context_type' => $this->girlsLesson->getMorphClass(), 'context_id' => $this->girlsLesson->id, 'type' => 'completion', 'title' => "شهادة {$i}", 'issued_on' => today()]);
    }
    Certificate::create(['recipient_type' => $this->girl->getMorphClass(), 'recipient_id' => $this->girl->id, 'type' => 'completion', 'title' => 'معتمدة', 'issued_on' => today(), 'status' => 'approved']);

    InboundMessage::create(['from_phone' => '97333000001', 'body' => 'استفسار', 'intent' => 'other', 'status' => 'open', 'student_id' => $this->boy->id, 'received_at' => now()->subDay()]);
    InboundMessage::create(['from_phone' => '97333000002', 'body' => 'تم', 'intent' => 'other', 'status' => 'resolved', 'student_id' => $this->boy->id, 'received_at' => now()]);
});

it('lists this week in date order: exams with student counts, packages ending, certificates and messages', function () {
    actingAsRole('super_admin');
    $d = $this->getJson('/api/dashboard/upcoming')->assertOk()->json('data');

    expect($d['from'])->toBe(now($this->tz)->toDateString())
        ->and(collect($d['items'])->pluck('id')->all())->toBe([
            'certificates', 'messages', "exam-{$this->paper->id}", "exam-{$this->boysExam->id}", "exam-{$this->girlsExam->id}", "package-{$this->boysPkg->id}",
        ]);

    $items = collect($d['items'])->keyBy('id');
    expect($items["exam-{$this->boysExam->id}"])->toMatchArray(['type' => 'exam', 'title' => 'اختبار البنين', 'subtitle' => 'حلقة البنين', 'count' => 1, 'link' => "/exams/{$this->boysExam->id}", 'draft' => false])
        ->and($items["exam-{$this->paper->id}"])->toMatchArray(['count' => 1, 'draft' => true, 'subtitle' => $this->boysPkg->name_ar])
        ->and($items['certificates'])->toMatchArray(['count' => 2, 'link' => '/certificates'])
        ->and($items['messages'])->toMatchArray(['count' => 1, 'link' => '/messages'])
        ->and($items["package-{$this->boysPkg->id}"]['date'])->toBe(now($this->tz)->addDays(5)->toDateString());
});

it('scopes items to the girls track, to a teacher\'s own circles and permissions, and to the chosen term', function () {
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    expect(collect($this->getJson('/api/dashboard/upcoming')->json('data.items'))->pluck('id')->all())
        ->toBe(['certificates', "exam-{$this->girlsExam->id}"]);

    // Teachers see their own circles' exams only; no approvals, messages or packages permission.
    $this->actingAs($this->maleTeacher, 'sanctum');
    expect(collect($this->getJson('/api/dashboard/upcoming')->json('data.items'))->pluck('id')->all())
        ->toBe(["exam-{$this->boysExam->id}"]);

    actingAsRole('super_admin');
    expect(collect($this->getJson('/api/dashboard/upcoming?term=T-girls')->json('data.items'))->pluck('id')->all())
        ->toBe(['certificates', 'messages', "exam-{$this->girlsExam->id}"]);

    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $this->actingAs($guardian, 'sanctum');
    $this->getJson('/api/dashboard/upcoming')->assertForbidden();
});
