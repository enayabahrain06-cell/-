<?php

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Evaluation;
use App\Models\HonorPeriod;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\Student;
use App\Models\StudentBadge;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\Engagement\HonorService;
use App\Services\SettingsService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Support\Carbon;

/*
 * Honor board (section 13): points = attendance% × 40 + evaluation avg/10 × 40 + new ayahs / best × 20 (+ bonus),
 * ranked per track, package and circle; one board per month and per gender; never mixed.
 */

beforeEach(function () {
    $this->seed(BadgeSeeder::class);
    $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
    $this->period = '2026-09';
});

/** A circle in a package of the given gender with N students and 4 sessions this month. */
function honorCircle(string $gender, int $students, string $name = 'حلقة'): array
{
    $pkg = $gender === 'female' ? Package::factory()->create(['gender' => 'female']) : Package::factory()->create();
    $teacher = User::factory()->role('teacher')->create(['gender' => $gender]);
    $lesson = Lesson::factory()->create(['package_id' => $pkg->id, 'teacher_id' => $teacher->id, 'name' => $name]);
    $kids = collect(range(1, $students))->map(function () use ($gender, $lesson) {
        $s = Student::factory()->{$gender}()->create();
        LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $s->id, 'joined_at' => '2026-09-01', 'status' => 'active']);

        return $s;
    });
    $sessions = collect(['2026-09-02', '2026-09-06', '2026-09-09', '2026-09-13'])
        ->map(fn ($d) => LessonSession::factory()->create(['lesson_id' => $lesson->id, 'session_date' => $d, 'status' => 'held']));

    return compact('pkg', 'lesson', 'kids', 'sessions');
}

/** Attendance on the first $present sessions, absent on the rest; one daily evaluation per session with $score everywhere; $ayahs new ayahs of al-Baqarah. */
function honorRecord(array $c, Student $s, int $present, int $score, int $ayahs): void
{
    foreach ($c['sessions'] as $i => $session) {
        Attendance::create(['lesson_session_id' => $session->id, 'student_id' => $s->id, 'status' => $i < $present ? 'present' : 'absent']);
        if ($i < $present) {
            Evaluation::create(['student_id' => $s->id, 'lesson_id' => $c['lesson']->id, 'lesson_session_id' => $session->id, 'type' => 'daily',
                'memorization' => $score, 'tajweed' => $score, 'revision' => $score, 'behavior' => $score, 'evaluated_on' => $session->session_date]);
        }
    }
    if ($ayahs > 0) {
        StudentProgress::create(['student_id' => $s->id, 'lesson_id' => $c['lesson']->id, 'type' => 'memorized', 'surah_number' => 2,
            'from_ayah' => 1, 'to_ayah' => $ayahs, 'ayah_count' => $ayahs, 'recorded_on' => '2026-09-10']);
    }
}

it('computes points from attendance, evaluation and memorization and ranks them', function () {
    $c = honorCircle('male', 3);
    [$a, $b, $d] = $c['kids']->all();
    honorRecord($c, $a, 4, 10, 10); // 100% · 10/10 · best memorizer → 40 + 40 + 20 = 100
    honorRecord($c, $b, 2, 5, 5);   // 50% · 5/10 · half of best    → 20 + 20 + 10 = 50
    honorRecord($c, $d, 2, 5, 5);   // same as b: shares rank 2

    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $board = $this->postJson('/api/honor/compute', ['period' => $this->period])->assertOk()->json('data');

    $rows = collect($board['rows'])->keyBy('student.id');
    expect($rows[$a->id]['points'])->toEqual(100)
        ->and($rows[$a->id]['breakdown'])->toEqual(['attendance' => 40, 'evaluation' => 40, 'memorization' => 20, 'bonus' => 0])
        ->and($rows[$b->id]['points'])->toEqual(50)
        ->and($rows[$a->id]['rank'])->toBe(1)
        ->and($rows[$b->id]['rank'])->toBe(2)
        ->and($rows[$d->id]['rank'])->toBe(2) // standard competition ranking 1, 2, 2
        ->and($rows[$a->id]['rank_in_circle'])->toBe(1)
        ->and($board['circle_of_month']['id'])->toBe($c['lesson']->id);
});

it('keeps boys and girls on separate boards and hides the other track', function () {
    $boys = honorCircle('male', 2);
    $girls = honorCircle('female', 2);
    foreach ($boys['kids'] as $s) {
        honorRecord($boys, $s, 4, 9, 3);
    }
    foreach ($girls['kids'] as $s) {
        honorRecord($girls, $s, 4, 10, 3);
    }
    $svc = app(HonorService::class);
    $svc->compute($this->period, 'male');
    $svc->compute($this->period, 'female');

    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    // Asking for the girls' board still returns the boys' board: the track is forced server-side.
    $rows = $this->getJson('/api/honor/board?gender=female')->assertOk()->json('data');
    expect($rows['gender'])->toBe('male')
        ->and(collect($rows['rows'])->pluck('student.id')->sort()->values()->all())->toBe($boys['kids']->pluck('id')->sort()->values()->all());

    $girlsPeriod = HonorPeriod::where('gender', 'female')->first();
    $this->postJson("/api/honor/periods/{$girlsPeriod->id}/honor")->assertForbidden();
    $this->postJson("/api/honor/periods/{$girlsPeriod->id}/publish")->assertForbidden();

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $rows = $this->getJson('/api/honor/board')->assertOk()->json('data');
    expect($rows['gender'])->toBe('female')
        ->and(collect($rows['rows'])->pluck('student.id')->intersect($boys['kids']->pluck('id')))->toBeEmpty();
});

