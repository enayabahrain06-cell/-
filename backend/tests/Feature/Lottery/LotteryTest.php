<?php

use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\Lottery\LotteryAllocator;

beforeEach(function () {
    $this->pkg = Package::factory()->create(['gender' => 'male', 'start_date' => now()->addWeek()->toDateString()]);
    $this->t1 = User::factory()->role('teacher')->create(['gender' => 'male', 'name' => 'الشيخ أحمد']);
    $this->t2 = User::factory()->role('teacher')->create(['gender' => 'male', 'name' => 'الشيخ علي']);
    $this->l1 = Lesson::factory()->create(['package_id' => $this->pkg->id, 'teacher_id' => $this->t1->id, 'capacity' => 20, 'name' => 'حلقة ١']);
    $this->l2 = Lesson::factory()->create(['package_id' => $this->pkg->id, 'teacher_id' => $this->t2->id, 'capacity' => 20, 'name' => 'حلقة ٢']);
    // 10 accepted boys; two sibling families of 3 and 2 (shared guardian user).
    $g1 = User::factory()->withoutPassword()->create();
    $g2 = User::factory()->withoutPassword()->create();
    $this->students = collect(range(1, 10))->map(function ($i) use ($g1, $g2) {
        $s = Student::factory()->male()->create([
            'guardian_user_id' => $i <= 3 ? $g1->id : ($i <= 5 ? $g2->id : null),
            'birth_date' => now()->subYears(7 + ($i % 5))->toDateString(),
            'memorization_level' => $i % 2 ? 'none' : 'juz_amma',
        ]);
        RegistrationRequest::factory()->create(['package_id' => $this->pkg->id, 'student_id' => $s->id, 'status' => \App\Enums\RegistrationStatus::tryFrom('pending_lottery')?->value ?? 'accepted']);

        return $s;
    });
    $this->family1 = $this->students->take(3)->pluck('id');
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
});

/** student id => lesson id (no flatMap: it re-indexes numeric keys). */
function lessonOf(array $detail): array
{
    $out = [];
    foreach ($detail['teachers'] as $t) {
        foreach ($t['students'] as $s) {
            $out[$s['student']['id']] = $t['lesson']['id'];
        }
    }
    ksort($out);

    return $out;
}

function makeLottery(array $caps = [5, 5], array $opts = []): array
{
    $t = test();
    return $t->postJson('/api/lotteries', [
        'package_id' => $t->pkg->id, 'name' => 'قرعة الفصل الأول', 'keep_siblings' => true,
        'teachers' => [
            ['teacher_id' => $t->t1->id, 'lesson_id' => $t->l1->id, 'capacity' => $caps[0]],
            ['teacher_id' => $t->t2->id, 'lesson_id' => $t->l2->id, 'capacity' => $caps[1]],
        ],
    ] + $opts)->assertCreated()->json('data');
}

it('builds the pool from accepted students and respects capacities', function () {
    $lottery = makeLottery([6, 3]);
    expect($lottery['pool'])->toHaveCount(10);

    $d = test()->postJson("/api/lotteries/{$lottery['id']}/run", ['seed' => 'abc'])->assertOk()->json();
    $byTeacher = collect($d['data']['teachers'])->keyBy('lottery_teacher_id');
    expect(collect($d['data']['teachers'])->every(fn ($t) => $t['assigned'] <= $t['capacity']))->toBeTrue()
        ->and(collect($d['data']['teachers'])->sum('assigned'))->toBe(9)
        ->and($d['run']['unassigned'])->toHaveCount(1)
        ->and($byTeacher->count())->toBe(2);
});

it('keeps siblings with the same teacher', function () {
    $lottery = makeLottery([5, 5]);
    foreach (['s1', 's2', 's3', 's4', 's5'] as $seed) {
        $d = test()->postJson("/api/lotteries/{$lottery['id']}/run", ['seed' => $seed])->assertOk()->json('data');
        $lessonOf = lessonOf($d);
        expect($this->family1->map(fn ($id) => $lessonOf[$id])->unique())->toHaveCount(1);
    }
});

