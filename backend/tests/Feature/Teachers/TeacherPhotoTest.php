<?php

use App\Models\Media;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('ahl.media.disk', 'local');
    $this->teacher = User::factory()->role('teacher')->create(['name' => 'الشيخ جعفر', 'gender' => 'male', 'track' => 'male']);
});

it('stores a teacher photo, lists it, serves it by signed URL and removes it', function () {
    actingAsRole('super_admin');

    // The account has no teacher record yet: the upload creates it.
    $urls = $this->post("/api/teachers/{$this->teacher->id}/photo", ['photo' => UploadedFile::fake()->image('t.jpg', 800, 600)], ['Accept' => 'application/json'])
        ->assertOk()->json('data');
    expect($urls['profile'])->not->toBeNull()->and($urls['thumb'])->not->toBeNull();

    $record = Teacher::where('user_id', $this->teacher->id)->first();
    expect($record)->not->toBeNull()->and($record->gender->value)->toBe('male')
        ->and(Media::where('model_type', $record->getMorphClass())->where('model_id', $record->id)->count())->toBe(2);

    // Profile and list both carry the photo.
    $this->getJson("/api/teachers/{$this->teacher->id}")->assertOk()->assertJsonPath('data.photo.thumb', fn ($v) => is_string($v));
    $row = collect($this->getJson('/api/teachers?stats=1')->assertOk()->json('data'))->firstWhere('id', $this->teacher->id);
    expect($row['photo_url'])->toBeString();

    // The signed URL serves WebP without a token; an unsigned one is refused.
    $img = $this->get($urls['profile'])->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect(getimagesizefromstring($img->getContent())[0])->toBe(512);
    $this->get(strtok($urls['thumb'], '?'))->assertForbidden();

    // Replacing keeps exactly two variants.
    $this->post("/api/teachers/{$this->teacher->id}/photo", ['photo' => UploadedFile::fake()->image('t2.png', 400, 400)], ['Accept' => 'application/json'])->assertOk();
    expect(Media::where('model_type', $record->getMorphClass())->where('model_id', $record->id)->count())->toBe(2);

    $this->deleteJson("/api/teachers/{$this->teacher->id}/photo")->assertOk();
    $this->getJson("/api/teachers/{$this->teacher->id}")->assertOk()->assertJsonPath('data.photo.profile', null);
    expect(Media::where('model_type', $record->getMorphClass())->where('model_id', $record->id)->count())->toBe(0);
});

it('lets only teacher managers of the same track change the photo', function () {
    $photo = fn () => ['photo' => UploadedFile::fake()->image('t.jpg')];

    // The teacher can see their own profile but not change the photo.
    $this->actingAs($this->teacher, 'sanctum');
    $this->post("/api/teachers/{$this->teacher->id}/photo", $photo(), ['Accept' => 'application/json'])->assertForbidden();

    // A girls'-track supervisor cannot manage a boys'-track teacher.
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female'])->givePermissionTo('teachers.manage');
    $this->post("/api/teachers/{$this->teacher->id}/photo", $photo(), ['Accept' => 'application/json'])->assertForbidden();

    // Not an image.
    actingAsRole('super_admin');
    $this->post("/api/teachers/{$this->teacher->id}/photo", ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertStatus(422);
});
