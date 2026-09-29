<?php

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $this->teacher = User::factory()->role('teacher')->create();
    $this->lesson = Lesson::factory()->create(['teacher_id' => $this->teacher->id, 'name' => 'حلقة النور']);
    $this->a = Student::factory()->create(['full_name' => 'أحمد علي', 'progress_juz' => 30, 'progress_surah' => 112, 'progress_ayah' => 4, 'memorized_ayahs' => 40]);
    $this->b = Student::factory()->create(['full_name' => 'بدر حسن', 'memorized_ayahs' => 0]);
    LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $this->a->id, 'joined_at' => today(), 'status' => 'active']);
});

it('lists students with circle, cached position, juz filter and sorting', function () {
    actingAsRole('supervisor');

    $rows = collect($this->getJson('/api/students?sort=memorized')->assertOk()->json('data'));
    expect($rows->pluck('id')->all())->toBe([$this->a->id, $this->b->id])
        ->and($rows[0]['circle'])->toMatchArray(['name' => 'حلقة النور'])
        ->and($rows[0]['progress'])->toMatchArray(['juz' => 30, 'surah' => 112, 'surah_name' => 'الإخلاص', 'memorized_ayahs' => 40])
        ->and($rows[1]['circle'])->toBeNull();

    expect(collect($this->getJson('/api/students?juz=30')->json('data'))->pluck('id')->all())->toBe([$this->a->id])
        ->and(collect($this->getJson('/api/students?juz=0')->json('data'))->pluck('id')->all())->toBe([$this->b->id]);
});

it('filters by an inclusive age band and sorts youngest first', function () {
    actingAsRole('supervisor');
    $this->a->update(['birth_date' => today()->subYears(8)->toDateString()]);              // 8 today
    $this->b->update(['birth_date' => today()->subYears(11)->addDay()->toDateString()]);   // 10, turns 11 tomorrow
    $c = Student::factory()->create(['birth_date' => today()->subYears(11)->toDateString()]); // 11 today

    $ids = fn (string $qs) => collect($this->getJson("/api/students?$qs")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('age_min=8&age_max=10'))->toEqualCanonicalizing([$this->a->id, $this->b->id])
        ->and($ids('age_min=11'))->toBe([$c->id])
        ->and($ids('age_max=7'))->toBe([])
        ->and($ids('sort=age'))->toBe([$this->a->id, $this->b->id, $c->id]);
});

it('returns a student attendance history with totals to staff, the teacher and the guardian only', function () {
    foreach (['present', 'late', 'absent', 'excused'] as $i => $status) {
        $s = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->subDays($i)->toDateString()]);
        Attendance::create(['lesson_session_id' => $s->id, 'student_id' => $this->a->id, 'status' => $status]);
    }

    $this->actingAs($this->teacher, 'sanctum');
    $res = $this->getJson("/api/students/{$this->a->id}/attendance")->assertOk();
    expect($res->json('totals'))->toMatchArray(['present' => 1, 'late' => 1, 'absent' => 1, 'excused' => 1, 'percent' => 67])
        ->and($res->json('data.0'))->toMatchArray(['status' => 'present', 'lesson' => 'حلقة النور', 'date' => today()->toDateString()]);

    $this->getJson("/api/students/{$this->b->id}/attendance")->assertForbidden(); // not in this teacher's circle

    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $this->a->update(['guardian_user_id' => $guardian->id]);
    $this->actingAs($guardian, 'sanctum');
    $this->getJson("/api/students/{$this->a->id}/attendance?status=absent")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/students/{$this->b->id}/attendance")->assertForbidden();
});
