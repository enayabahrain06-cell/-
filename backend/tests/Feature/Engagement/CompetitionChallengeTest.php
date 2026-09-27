<?php

use App\Models\Attendance;
use App\Models\Badge;
use App\Models\Certificate;
use App\Models\Challenge;
use App\Models\Competition;
use App\Models\Evaluation;
use App\Models\HonorPoint;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\Student;
use App\Models\StudentBadge;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\Engagement\ChallengeService;
use App\Services\Engagement\CompetitionService;
use Database\Seeders\BadgeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(BadgeSeeder::class);
    $this->travelTo(Carbon::parse('2026-09-20 09:00:00', 'UTC'));
    $this->pkg = Package::factory()->create(['gender' => 'male']);
    $this->lesson = Lesson::factory()->create(['package_id' => $this->pkg->id, 'teacher_id' => User::factory()->role('teacher')->create(['gender' => 'male'])->id]);
    $this->boys = collect(range(1, 4))->map(function ($i) {
        $s = Student::factory()->male()->create(['birth_date' => now()->subYears(8 + $i)->toDateString()]);
        LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $s->id, 'joined_at' => '2026-09-01', 'status' => 'active']);

        return $s;
    });
    $this->sup = actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
});

function competitionPayload(array $over = []): array
{
    return array_replace([
        'name_ar' => 'مسابقة جزء عمّ', 'name_en' => 'Juz Amma contest', 'gender' => 'male', 'type' => 'memorization', 'scope' => 'authority',
        'min_age' => 8, 'max_age' => 14,
        'registration_opens_at' => '2026-09-15T08:00', 'registration_closes_at' => '2026-09-25T20:00',
        'starts_at' => '2026-09-26T16:00', 'ends_at' => '2026-09-30T20:00',
        'tie_break' => 'last_round', 'criteria' => CompetitionService::DEFAULT_CRITERIA,
        'rounds' => [['name' => 'التصفيات', 'round_date' => '2026-09-26'], ['name' => 'النهائي', 'round_date' => '2026-09-30']],
        'prizes' => [['rank' => 1, 'title' => 'المركز الأول', 'points' => 20, 'badge_id' => Badge::where('key', 'competition_winner')->value('id')], ['rank' => 2, 'title' => 'المركز الثاني', 'points' => 10]],
    ], $over);
}

function openCompetition(array $over = []): Competition
{
    $id = test()->postJson('/api/competitions', competitionPayload($over))->assertCreated()->json('data.id');
    test()->postJson("/api/competitions/{$id}/status", ['status' => 'open'])->assertOk();

    return Competition::find($id);
}

it('stores competition times in UTC from Bahrain local input', function () {
    $c = openCompetition();
    expect($c->registration_opens_at->format('Y-m-d H:i'))->toBe('2026-09-15 05:00');
});

it('checks eligibility by age, gender and scope', function () {
    $c = openCompetition(['min_age' => 10, 'max_age' => 11]);
    $svc = app(CompetitionService::class);
    $ages = $this->boys->map(fn ($s) => $s->birth_date->diffInYears($c->starts_at));
    $girl = Student::factory()->female()->create(['birth_date' => now()->subYears(10)->toDateString()]);
    $outsider = Student::factory()->male()->create(['birth_date' => now()->subYears(10)->toDateString()]); // no active circle

    expect($svc->eligibility($c, $this->boys[1])['eligible'])->toBe($ages[1] >= 10 && $ages[1] <= 11)
        ->and($svc->eligibility($c, $this->boys[3])['reason'])->toBe('age')
        ->and($svc->eligibility($c, $girl)['reason'])->toBe('gender')
        ->and($svc->eligibility($c, $outsider)['reason'])->toBe('scope');

    $other = Lesson::factory()->create(['package_id' => $this->pkg->id]);
    $circle = openCompetition(['scope' => 'circle', 'scope_lesson_id' => $other->id, 'min_age' => null, 'max_age' => null]);
    expect($svc->eligibility($circle, $this->boys[0])['reason'])->toBe('scope');
});

