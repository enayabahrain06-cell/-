<?php

use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\Student;
use App\Models\StudentProgress;
use App\Models\User;

function circleWith(string $direction, ?User $teacher = null): array
{
    $teacher ??= User::factory()->role('teacher')->create();
    $package = Package::factory()->create(['memorization_direction' => $direction, 'plan_ayahs' => 100, 'start_date' => today()->subMonth()->toDateString()]);
    $lesson = Lesson::factory()->create(['teacher_id' => $teacher->id, 'package_id' => $package->id]);
    $student = Student::factory()->create();
    LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $student->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);

    return [$teacher, $lesson, $student];
}

it('derives the position from the ledger in a backward circle (An-Nas first)', function () {
    [$teacher, , $student] = circleWith('backward');
    $this->actingAs($teacher, 'sanctum');

    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 114, 'from_ayah' => 1, 'to_ayah' => 6])->assertCreated();
    $res = $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 113, 'from_ayah' => 1, 'to_ayah' => 5])
        ->assertCreated();

    expect($res->json('position.direction'))->toBe('backward')
        ->and($res->json('position.current.surah'))->toBe(113)
        ->and($res->json('position.current.ayah'))->toBe(5)
        ->and($res->json('position.current.juz'))->toBe(30)
        ->and($res->json('position.current.juz_ordinal'))->toBe(1)
        ->and($res->json('position.next.surah'))->toBe(112)
        ->and($res->json('position.next.ayah'))->toBe(1);

    $student->refresh();
    expect($student->progress_surah)->toBe(113)->and($student->progress_juz)->toBe(30)->and($student->memorized_ayahs)->toBe(11);
});

it('derives the position in a forward circle (Al-Fatihah first) and ignores revision entries', function () {
    [$teacher, , $student] = circleWith('forward');
    $this->actingAs($teacher, 'sanctum');

    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 1, 'from_ayah' => 1, 'to_ayah' => 7])->assertCreated();
    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 2, 'from_ayah' => 1, 'to_ayah' => 10])->assertCreated();
    $res = $this->postJson("/api/students/{$student->id}/progress", ['type' => 'revised', 'surah_number' => 114, 'from_ayah' => 1, 'to_ayah' => 6])->assertCreated();

    expect($res->json('position.current'))->toMatchArray(['surah' => 2, 'ayah' => 10, 'juz' => 1, 'juz_ordinal' => 1])
        ->and($res->json('position.next'))->toMatchArray(['surah' => 2, 'ayah' => 11]);
});

it('rejects ranges outside the surah', function () {
    [$teacher, , $student] = circleWith('backward');
    $this->actingAs($teacher, 'sanctum');

    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 1, 'from_ayah' => 1, 'to_ayah' => 8])
        ->assertStatus(422)->assertJsonValidationErrors('to_ayah');
    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 115, 'from_ayah' => 1, 'to_ayah' => 2])
        ->assertStatus(422)->assertJsonValidationErrors('surah_number');
    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 2, 'from_ayah' => 10, 'to_ayah' => 5])
        ->assertStatus(422)->assertJsonValidationErrors('to_ayah');
});

it('builds the juz map, quran percentage and yearly plan percentage', function () {
    [$teacher, , $student] = circleWith('backward');
    $this->actingAs($teacher, 'sanctum');

    // Whole of juz Amma: An-Naba (78) to An-Nas (114) = 564 ayahs.
    for ($s = 78; $s <= 114; $s++) {
        $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => $s, 'from_ayah' => 1, 'to_ayah' => \App\Support\Quran::ayahCount($s)])->assertCreated();
    }
    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 77, 'from_ayah' => 1, 'to_ayah' => 10])->assertCreated();

    $profile = $this->getJson("/api/students/{$student->id}/profile")->assertOk()->json('data.progress');
    $map = collect($profile['juz_map']);

    expect($map->first())->toMatchArray(['juz' => 30, 'ordinal' => 1, 'status' => 'memorized', 'memorized' => 564, 'total' => 564])
        ->and($map->firstWhere('juz', 29))->toMatchArray(['status' => 'in_progress', 'memorized' => 10])
        ->and($map->firstWhere('juz', 1)['status'])->toBe('not_started')
        ->and($profile['memorized_ayahs'])->toBe(574)
        ->and($profile['completed_juz'])->toBe(1)
        ->and($profile['quran_percent'])->toBe(9.2)
        ->and($profile['plan']['target_ayahs'])->toBe(100)
        ->and($profile['plan']['percent'])->toBe(100)
        ->and($profile['position']['current'])->toMatchArray(['surah' => 77, 'ayah' => 10, 'juz' => 29, 'juz_ordinal' => 2]);
});

