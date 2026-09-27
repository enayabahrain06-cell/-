<?php

use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Media;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('ahl.media.disk', 'local');
});

function fakePhoto(string $name = 'photo.jpg', int $w = 800, int $h = 600): UploadedFile
{
    return UploadedFile::fake()->image($name, $w, $h);
}

it('stores 512 and 96 px webp variants and discards the original', function () {
    actingAsRole('supervisor');
    $student = Student::factory()->create();

    $this->post("/api/students/{$student->id}/photo", ['photo' => fakePhoto()])
        ->assertOk()
        ->assertJsonPath('data.has_photo', true);

    $student->refresh();
    expect($student->photo_path)->not->toBeNull()->and($student->photo_thumb_path)->not->toBeNull();

    Storage::disk('local')->assertExists($student->photo_path);
    Storage::disk('local')->assertExists($student->photo_thumb_path);

    [$w, $h, , $mime] = array_pad((array) getimagesizefromstring(Storage::disk('local')->get($student->photo_path)), 4, null);
    expect([$w, $h])->toBe([512, 512])->and(getimagesizefromstring(Storage::disk('local')->get($student->photo_path))['mime'])->toBe('image/webp');
    expect(getimagesizefromstring(Storage::disk('local')->get($student->photo_thumb_path))[0])->toBe(96);

    // Only the two variants exist; no JPEG original anywhere.
    expect(Media::where('model_id', $student->id)->count())->toBe(2)
        ->and(Media::where('mime', 'image/jpeg')->exists())->toBeFalse()
        ->and(count(Storage::disk('local')->allFiles()))->toBe(2);
});

it('replaces an existing photo instead of accumulating files', function () {
    actingAsRole('supervisor');
    $student = Student::factory()->create();

    $this->post("/api/students/{$student->id}/photo", ['photo' => fakePhoto()])->assertOk();
    $this->post("/api/students/{$student->id}/photo", ['photo' => fakePhoto('b.png')])->assertOk();

    expect(Media::where('model_id', $student->id)->count())->toBe(2)
        ->and(count(Storage::disk('local')->allFiles()))->toBe(2);
});

it('rejects wrong types and oversized files', function () {
    actingAsRole('supervisor');
    $student = Student::factory()->create();

    $this->post("/api/students/{$student->id}/photo", ['photo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])
        ->assertStatus(422)->assertJsonValidationErrors('photo');

    $this->post("/api/students/{$student->id}/photo", ['photo' => UploadedFile::fake()->create('big.jpg', 6 * 1024, 'image/jpeg')])
        ->assertStatus(422)->assertJsonValidationErrors('photo');
});

it('removes the photo and clears the cached paths', function () {
    actingAsRole('supervisor');
    $student = Student::factory()->create();
    $this->post("/api/students/{$student->id}/photo", ['photo' => fakePhoto()])->assertOk();

    $this->deleteJson("/api/students/{$student->id}/photo")->assertOk();

    expect($student->fresh()->photo_path)->toBeNull()
        ->and(Media::where('model_id', $student->id)->count())->toBe(0)
        ->and(count(Storage::disk('local')->allFiles()))->toBe(0);
});

it('serves the photo through a signed url that expires after 10 minutes', function () {
    actingAsRole('supervisor');
    $student = Student::factory()->create();
    $this->post("/api/students/{$student->id}/photo", ['photo' => fakePhoto()])->assertOk();

    $urls = $this->getJson("/api/students/{$student->id}/photo-url")->assertOk()->json();
    expect($urls['thumb'])->toContain('signature=')->and($urls['has_photo'])->toBeTrue();

    // Anonymous fetch with a valid signature works.
    $this->app['auth']->forgetGuards();
    $this->get($urls['thumb'])->assertOk()->assertHeader('Content-Type', 'image/webp');

    // Tampered URL fails.
    $this->get($urls['thumb'].'x')->assertStatus(403);

    // Expired after 11 minutes.
    $this->travel(11)->minutes();
    $this->get($urls['thumb'])->assertStatus(403);
});

it('returns 404 from the signed route when the student has no photo', function () {
    $student = Student::factory()->create();
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('media.student-photo', now()->addMinutes(5), ['student' => $student->id, 'size' => 'thumb']);

    $this->get($url)->assertNotFound();
});

it('reports has_photo false and an initial when there is no photo', function () {
    actingAsRole('supervisor');
    $student = Student::factory()->create(['full_name' => 'أحمد محمد الجودر']);

    $this->getJson("/api/students/{$student->id}/photo-url")
        ->assertOk()
        ->assertJsonPath('has_photo', false)
        ->assertJsonPath('initial', 'أ')
        ->assertJsonPath('thumb', null);
});

it('lets a guardian see and edit only their own child', function () {
    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $own = Student::factory()->create(['guardian_user_id' => $guardian->id, 'guardian_phone' => $guardian->phone]);
    $other = Student::factory()->create();

    $this->actingAs($guardian, 'sanctum');
    $this->getJson("/api/students/{$own->id}/photo-url")->assertOk();
    $this->getJson("/api/students/{$other->id}/photo-url")->assertForbidden();

    $this->post("/api/students/{$own->id}/photo", ['photo' => fakePhoto()])->assertOk();
    $this->post("/api/students/{$other->id}/photo", ['photo' => fakePhoto()])->assertForbidden();
});

it('lets a teacher see photos only of students enrolled in their circles', function () {
    $teacher = actingAsRole('teacher');
    $lesson = Lesson::factory()->create(['teacher_id' => $teacher->id]);
    $enrolled = Student::factory()->create();
    $stranger = Student::factory()->create();
    LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $enrolled->id, 'joined_at' => now()->toDateString(), 'status' => 'active']);

    $this->getJson("/api/students/{$enrolled->id}/photo-url")->assertOk();
    $this->getJson("/api/students/{$stranger->id}/photo-url")->assertForbidden();
    // Teachers cannot upload.
    $this->post("/api/students/{$enrolled->id}/photo", ['photo' => fakePhoto()])->assertForbidden();
});

it('streams generic media only to authorised users', function () {
    $supervisor = actingAsRole('supervisor');
    $student = Student::factory()->create();
    $this->post("/api/students/{$student->id}/photo", ['photo' => fakePhoto()])->assertOk();
    $media = Media::where('model_id', $student->id)->where('collection', 'photo')->first();

    $this->get("/api/media/{$media->id}")->assertOk()->assertHeader('Content-Type', 'image/webp');

    $outsider = User::factory()->withoutPassword()->create();
    $outsider->assignRole('guardian');
    $this->actingAs($outsider, 'sanctum')->get("/api/media/{$media->id}")->assertForbidden();
});