it('enforces the registration window for students but lets managers register late', function () {
    $c = openCompetition(['min_age' => null, 'max_age' => null]);
    $user = User::factory()->create();
    $this->boys[0]->update(['user_id' => $user->id]);
    $user->assignRole('student');

    $this->actingAs($user, 'sanctum');
    $this->getJson('/api/my/competitions')->assertOk()->assertJsonCount(1, 'data.open');
    $this->postJson("/api/my/competitions/{$c->id}/register")->assertCreated();

    $this->travelTo(Carbon::parse('2026-09-26 09:00:00', 'UTC'));
    $late = User::factory()->create();
    $late->assignRole('student');
    $this->boys[1]->update(['user_id' => $late->id]);
    $this->actingAs($late, 'sanctum');
    $this->postJson("/api/my/competitions/{$c->id}/register")->assertUnprocessable();

    $this->actingAs($this->sup, 'sanctum');
    $this->postJson("/api/competitions/{$c->id}/participants", ['student_ids' => [$this->boys[1]->id]])->assertOk()->assertJsonPath('data.registered', 1);
});

it('accepts judges only from the competition track', function () {
    $c = openCompetition();
    $femaleJudge = User::factory()->role('teacher')->create(['gender' => 'female']);
    $maleJudge = User::factory()->role('teacher')->create(['gender' => 'male']);

    $this->postJson("/api/competitions/{$c->id}/judges", ['user_id' => $femaleJudge->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->postJson("/api/competitions/{$c->id}/judges", ['user_id' => $maleJudge->id])->assertOk();
    expect(collect($this->getJson("/api/competitions/{$c->id}/judge-candidates")->json('data'))->pluck('id'))->not->toContain($femaleJudge->id);
});

it('averages judges per round, then rounds, and breaks ties by the last round', function () {
    $c = openCompetition(['min_age' => null, 'max_age' => null]);
    $svc = app(CompetitionService::class);
    [$r1, $r2] = $c->rounds()->orderBy('sort_order')->get()->all();
    $j1 = User::factory()->role('teacher')->create(['gender' => 'male']);
    $j2 = User::factory()->role('teacher')->create(['gender' => 'male']);
    $svc->addJudge($c, $j1);
    $svc->addJudge($c, $j2);
    [$a, $b, $d] = $this->boys->take(3)->map(fn ($s) => $svc->register($c, $s))->all();
    $all = fn (int $v) => ['accuracy' => $v, 'tajweed' => $v, 'voice' => $v, 'rules' => $v];

    // a: round 1 = (100 + 80) / 2 = 90, round 2 = 70 → final 80
    $svc->score($r1, $a, $j1, $all(10));
    $svc->score($r1, $a, $j2, $all(8));
    $svc->score($r2, $a, $j1, $all(7));
    // b: round 1 = 70, round 2 = 90 → final 80, better last round → first
    $svc->score($r1, $b, $j1, $all(7));
    $svc->score($r2, $b, $j1, $all(9));
    // d: 50 / 50
    $svc->score($r1, $d, $j1, $all(5));
    $svc->score($r2, $d, $j2, $all(5));
    // Weighted total: accuracy 10/10×40 + tajweed 5/10×30 + voice 0 + rules 10/10×10 = 65
    expect($svc->score($r2, $d, $j1, ['accuracy' => 10, 'tajweed' => 5, 'voice' => 0, 'rules' => 10])->total_x100)->toBe(6500);

    $rows = $svc->standings($c->fresh())->keyBy(fn ($r) => $r['participant']->id);
    expect($rows[$a->id]['final_x100'])->toBe(8000)
        ->and($rows[$b->id]['final_x100'])->toBe(8000)
        ->and($rows[$b->id]['rank'])->toBe(1)
        ->and($rows[$a->id]['rank'])->toBe(2)
        ->and($rows[$d->id]['rank'])->toBe(3);

    // Scores outside the criterion range and judges not assigned are refused.
    $stranger = User::factory()->role('teacher')->create(['gender' => 'male']);
    expect(fn () => $svc->score($r1, $a, $j1, $all(11)))->toThrow(ValidationException::class)
        ->and(fn () => $svc->score($r1, $a, $stranger, $all(5)))->toThrow(ValidationException::class);
});

it('hides results until published, then rewards exactly once and locks scoring', function () {
    $c = openCompetition(['min_age' => null, 'max_age' => null]);
    $svc = app(CompetitionService::class);
    $round = $c->rounds()->orderBy('sort_order')->first();
    $judge = User::factory()->role('teacher')->create(['gender' => 'male']);
    $svc->addJudge($c, $judge);
    $p1 = $svc->register($c, $this->boys[0]);
    $p2 = $svc->register($c, $this->boys[1]);
    $svc->score($round, $p1, $judge, ['accuracy' => 9, 'tajweed' => 9, 'voice' => 9, 'rules' => 9]);
    $svc->score($round, $p2, $judge, ['accuracy' => 6, 'tajweed' => 6, 'voice' => 6, 'rules' => 6]);

    $user = User::factory()->create();
    $this->boys[0]->update(['user_id' => $user->id]);
    $user->assignRole('student');
    $this->actingAs($user, 'sanctum');
    $mine = $this->getJson('/api/my/competitions')->assertOk()->json('data.mine.0');
    expect($mine['final_rank'])->toBeNull()->and($mine['published'])->toBeFalse();

    $this->actingAs($this->sup, 'sanctum');
    $this->postJson("/api/competitions/{$c->id}/publish")->assertOk()->assertJsonPath('data.rewarded', 2);
    $this->postJson("/api/competitions/{$c->id}/publish")->assertUnprocessable();
    $svc->publish($c->fresh(), $this->sup); // a second publish through the service must not double-reward

    expect(Certificate::where('competition_id', $c->id)->count())->toBe(2)
        ->and(HonorPoint::where('source_type', Competition::class)->where('student_id', $this->boys[0]->id)->value('points_x100'))->toBe(2000)
        ->and(StudentBadge::where('student_id', $this->boys[0]->id)->count())->toBe(1)
        ->and(MessageLog::where('type', 'competition_result')->count())->toBe(2);
    expect(fn () => $svc->score($round, $p1, $judge, ['accuracy' => 1, 'tajweed' => 1, 'voice' => 1, 'rules' => 1]))->toThrow(ValidationException::class);

    $this->actingAs($user, 'sanctum');
    $this->getJson('/api/my/competitions')->assertOk()->assertJsonPath('data.mine.0.final_rank', 1);
});

it('never shows a competition of the other track', function () {
    $c = openCompetition();
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->getJson('/api/competitions')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/competitions/{$c->id}")->assertForbidden();
    $this->postJson("/api/competitions/{$c->id}/participants", ['student_ids' => [$this->boys[0]->id]])->assertForbidden();
    $this->postJson('/api/competitions', competitionPayload())->assertUnprocessable()->assertJsonValidationErrors('gender');
});

// --- challenges -------------------------------------------------------------------------------

function makeChallenge(array $over = []): Challenge
{
    return Challenge::create(array_replace([
        'name_ar' => 'تحدي', 'gender' => 'male', 'scope' => 'authority', 'goal_type' => 'attendance_days', 'goal_value' => 3,
        'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30', 'status' => 'active', 'reward_points' => 5,
        'reward_badge_id' => Badge::where('key', 'challenge_champion')->value('id'),
    ], $over));
}

it('measures progress for every goal type from existing records', function () {
    $s = $this->boys[0];
    $svc = app(ChallengeService::class);
    $sessions = collect(['2026-09-02', '2026-09-05', '2026-09-08'])->map(fn ($d) => LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => $d, 'status' => 'held']));
    Attendance::create(['lesson_session_id' => $sessions[0]->id, 'student_id' => $s->id, 'status' => 'present']);
    Attendance::create(['lesson_session_id' => $sessions[1]->id, 'student_id' => $s->id, 'status' => 'late']);
    Attendance::create(['lesson_session_id' => $sessions[2]->id, 'student_id' => $s->id, 'status' => 'absent']);
    foreach ([9, 9, 6, 9] as $i => $t) {
        Evaluation::create(['student_id' => $s->id, 'lesson_id' => $this->lesson->id, 'type' => 'daily', 'memorization' => 8, 'tajweed' => $t, 'revision' => 8, 'behavior' => 8, 'evaluated_on' => '2026-09-0'.($i + 2)]);
    }
    StudentProgress::create(['student_id' => $s->id, 'lesson_id' => $this->lesson->id, 'type' => 'memorized', 'surah_number' => 78, 'from_ayah' => 1, 'to_ayah' => 20, 'ayah_count' => 20, 'recorded_on' => '2026-09-03']);
    StudentProgress::create(['student_id' => $s->id, 'lesson_id' => $this->lesson->id, 'type' => 'memorized', 'surah_number' => 78, 'from_ayah' => 15, 'to_ayah' => 25, 'ayah_count' => 11, 'recorded_on' => '2026-09-06']);
    StudentProgress::create(['student_id' => $s->id, 'lesson_id' => $this->lesson->id, 'type' => 'revised', 'surah_number' => 78, 'from_ayah' => 1, 'to_ayah' => 10, 'ayah_count' => 10, 'recorded_on' => '2026-09-06']);

    $m = fn (array $o) => $svc->measure(makeChallenge($o), $s);
    expect($m(['goal_type' => 'attendance_days', 'goal_value' => 3]))->toBe(['value' => 2, 'target' => 3])
        ->and($m(['goal_type' => 'memorize_range', 'goal_value' => 40, 'surah_number' => 78, 'from_ayah' => 1, 'to_ayah' => 40]))->toBe(['value' => 25, 'target' => 40]) // overlap counted once
        ->and($m(['goal_type' => 'revision_range', 'goal_value' => 20, 'surah_number' => 78, 'from_ayah' => 1, 'to_ayah' => 20]))->toBe(['value' => 10, 'target' => 20])
        ->and($m(['goal_type' => 'score_streak', 'goal_value' => 3, 'min_score' => 9, 'score_criterion' => 'tajweed']))->toBe(['value' => 2, 'target' => 3])
        // Records before the challenge started do not count.
        ->and($m(['goal_type' => 'attendance_days', 'goal_value' => 3, 'starts_at' => '2026-09-06']))->toBe(['value' => 0, 'target' => 3]);
});

