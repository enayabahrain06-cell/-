<?php

use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Location;
use App\Models\LocationBooking;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Media\StudentPhotoService;
use App\Services\SettingsService;
use App\Services\Wallet\WalletService;

beforeEach(function () {
    $this->boys = Package::factory()->create(['gender' => 'male', 'min_age' => 6, 'max_age' => 16, 'status' => 'open', 'start_date' => now()->addMonth()->toDateString()]);
    $this->girls = Package::factory()->girls()->create(['min_age' => 6, 'max_age' => 16, 'status' => 'open', 'start_date' => now()->addMonth()->toDateString()]);
    $this->maleTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->femaleTeacher = User::factory()->role('teacher')->create(['gender' => 'female', 'track' => 'female']);
});

function lessonPayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'حلقة', 'days' => ['sun', 'tue'], 'start_time' => '16:00', 'end_time' => '17:00',
        'capacity' => 10, 'start_date' => today()->toDateString(),
    ];
}

it('refuses a male teacher for a girls circle, even through the API', function () {
    actingAsRole('super_admin');

    $this->postJson('/api/lessons', lessonPayload(['package_id' => $this->girls->id, 'teacher_id' => $this->maleTeacher->id]))
        ->assertStatus(422)->assertJsonValidationErrors('teacher_id');

    $res = $this->postJson('/api/lessons', lessonPayload(['package_id' => $this->girls->id, 'teacher_id' => $this->femaleTeacher->id]))->assertCreated();
    expect($res->json('data.gender'))->toBe('female');

    // Switching an existing girls circle to a male teacher is refused as well.
    $this->putJson('/api/lessons/'.$res->json('data.id'), ['teacher_id' => $this->maleTeacher->id])
        ->assertStatus(422)->assertJsonValidationErrors('teacher_id');
});

it('refuses a girl registering in a boys package by direct API call, and lists only matching packages', function () {
    $this->postJson('/api/public/registrations', [
        'package_id' => $this->boys->id,
        'full_name' => 'مريم علي الدوسري',
        'birth_date' => now()->subYears(10)->toDateString(),
        'gender' => 'female',
        'guardian_name' => 'علي الدوسري',
        'guardian_phone' => '36111222',
        'memorization_level' => 'none',
        'locale' => 'ar',
    ])->assertStatus(422)->assertJsonValidationErrors('package_id');

    $this->getJson('/api/public/packages')->assertStatus(422)->assertJsonValidationErrors('gender');
    $ids = collect($this->getJson('/api/public/packages?gender=female')->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toBe([$this->girls->id]);

    // "Mixed" packages no longer exist.
    actingAsRole('super_admin');
    $this->postJson('/api/packages', [
        'name' => 'باقة', 'min_age' => 7, 'max_age' => 12, 'gender' => 'mixed', 'seats' => 10, 'price' => '5',
        'days' => ['sun'], 'start_time' => '16:00', 'end_time' => '17:00', 'start_date' => now()->addMonth()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors('gender');
});

it('scopes a female-track supervisor to girls: students, lessons, packages, payments', function () {
    $boy = Student::factory()->male()->create();
    $girl = Student::factory()->female()->create();
    $boysLesson = Lesson::factory()->create(['package_id' => $this->boys->id, 'teacher_id' => $this->maleTeacher->id]);
    $girlsLesson = Lesson::factory()->create(['package_id' => $this->girls->id, 'teacher_id' => $this->femaleTeacher->id]);
    app(WalletService::class)->ensure($boy);
    app(WalletService::class)->ensure($girl);
    $admin = User::factory()->create();
    foreach ([$boy, $girl] as $s) {
        Payment::create(['receipt_no' => 'R-'.$s->id, 'student_id' => $s->id, 'amount_fils' => 1000, 'method' => 'cash', 'received_by' => $admin->id, 'paid_at' => now()]);
    }

    actingAsRole('supervisor', ['name' => 'أ. فاطمة الشيخ', 'gender' => 'female', 'track' => 'female']);

    $this->getJson("/api/students/{$boy->id}")->assertForbidden();
    $this->getJson("/api/students/{$girl->id}")->assertOk();
    expect(collect($this->getJson('/api/students')->assertOk()->json('data'))->pluck('id')->all())->toBe([$girl->id]);

    $this->getJson("/api/lessons/{$boysLesson->id}")->assertForbidden();
    expect(collect($this->getJson('/api/lessons')->json('data'))->pluck('id')->all())->toBe([$girlsLesson->id]);

    $this->getJson("/api/packages/{$this->boys->id}")->assertForbidden();
    expect(collect($this->getJson('/api/packages')->json('data'))->pluck('id')->all())->toBe([$this->girls->id]);

    expect(collect($this->getJson('/api/payments')->assertOk()->json('data'))->pluck('student.id')->all())->toBe([$girl->id]);
    $this->getJson("/api/students/{$boy->id}/wallet")->assertForbidden();

    // Cannot create in the other track.
    $this->postJson('/api/lessons', lessonPayload(['package_id' => $this->boys->id, 'teacher_id' => $this->maleTeacher->id]))
        ->assertStatus(422)->assertJsonValidationErrors('gender');

    // The Super Admin always sees both tracks.
    actingAsRole('super_admin', ['track' => 'female']);
    $this->getJson("/api/students/{$boy->id}")->assertOk();
    expect($this->getJson('/api/students')->json('data'))->toHaveCount(2);
});

it('enforces hall gender and treats a booking by the other gender as occupied in a shared hall', function () {
    $shared = Location::factory()->create(['gender' => 'shared']);
    $boysHall = Location::factory()->create(['gender' => 'male']);
    actingAsRole('super_admin');
    $slot = ['booking_date' => now()->addWeek()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:00', 'title' => 'حفل'];

    $this->postJson('/api/location-bookings', $slot + ['location_id' => $shared->id, 'gender' => 'male'])->assertCreated();
    $this->postJson('/api/location-bookings', ['start_time' => '18:30', 'end_time' => '19:30'] + $slot + ['location_id' => $shared->id, 'gender' => 'female'])
        ->assertStatus(422);
    // Adjacent slot for the other gender is fine.
    $this->postJson('/api/location-bookings', ['start_time' => '19:00', 'end_time' => '20:00'] + $slot + ['location_id' => $shared->id, 'gender' => 'female'])
        ->assertCreated();

    // A boys-only hall never takes a girls booking or a girls circle.
    $this->postJson('/api/location-bookings', ['booking_date' => now()->addWeeks(2)->toDateString()] + $slot + ['location_id' => $boysHall->id, 'gender' => 'female'])
        ->assertStatus(422)->assertJsonValidationErrors('location_id');
    $this->postJson('/api/lessons', lessonPayload(['package_id' => $this->girls->id, 'teacher_id' => $this->femaleTeacher->id, 'location_id' => $boysHall->id]))
        ->assertStatus(422)->assertJsonValidationErrors('location_id');

    // A shared hall used by girls cannot be limited to boys.
    $this->putJson("/api/locations/{$shared->id}", ['gender' => 'male'])->assertStatus(422)->assertJsonValidationErrors('gender');
    expect(LocationBooking::where('location_id', $shared->id)->count())->toBe(2);
});

it('shows a girl photo only to female staff, the Super Admin and her guardian; never to male staff', function () {
    $guardian = User::factory()->withoutPassword()->create(['gender' => 'male']);
    $guardian->assignRole('guardian');
    $girl = Student::factory()->female()->create(['guardian_user_id' => $guardian->id, 'photo_path' => 'photos/p.webp', 'photo_thumb_path' => 'photos/t.webp']);

    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'both']);
    $this->getJson("/api/students/{$girl->id}/photo-url")->assertForbidden();
    $list = collect($this->getJson('/api/students')->assertOk()->json('data'))->firstWhere('id', $girl->id);
    expect($list['has_photo'])->toBeTrue()->and($list['photo_url'])->toBeNull()->and($list['initial'])->not->toBe('');

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->getJson("/api/students/{$girl->id}/photo-url")->assertOk();

    actingAsRole('super_admin', ['gender' => 'male']);
    $this->getJson("/api/students/{$girl->id}/photo-url")->assertOk();

    $this->actingAs($guardian, 'sanctum');
    $this->getJson("/api/students/{$girl->id}/photo-url")->assertOk();

    // Teachers: a female teacher of her circle sees it.
    $lesson = Lesson::factory()->create(['package_id' => $this->girls->id, 'teacher_id' => $this->femaleTeacher->id]);
    LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $girl->id, 'joined_at' => today(), 'status' => 'active']);
    $this->actingAs($this->femaleTeacher, 'sanctum');
    $this->getJson("/api/students/{$girl->id}/photo-url")->assertOk();
});