it('reproduces a distribution with the same seed and changes it with a new one', function () {
    $lottery = makeLottery([5, 5]);
    $map = fn ($d) => lessonOf($d);

    $a = $map(test()->postJson("/api/lotteries/{$lottery['id']}/run", ['seed' => 'same'])->json('data'));
    $b = $map(test()->postJson("/api/lotteries/{$lottery['id']}/run", ['seed' => 'same'])->json('data'));
    expect($a)->toBe($b);

    $different = false;
    foreach (['x1', 'x2', 'x3', 'x4', 'x5', 'x6'] as $seed) {
        $m = $map(test()->postJson("/api/lotteries/{$lottery['id']}/run", ['seed' => $seed])->json('data'));
        $different = $different || $m !== $a;
    }
    expect($different)->toBeTrue()
        ->and(test()->getJson("/api/lotteries/{$lottery['id']}")->json('data.run_count'))->toBe(8);
});

it('approves once: writes lesson_students and messages students and guardians with teacher and first lesson', function () {
    $lottery = makeLottery([5, 5]);
    test()->postJson("/api/lotteries/{$lottery['id']}/approve")->assertStatus(422); // not run yet
    test()->postJson("/api/lotteries/{$lottery['id']}/run", ['seed' => 'go'])->assertOk();

    $r = test()->postJson("/api/lotteries/{$lottery['id']}/approve")->assertOk()->json('result');
    expect($r['enrolled'])->toBe(10)
        ->and(LessonStudent::whereIn('lesson_id', [$this->l1->id, $this->l2->id])->where('status', 'active')->count())->toBe(10)
        ->and(MessageLog::where('type', 'lottery_result')->count())->toBeGreaterThan(0)
        ->and(MessageLog::where('type', 'lottery_result')->first()->body)->toContain('الشيخ');

    test()->postJson("/api/lotteries/{$lottery['id']}/approve")->assertStatus(422);
    test()->postJson("/api/lotteries/{$lottery['id']}/run")->assertStatus(422);
    expect(LessonStudent::count())->toBe(10);
});

it('balances ages and levels across circles when asked', function () {
    $students = collect(range(1, 12))->map(fn ($i) => ['id' => $i, 'age' => $i <= 6 ? 7 : 12, 'level' => $i % 2 ? 'none' : 'juz_amma', 'family' => ''])->all();
    $out = (new LotteryAllocator)->allocate($students, [['key' => 'a', 'capacity' => 6], ['key' => 'b', 'capacity' => 6]], 'seed', false, true, true);
    $young = collect($out['assignments'])->only(range(1, 6))->countBy();
    expect($young->get('a'))->toBe(3)->and($young->get('b'))->toBe(3);
});

it('never mixes tracks: a boys lottery refuses a female teacher and a girls supervisor cannot see it', function () {
    $female = User::factory()->role('teacher')->create(['gender' => 'female', 'track' => 'female']);
    $girlsLesson = Lesson::factory()->create(['package_id' => $this->pkg->id, 'teacher_id' => $this->t1->id]);
    test()->postJson('/api/lotteries', [
        'package_id' => $this->pkg->id, 'name' => 'x',
        'teachers' => [['teacher_id' => $female->id, 'lesson_id' => $girlsLesson->id, 'capacity' => 5]],
    ])->assertStatus(422)->assertJsonValidationErrors('teachers.0.teacher_id');

    $lottery = makeLottery();
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    test()->getJson("/api/lotteries/{$lottery['id']}")->assertForbidden();
    test()->postJson("/api/lotteries/{$lottery['id']}/run")->assertForbidden();
    expect(test()->getJson('/api/lotteries')->assertOk()->json('data'))->toBe([]);
});
