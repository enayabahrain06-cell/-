<?php

use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\Student;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\Dashboard\MemorizationPanel;
use App\Support\Quran;
use Carbon\Carbon;

beforeEach(function () {
    $tz = config('ahl.display_timezone', 'Asia/Bahrain');
    $this->today = Carbon::parse(now($tz)->toDateString());
    $this->weekStart = $this->today->copy()->startOfWeek(Carbon::SATURDAY);
    $this->monthStart = $this->today->copy()->startOfMonth();

    $this->maleTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    // Plan: 1000 ayahs over 365 days, 100 days elapsed → 274 planned to date.
    $boysPkg = Package::factory()->create(['gender' => 'male', 'term' => '1447', 'plan_ayahs' => 1000,
        'start_date' => $this->today->copy()->subDays(100)->toDateString(), 'end_date' => $this->today->copy()->addDays(265)->toDateString()]);
    $girlsPkg = Package::factory()->girls()->create(['term' => '1448', 'plan_ayahs' => 0]);
    $this->boysLesson = Lesson::factory()->create(['name' => 'حلقة البنين', 'package_id' => $boysPkg->id, 'teacher_id' => $this->maleTeacher->id]);
    $this->girlsLesson = Lesson::factory()->create(['name' => 'حلقة البنات', 'package_id' => $girlsPkg->id]);
    $this->boy = Student::factory()->male()->create(['full_name' => 'علي']);
    $this->girl = Student::factory()->female()->create(['full_name' => 'فاطمة']);
    foreach ([[$this->boysLesson, $this->boy], [$this->girlsLesson, $this->girl]] as [$l, $s]) {
        LessonStudent::create(['lesson_id' => $l->id, 'student_id' => $s->id, 'joined_at' => $this->today->copy()->subDays(100), 'status' => 'active']);
    }

    // Boy: 100 new ayahs today, revised 2 days ago.
    StudentProgress::create(['student_id' => $this->boy->id, 'type' => 'memorized', 'surah_number' => 2, 'from_ayah' => 1, 'to_ayah' => 100, 'ayah_count' => 100, 'recorded_on' => $this->today->toDateString()]);
    StudentProgress::create(['student_id' => $this->boy->id, 'type' => 'revised', 'surah_number' => 1, 'from_ayah' => 1, 'to_ayah' => 7, 'ayah_count' => 7, 'recorded_on' => $this->today->copy()->subDays(2)->toDateString()]);
    // Girl: completes juz 30 (78:1 to 114:end) this month; surah 78 was already done last month. No revision.
    $n78 = Quran::ayahCount(78);
    StudentProgress::create(['student_id' => $this->girl->id, 'type' => 'memorized', 'surah_number' => 78, 'from_ayah' => 1, 'to_ayah' => $n78, 'ayah_count' => $n78, 'recorded_on' => $this->monthStart->copy()->subDays(3)->toDateString()]);
    $this->girlMonthAyahs = 0;
    foreach (range(79, 114) as $s) {
        $n = Quran::ayahCount($s);
        $this->girlMonthAyahs += $n;
        StudentProgress::create(['student_id' => $this->girl->id, 'type' => 'memorized', 'surah_number' => $s, 'from_ayah' => 1, 'to_ayah' => $n, 'ayah_count' => $n, 'recorded_on' => $this->monthStart->toDateString()]);
    }
});

it('computes the week, reviews due, juz finished this month and students behind plan', function () {
    actingAsRole('super_admin');
    $d = $this->getJson('/api/dashboard/memorization')->assertOk()->json('data');

    $weekAyahs = 100 + ($this->monthStart->gte($this->weekStart) ? $this->girlMonthAyahs : 0);
    expect($d['students'])->toBe(2)
        ->and($d['ayahs_this_week'])->toBe($weekAyahs)
        ->and($d['pages_this_week'])->toEqual(round($weekAyahs / MemorizationPanel::AYAHS_PER_PAGE, 1))
        ->and($d['reviews_due'])->toBe(1)
        ->and($d['juz_finished_this_month'])->toBe(1)
        ->and($d['behind_total'])->toBe(1)
        ->and($d['behind'][0])->toMatchArray([
            'student_id' => $this->boy->id, 'name' => 'علي', 'lesson' => 'حلقة البنين',
            'planned_ayahs' => 274, 'actual_ayahs' => 100, 'gap_ayahs' => 174, 'plan_percent' => 10,
        ])
        ->and($d['behind'][0]['gap_pages'])->toEqual(round(174 / MemorizationPanel::AYAHS_PER_PAGE, 1));
});

it('scopes by track, own circles and term, and refuses guardians', function () {
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $d = $this->getJson('/api/dashboard/memorization')->assertOk()->json('data');
    expect($d['students'])->toBe(1)->and($d['behind'])->toBe([])->and($d['juz_finished_this_month'])->toBe(1);

    $this->actingAs($this->maleTeacher, 'sanctum');
    $d = $this->getJson('/api/dashboard/memorization')->assertOk()->json('data');
    expect($d['students'])->toBe(1)->and($d['behind'][0]['student_id'])->toBe($this->boy->id);

    actingAsRole('super_admin');
    expect($this->getJson('/api/dashboard/memorization?term=1448')->json('data.students'))->toBe(1)
        ->and($this->getJson('/api/dashboard/memorization?term=1448')->json('data.behind'))->toBe([])
        ->and($this->getJson('/api/dashboard/memorization?term=nothing')->json('data.students'))->toBe(0);

    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $this->actingAs($guardian, 'sanctum');
    $this->getJson('/api/dashboard/memorization')->assertForbidden();
});