it('completes a challenge automatically when records arrive and rewards exactly once', function () {
    $c = makeChallenge(['goal_value' => 2]);
    $s = $this->boys[0];
    $p = app(ChallengeService::class)->join($c, $s, $this->sup);
    expect($p->status)->toBe('joined')->and($p->progress_pct)->toBe(0);

    $sessions = collect(['2026-09-02', '2026-09-05'])->map(fn ($d) => LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => $d, 'status' => 'held']));
    Attendance::create(['lesson_session_id' => $sessions[0]->id, 'student_id' => $s->id, 'status' => 'present']);
    expect($p->fresh()->progress_pct)->toBe(50);
    Attendance::create(['lesson_session_id' => $sessions[1]->id, 'student_id' => $s->id, 'status' => 'present']);

    $p = $p->fresh();
    expect($p->status)->toBe('completed')->and($p->rewarded_at)->not->toBeNull();

    app(ChallengeService::class)->reward($p);
    app(ChallengeService::class)->nightly();
    expect(HonorPoint::where('source_type', Challenge::class)->where('student_id', $s->id)->count())->toBe(1)
        ->and(StudentBadge::where('student_id', $s->id)->count())->toBe(1)
        ->and(MessageLog::where('type', 'challenge_completed')->count())->toBe(1);
});