it('starts every month from zero and keeps the previous month as history', function () {
    $c = honorCircle('male', 2);
    [$a, $b] = $c['kids']->all();
    honorRecord($c, $a, 4, 10, 10);
    honorRecord($c, $b, 1, 4, 0);
    $svc = app(HonorService::class);
    $sep = $svc->compute('2026-09', 'male');

    $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
    $this->artisan('engagement:run daily')->assertSuccessful();

    expect($sep->fresh()->status)->toBe('finalized')
        ->and($sep->rankings()->where('student_id', $a->id)->value('points_x100'))->toBe(10000);
    $oct = HonorPeriod::where('period', '2026-10')->where('gender', 'male')->first();
    // No October records yet: everyone starts at zero, and the change against September is negative for a.
    expect($oct->rankings()->sum('points_x100'))->toEqual(0)
        ->and($oct->rankings()->where('student_id', $a->id)->value('points_change_x100'))->toBe(-10000);

    // A finalized month is not recomputed by later records.
    Attendance::query()->update(['status' => 'absent']);
    $svc->compute('2026-09', 'male');
    expect($sep->rankings()->where('student_id', $a->id)->value('points_x100'))->toBe(10000);
});

it('awards badges by rule, once per period', function () {
    $c = honorCircle('male', 2);
    [$a, $b] = $c['kids']->all();
    honorRecord($c, $a, 4, 9, 5); // full attendance, tajweed 9 average
    honorRecord($c, $b, 3, 7, 5);
    $svc = app(HonorService::class);
    $svc->compute($this->period, 'male');
    $svc->compute($this->period, 'male');

    $keys = fn (Student $s) => StudentBadge::with('badge')->where('student_id', $s->id)->get()->pluck('badge.key')->sort()->values()->all();
    expect($keys($a))->toBe(['excellent_tajweed', 'full_attendance'])
        ->and($keys($b))->toBe([])
        ->and(StudentBadge::where('student_id', $a->id)->count())->toBe(2)
        ->and(StudentBadge::where('student_id', $a->id)->value('period'))->toBe('2026-09');
});

it('does not give the full attendance badge below the minimum number of sessions', function () {
    app(SettingsService::class)->set('honor.full_attendance_min_sessions', 5);
    $c = honorCircle('male', 1);
    honorRecord($c, $c['kids'][0], 4, 6, 0);
    app(HonorService::class)->compute($this->period, 'male');

    expect(StudentBadge::where('student_id', $c['kids'][0]->id)->count())->toBe(0);
});

it('honors the top three once: certificate drafts, one message each, published board', function () {
    $c = honorCircle('male', 4);
    foreach ($c['kids'] as $i => $s) {
        honorRecord($c, $s, 4 - ($i % 4), 10 - $i, 10 - $i);
    }
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $id = $this->postJson('/api/honor/compute')->assertOk()->json('data.id');

    $this->postJson("/api/honor/periods/{$id}/honor")->assertOk()->assertJsonPath('data.certificates', 3);
    $this->postJson("/api/honor/periods/{$id}/honor")->assertOk()->assertJsonPath('data.certificates', 0);

    expect(Certificate::where('honor_period_id', $id)->count())->toBe(3)
        ->and(Certificate::where('honor_period_id', $id)->pluck('status')->map(fn ($s) => $s->value ?? $s)->unique()->all())->toBe(['draft'])
        ->and(MessageLog::where('type', 'honor_congrats')->count())->toBe(3)
        ->and(HonorPeriod::find($id)->published_to_students)->toBeTrue();
});

it('shows students their board only after publishing', function () {
    $c = honorCircle('male', 2);
    [$a] = $c['kids']->all();
    honorRecord($c, $a, 4, 10, 4);
    $hp = app(HonorService::class)->compute($this->period, 'male');
    $user = User::factory()->create();
    $a->update(['user_id' => $user->id]);
    $user->assignRole('student');
    $this->actingAs($user, 'sanctum');

    $this->getJson('/api/my/honor')->assertOk()->assertJsonPath('data.published', false)->assertJsonPath('data.board', null)->assertJsonPath('data.mine', null);
    $hp->update(['published_to_students' => true]);
    $d = $this->getJson('/api/my/honor')->assertOk()->json('data');
    expect($d['mine']['rank_in_track'])->toBe(1)
        ->and($d['board']['rows'][0])->not->toHaveKey('student.photo_url');
});

it('serves the TV display only with the key, only published boards and only two names', function () {
    $c = honorCircle('female', 2);
    foreach ($c['kids'] as $s) {
        honorRecord($c, $s, 4, 8, 2);
    }
    $hp = app(HonorService::class)->compute($this->period, 'female');

    $this->getJson('/api/public/honor/display?gender=female')->assertForbidden(); // no key configured
    app(SettingsService::class)->set('honor.display_key', 'tv-secret-123');
    $this->getJson('/api/public/honor/display?gender=female&key=wrong')->assertForbidden();
    $this->getJson('/api/public/honor/display?gender=female&key=tv-secret-123')->assertOk()->assertJsonPath('data.board', null);

    $hp->update(['published_to_students' => true]);
    $d = $this->getJson('/api/public/honor/display?gender=female&key=tv-secret-123')->assertOk()->json('data.board');
    expect($d['gender'])->toBe('female')
        ->and($d['rows'])->toHaveCount(2)
        ->and(count(explode(' ', $d['rows'][0]['student']['full_name'])))->toBeLessThanOrEqual(2)
        ->and($d['rows'][0]['student'])->not->toHaveKey('photo_url');

    // The boys' display never shows girls.
    expect($this->getJson('/api/public/honor/display?gender=male&key=tv-secret-123')->json('data.board'))->toBeNull();
});