it('recomputes the position when an entry is removed', function () {
    [$teacher, , $student] = circleWith('backward');
    $this->actingAs($teacher, 'sanctum');

    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 114, 'from_ayah' => 1, 'to_ayah' => 6])->assertCreated();
    $id = $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 113, 'from_ayah' => 1, 'to_ayah' => 5])->json('entry.id');

    $this->deleteJson("/api/progress/{$id}")->assertOk();

    expect($student->fresh()->progress_surah)->toBe(114)->and($student->fresh()->memorized_ayahs)->toBe(6);
});

it('appends ledger entries from the attendance sheet in the same save', function () {
    [$teacher, $lesson, $student] = circleWith('backward');
    $session = LessonSession::factory()->create(['lesson_id' => $lesson->id, 'session_date' => today()->toDateString()]);
    $this->actingAs($teacher, 'sanctum');

    $this->putJson("/api/sessions/{$session->id}/attendance", ['records' => [[
        'student_id' => $student->id,
        'status' => 'present',
        'progress' => [
            ['type' => 'memorized', 'surah_number' => 114, 'from_ayah' => 1, 'to_ayah' => 6],
            ['type' => 'revised', 'surah_number' => 1, 'from_ayah' => 1, 'to_ayah' => 7],
        ],
    ]]])->assertOk();

    expect(StudentProgress::where('student_id', $student->id)->count())->toBe(2)
        ->and(StudentProgress::where('student_id', $student->id)->where('type', 'memorized')->first()->lesson_id)->toBe($lesson->id)
        ->and($student->fresh()->progress_surah)->toBe(114);
});

it('lets the guardian read the profile but not write to the ledger, and blocks other teachers', function () {
    [$teacher, , $student] = circleWith('backward');
    $guardian = User::factory()->withoutPassword()->create(['locale' => 'en']);
    $guardian->assignRole('guardian');
    $student->update(['guardian_user_id' => $guardian->id]);

    $this->actingAs($guardian, 'sanctum');
    $res = $this->getJson("/api/students/{$student->id}/profile")->assertOk();
    expect($res->json('meta.read_only'))->toBeTrue()
        ->and($res->json('meta.locale'))->toBe('en')
        ->and($res->json('data.progress.position.next.surah_name'))->toBe('An-Nas');
    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 114, 'from_ayah' => 1, 'to_ayah' => 6])->assertForbidden();

    $this->actingAs(User::factory()->role('teacher')->create(), 'sanctum');
    $this->getJson("/api/students/{$student->id}/profile")->assertForbidden();
    $this->postJson("/api/students/{$student->id}/progress", ['type' => 'memorized', 'surah_number' => 114, 'from_ayah' => 1, 'to_ayah' => 6])->assertForbidden();
});

it('lists the surahs for the picker in the request language', function () {
    actingAsRole('teacher');

    $res = $this->getJson('/api/quran/surahs', ['Accept-Language' => 'ar'])->assertOk();
    expect($res->json('data'))->toHaveCount(114)
        ->and($res->json('data.0.name'))->toBe('الفاتحة')
        ->and($res->json('data.113'))->toMatchArray(['number' => 114, 'ayah_count' => 6, 'juz_start' => 30])
        ->and($res->json('juz_starts'))->toHaveCount(30);
});