it('sends the halfway and deadline nudges once each', function () {
    $c = makeChallenge(['goal_value' => 4, 'ends_at' => '2026-09-22']);
    $s = $this->boys[0];
    $session = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => '2026-09-02', 'status' => 'held']);
    $session2 = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => '2026-09-03', 'status' => 'held']);
    Attendance::create(['lesson_session_id' => $session->id, 'student_id' => $s->id, 'status' => 'present']);
    Attendance::create(['lesson_session_id' => $session2->id, 'student_id' => $s->id, 'status' => 'present']);
    app(ChallengeService::class)->join($c, $s);

    app(ChallengeService::class)->nightly();
    app(ChallengeService::class)->nightly();
    expect(MessageLog::where('type', 'challenge_nudge')->count())->toBe(1)
        ->and(MessageLog::where('type', 'challenge_deadline')->count())->toBe(1);
});

it('lets students join only open challenges of their own track', function () {
    $girls = makeChallenge(['gender' => 'female']);
    $boys = makeChallenge();
    $draft = makeChallenge(['status' => 'draft']);
    $user = User::factory()->create();
    $this->boys[0]->update(['user_id' => $user->id]);
    $user->assignRole('student');
    $this->actingAs($user, 'sanctum');

    expect(collect($this->getJson('/api/my/challenges')->assertOk()->json('data.open'))->pluck('id')->all())->toBe([$boys->id]);
    $this->postJson("/api/my/challenges/{$girls->id}/join")->assertUnprocessable();
    $this->postJson("/api/my/challenges/{$draft->id}/join")->assertUnprocessable();
    $this->postJson("/api/my/challenges/{$boys->id}/join")->assertCreated()->assertJsonPath('data.participant_status', 'joined');
});