it('prints girls photos on rosters and certificates only when print_female_photos is on', function () {
    $girl = Student::factory()->female()->create();
    $boy = Student::factory()->male()->create();

    expect(StudentPhotoService::mayPrint($girl))->toBeFalse()->and(StudentPhotoService::mayPrint($boy))->toBeTrue();
    app(SettingsService::class)->set('media.print_female_photos', true, 'media', 'bool');
    expect(StudentPhotoService::mayPrint($girl->fresh()))->toBeTrue();
});

it('gives new teachers the track of their gender and requires a teacher gender', function () {
    actingAsRole('super_admin');

    $this->postJson('/api/admin/users', ['name' => 'أ. نورة', 'phone' => '36000077', 'password' => 'secret123', 'roles' => ['teacher']])
        ->assertStatus(422)->assertJsonValidationErrors('teacher.gender');

    $this->postJson('/api/admin/users', ['name' => 'أ. نورة', 'phone' => '36000077', 'password' => 'secret123', 'roles' => ['teacher'], 'teacher' => ['gender' => 'female']])
        ->assertCreated()->assertJsonPath('data.track', 'female')->assertJsonPath('data.teacher.gender', 'female');
});

it('compares the two tracks side by side for the Super Admin only', function () {
    Student::factory()->male()->count(2)->create(['memorized_ayahs' => 100]);
    Student::factory()->female()->count(3)->create(['memorized_ayahs' => 40]);

    actingAsRole('supervisor', ['track' => 'both']);
    $this->getJson('/api/reports/tracks/compare')->assertForbidden();

    actingAsRole('super_admin');
    $rows = collect($this->getJson('/api/reports/tracks/compare')->assertOk()->json('data'))->keyBy('gender');
    expect($rows['male'])->toMatchArray(['active_students' => 2, 'avg_memorized_ayahs' => 100])
        ->and($rows['female'])->toMatchArray(['active_students' => 3, 'avg_memorized_ayahs' => 40]);
});

it('refuses to enrol a boy in a girls circle', function () {
    $lesson = Lesson::factory()->create(['package_id' => $this->girls->id, 'teacher_id' => $this->femaleTeacher->id]);
    $boy = Student::factory()->male()->create();
    actingAsRole('super_admin');

    $this->postJson("/api/lessons/{$lesson->id}/students", ['student_ids' => [$boy->id]])
        ->assertStatus(422)->assertJsonValidationErrors('student_ids');
});