it('never shows a challenge of the other track to staff', function () {
    $c = makeChallenge();
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->getJson('/api/challenges')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/challenges/{$c->id}")->assertForbidden();
    $this->postJson("/api/challenges/{$c->id}/participants", ['student_ids' => [$this->boys[0]->id]])->assertForbidden();
});

it('reports participation, results, completion and top performers per track, and exports', function () {
    $c = openCompetition(['min_age' => null, 'max_age' => null]);
    $svc = app(CompetitionService::class);
    $judge = User::factory()->role('teacher')->create(['gender' => 'male']);
    $svc->addJudge($c, $judge);
    $p = $svc->register($c, $this->boys[0]);
    $svc->score($c->rounds()->first(), $p, $judge, ['accuracy' => 9, 'tajweed' => 9, 'voice' => 9, 'rules' => 9]);
    $svc->publish($c->fresh(), $this->sup, notify: false);
    makeChallenge();

    $d = $this->getJson('/api/reports/engagement?from=2026-09-01&to=2026-09-30')->assertOk()->json();
    $sections = collect($d['sections'])->keyBy('key');
    expect($sections['participation']['rows'])->toHaveCount(1)
        ->and($sections['results']['rows'][0][1])->toBe(1)
        ->and($sections['challenges']['rows'])->toHaveCount(1);
    $this->get('/api/reports/engagement?from=2026-09-01&to=2026-09-30&format=xlsx')->assertOk();

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $girls = collect($this->getJson('/api/reports/engagement?from=2026-09-01&to=2026-09-30')->assertOk()->json('sections'))->keyBy('key');
    expect($girls['participation']['rows'])->toBeEmpty()->and($girls['challenges']['rows'])->toBeEmpty();
});

it('sends registration and round reminders once', function () {
    $c = openCompetition(['min_age' => null, 'max_age' => null]);
    $this->artisan('engagement:run reminders')->assertSuccessful();
    $this->artisan('engagement:run reminders')->assertSuccessful();
    expect(MessageLog::where('type', 'competition_open')->count())->toBe(4);

    app(CompetitionService::class)->register($c, $this->boys[0]);
    $this->travelTo(Carbon::parse('2026-09-25 06:00:00', 'UTC')); // closes 17:00 UTC the same day
    $this->artisan('engagement:run reminders')->assertSuccessful();
    expect(MessageLog::where('type', 'competition_closing')->count())->toBe(3); // the registered boy is not reminded

    $this->artisan('engagement:run reminders')->assertSuccessful(); // round on 26 September: reminder the day before
    expect(MessageLog::where('type', 'competition_round')->count())->toBe(1);
});
