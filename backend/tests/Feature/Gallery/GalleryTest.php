<?php

use App\Models\AcademicTerm;
use App\Models\Album;
use App\Models\AlbumPhoto;
use App\Models\AuditLog;
use App\Models\Competition;
use App\Models\CompetitionParticipant;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\Media;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('ahl.media.disk', 'local');
    config()->set('ahl.gallery.video', 'off');
    Cache::flush();

    $this->term = AcademicTerm::create(['name_ar' => 'الفصل الأول', 'name_en' => 'Term 1', 'is_current' => true]);
    $this->level = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1']);
    $this->pkg = Package::factory()->create(['academic_term_id' => $this->term->id]);
    $this->teacher = User::factory()->role('teacher')->create();
    $this->mine = Lesson::factory()->create(['name' => 'صف النور', 'package_id' => $this->pkg->id, 'level_id' => $this->level->id, 'teacher_id' => $this->teacher->id]);
    $this->other = Lesson::factory()->create(['name' => 'صف الهدى', 'package_id' => $this->pkg->id]);

    // A student of each class, each with a guardian account.
    $this->family = function (Lesson $lesson, array $attrs = []) {
        $guardian = User::factory()->role('guardian')->create();
        $student = Student::factory()->male()->create(['guardian_user_id' => $guardian->id] + $attrs);
        LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $student->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);

        return [$student, $guardian];
    };
    [$this->kid, $this->parent] = ($this->family)($this->mine);
    [$this->otherKid, $this->otherParent] = ($this->family)($this->other);

    $this->makeAlbum = fn (array $attrs = []) => Album::create($attrs + [
        'title' => 'رحلة', 'album_date' => today()->toDateString(), 'academic_term_id' => $this->term->id, 'gender' => 'male',
        'link_type' => 'lesson', 'link_id' => $this->mine->id,
    ]);
    $this->jpeg = fn (int $w = 3000, int $h = 2000) => UploadedFile::fake()->image('IMG_0001.jpg', $w, $h);
});

it('lets a teacher create staff-only albums for their own classes; only managers share them', function () {
    $this->actingAs($this->teacher, 'sanctum');
    $opts = $this->getJson('/api/gallery/options')->assertOk();
    expect($opts->json('classes.*.id'))->toBe([$this->mine->id])->and($opts->json('can_manage'))->toBeFalse()->and($opts->json('video_enabled'))->toBeFalse();

    $id = $this->postJson('/api/gallery/albums', ['title' => 'يوم النشاط', 'album_date' => today()->toDateString(), 'link_type' => 'lesson', 'link_id' => $this->mine->id])
        ->assertCreated()->assertJsonPath('data.visibility', 'staff')->assertJsonPath('data.gender', 'male')
        ->assertJsonPath('data.term.id', $this->term->id)->assertJsonPath('data.can.share', false)->json('data.id');
    $this->postJson('/api/gallery/albums', ['title' => 'ليس صفي', 'album_date' => today()->toDateString(), 'link_type' => 'lesson', 'link_id' => $this->other->id])->assertForbidden();
    $this->postJson('/api/gallery/albums', ['title' => 'بلا رابط', 'album_date' => today()->toDateString(), 'gender' => 'male'])->assertForbidden();
    $this->putJson("/api/gallery/albums/{$id}/sharing", ['visibility' => 'all_guardians', 'allow_download' => true])->assertForbidden();
    $this->putJson("/api/gallery/albums/{$id}", ['title' => 'يوم النشاط الأول'])->assertOk();

    actingAsRole('supervisor');
    $this->putJson("/api/gallery/albums/{$id}/sharing", ['visibility' => 'linked', 'allow_download' => false])->assertOk()
        ->assertJsonPath('data.visibility', 'linked');
    expect(Album::find($id)->shared_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'gallery.album_created')->count())->toBe(1)
        ->and(AuditLog::where('action', 'gallery.album_shared')->count())->toBe(1);
});

it('resizes uploads to webp, keeps a thumbnail, records the uploader and never keeps the original', function () {
    $album = ($this->makeAlbum)();
    $this->actingAs($this->teacher, 'sanctum');
    $photo = $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => ($this->jpeg)(), 'caption' => 'في الطريق'])
        ->assertCreated()->assertJsonPath('data.kind', 'photo')->assertJsonPath('data.uploaded_by.id', $this->teacher->id)->json('data');

    expect([$photo['width'], $photo['height']])->toBe([2048, 1365]);
    $media = Media::where('model_type', (new AlbumPhoto)->getMorphClass())->where('model_id', $photo['id'])->get();
    expect($media->pluck('collection.value')->sort()->values()->all())->toBe(['gallery_image', 'gallery_thumb'])
        ->and($media->pluck('mime')->unique()->all())->toBe(['image/webp']);
    $thumb = getimagesizefromstring(Storage::disk('local')->get($media->firstWhere('collection.value', 'gallery_thumb')->path));
    expect([$thumb[0], $thumb[1]])->toBe([480, 480]);
    expect(AuditLog::where('action', 'gallery.photo_uploaded')->where('user_id', $this->teacher->id)->count())->toBe(1);

    // Too large, and not an image.
    config()->set('ahl.gallery.max_upload_mb', 1);
    $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => UploadedFile::fake()->create('big.jpg', 2048, 'image/jpeg')], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();

    // Another teacher cannot upload into it.
    $this->actingAs(User::factory()->role('teacher')->create(), 'sanctum');
    $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => ($this->jpeg)(800, 600)], ['Accept' => 'application/json'])->assertForbidden();
});

it('serves files only to signed-in users allowed to see the album; guardians download only when allowed', function () {
    $album = ($this->makeAlbum)();
    actingAsRole('supervisor');
    $id = $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => ($this->jpeg)(800, 600)])->assertCreated()->json('data.id');

    // Staff-only: the guardian sees nothing yet.
    $this->actingAs($this->parent, 'sanctum');
    expect($this->getJson('/api/me/gallery')->json('data'))->toBe([]);
    $this->get("/api/gallery/photos/{$id}/thumb")->assertForbidden();
    $this->getJson("/api/me/gallery/{$album->id}")->assertNotFound();

    $album->update(['visibility' => 'linked', 'shared_at' => now()]);
    expect($this->getJson('/api/me/gallery')->json('data.*.id'))->toBe([$album->id]);
    $this->getJson("/api/me/gallery/{$album->id}")->assertOk()->assertJsonPath('data.photos.0.id', $id)
        ->assertJsonPath('data.visibility', null)->assertJsonPath('data.photos.0.uploaded_by', null)->assertJsonPath('data.can.download', false);
    $this->get("/api/gallery/photos/{$id}/image")->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get("/api/gallery/photos/{$id}/image?download=1")->assertForbidden();

    $album->update(['allow_download' => true]);
    $res = $this->get("/api/gallery/photos/{$id}/image?download=1")->assertOk();
    expect($res->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and(AuditLog::where('action', 'gallery.photo_downloaded')->where('user_id', $this->parent->id)->count())->toBe(1);

    // A guardian of another class, a teacher (no download), and nobody signed in.
    $this->actingAs($this->otherParent, 'sanctum');
    expect($this->getJson('/api/me/gallery')->json('data'))->toBe([]);
    $this->get("/api/gallery/photos/{$id}/thumb")->assertForbidden();
    $this->actingAs($this->teacher, 'sanctum');
    $this->get("/api/gallery/photos/{$id}/thumb")->assertOk();
    $this->get("/api/gallery/photos/{$id}/image?download=1")->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson("/api/gallery/photos/{$id}/thumb", ['Authorization' => ''])->assertUnauthorized();
});

it('shares with all guardians of the track, by level, and by competition', function () {
    $all = ($this->makeAlbum)(['link_type' => null, 'link_id' => null, 'visibility' => 'all_guardians', 'shared_at' => now()]);
    $girls = ($this->makeAlbum)(['link_type' => null, 'link_id' => null, 'gender' => 'female', 'visibility' => 'all_guardians', 'shared_at' => now()]);
    $level = ($this->makeAlbum)(['link_type' => 'level', 'link_id' => $this->level->id, 'visibility' => 'linked', 'shared_at' => now()]);
    $comp = Competition::create(['name_ar' => 'مسابقة التلاوة', 'gender' => 'male', 'type' => 'recitation', 'scope' => 'authority', 'criteria' => '[]',
        'registration_opens_at' => now(), 'registration_closes_at' => now()->addWeek(), 'starts_at' => now()->addWeeks(2), 'ends_at' => now()->addWeeks(3)]);
    CompetitionParticipant::create(['competition_id' => $comp->id, 'student_id' => $this->otherKid->id, 'registered_at' => now()]);
    $compAlbum = ($this->makeAlbum)(['link_type' => 'competition', 'link_id' => $comp->id, 'visibility' => 'linked', 'shared_at' => now()]);

    $this->actingAs($this->parent, 'sanctum');
    expect($this->getJson('/api/me/gallery')->json('data.*.id'))->toEqualCanonicalizing([$all->id, $level->id]);
    $this->actingAs($this->otherParent, 'sanctum');
    expect($this->getJson('/api/me/gallery')->json('data.*.id'))->toEqualCanonicalizing([$all->id, $compAlbum->id]);
    expect($girls->id)->not->toBeIn($this->getJson('/api/me/gallery')->json('data.*.id'));
});

it('keeps albums inside the staff gender track and filters by term and link', function () {
    $boys = ($this->makeAlbum)();
    $old = AcademicTerm::create(['name_ar' => 'السابق', 'name_en' => 'Old']);
    $past = ($this->makeAlbum)(['academic_term_id' => $old->id, 'title' => 'قديم']);

    actingAsRole('supervisor', ['track' => 'female']);
    expect($this->getJson('/api/gallery/albums')->json('data'))->toBe([]);
    $this->getJson("/api/gallery/albums/{$boys->id}")->assertForbidden();

    actingAsRole('supervisor');
    expect($this->getJson("/api/gallery/albums?term_id={$this->term->id}")->json('data.*.id'))->toBe([$boys->id])
        ->and($this->getJson('/api/gallery/albums?term_id=all')->json('meta.total'))->toBe(2)
        ->and($this->getJson("/api/gallery/albums?link_type=lesson&link_id={$this->mine->id}&term_id=all")->json('meta.total'))->toBe(2)
        ->and($this->getJson("/api/gallery/albums?link_type=lesson&link_id={$this->other->id}")->json('data'))->toBe([]);
    actingAsRole('guardian');
    $this->getJson('/api/gallery/albums')->assertForbidden();
});

it('warns about students without photo consent in the linked class', function () {
    actingAsRole('supervisor');
    $this->putJson("/api/students/{$this->kid->id}", ['photo_consent_withheld' => true])->assertOk();
    expect(AuditLog::where('action', 'student.updated')->latest('id')->first()->new_values['photo_consent_withheld'])->toBeTrue();

    $album = ($this->makeAlbum)();
    $this->getJson("/api/gallery/albums/{$album->id}/consent")->assertOk()->assertJsonPath('data.0.id', $this->kid->id)->assertJsonCount(1, 'data');
    $this->getJson("/api/gallery/albums/{$album->id}")->assertJsonPath('data.consent_withheld.0.full_name', $this->kid->full_name);
    $this->getJson("/api/students/{$this->kid->id}")->assertJsonPath('data.photo_consent_withheld', true);
});

it('reorders, sets the cover, deletes photos and albums', function () {
    $album = ($this->makeAlbum)(['created_by' => $this->teacher->id]);
    $this->actingAs($this->teacher, 'sanctum');
    $ids = collect(range(1, 3))->map(fn () => $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => ($this->jpeg)(600, 400)])->json('data.id'))->all();
    $this->postJson("/api/gallery/albums/{$album->id}/reorder", ['ids' => [$ids[2], $ids[0]]])->assertOk()->assertJson(['data' => [$ids[2], $ids[0], $ids[1]]]);
    $this->putJson("/api/gallery/albums/{$album->id}", ['cover_photo_id' => $ids[1]])->assertOk()->assertJsonPath('data.cover.id', $ids[1]);

    // Supervisor's photo: the teacher cannot delete it.
    actingAsRole('supervisor');
    $sup = $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => ($this->jpeg)(600, 400)])->json('data.id');
    $this->actingAs($this->teacher, 'sanctum');
    $this->deleteJson("/api/gallery/photos/{$sup}")->assertForbidden();
    $this->deleteJson("/api/gallery/photos/{$ids[1]}")->assertOk();
    expect($album->fresh()->cover_photo_id)->toBeNull();
    $this->deleteJson("/api/gallery/albums/{$album->id}")->assertForbidden();

    actingAsRole('supervisor');
    $this->deleteJson("/api/gallery/albums/{$album->id}")->assertOk();
    expect(Album::count())->toBe(0)->and(AlbumPhoto::count())->toBe(0)->and(Media::count())->toBe(0)
        ->and(AuditLog::where('action', 'gallery.photo_deleted')->count())->toBe(1)
        ->and(AuditLog::where('action', 'gallery.album_deleted')->count())->toBe(1);
});

it('sends one WhatsApp message per guardian when an album is shared with them', function () {
    ($this->family)($this->mine); // a second family in the class
    $album = ($this->makeAlbum)();
    actingAsRole('supervisor');
    $this->putJson("/api/gallery/albums/{$album->id}/sharing", ['visibility' => 'linked', 'allow_download' => false, 'notify' => true])
        ->assertOk()->assertJsonPath('notified', 2);
    $this->putJson("/api/gallery/albums/{$album->id}/sharing", ['visibility' => 'linked', 'allow_download' => true, 'notify' => true])
        ->assertJsonPath('notified', 0);
    expect(MessageLog::where('type', 'album_shared')->count())->toBe(2)
        ->and(MessageLog::where('type', 'album_shared')->first()->body)->toContain('رحلة');

    $unlinked = ($this->makeAlbum)(['link_type' => null, 'link_id' => null]);
    $this->putJson("/api/gallery/albums/{$unlinked->id}/sharing", ['visibility' => 'linked', 'allow_download' => false])->assertUnprocessable();
});

it('refuses videos when ffmpeg is off, and accepts a short one when it runs', function () {
    $album = ($this->makeAlbum)();
    actingAsRole('supervisor');
    $clip = UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4');
    $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => $clip], ['Accept' => 'application/json'])->assertUnprocessable();

    config()->set('ahl.gallery.video', 'auto');
    Cache::flush();
    if (! Process::run(['ffmpeg', '-version'])->successful()) {
        $this->markTestSkipped('ffmpeg is not installed here');
    }
    $path = sys_get_temp_dir().'/gallery-test-'.uniqid().'.mp4';
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=2:size=320x240:rate=10', '-pix_fmt', 'yuv420p', $path])->throw();
    $this->getJson('/api/gallery/options')->assertJsonPath('video_enabled', true);
    $res = $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => new UploadedFile($path, 'clip.mp4', 'video/mp4', null, true)])
        ->assertCreated()->assertJsonPath('data.kind', 'video')->assertJsonPath('data.duration_seconds', 2);
    $this->get('/api/gallery/photos/'.$res->json('data.id').'/video')->assertOk();
    @unlink($path);
});

it('links albums to programs and trips: registered students only, track and term from the activity', function () {
    $trip = App\Models\Activity::create(['type' => 'trip', 'academic_term_id' => $this->term->id, 'name_ar' => 'رحلة المتحف', 'starts_on' => today()->toDateString(), 'gender' => 'male', 'status' => 'open']);
    App\Models\ActivityRegistration::create(['activity_id' => $trip->id, 'student_id' => $this->kid->id, 'status' => 'registered']);
    App\Models\ActivityRegistration::create(['activity_id' => $trip->id, 'student_id' => $this->otherKid->id, 'status' => 'waitlist']);

    actingAsRole('supervisor');
    expect($this->getJson('/api/gallery/options')->json('activities.*.id'))->toBe([$trip->id]);
    $id = $this->postJson('/api/gallery/albums', ['title' => 'صور الرحلة', 'album_date' => today()->toDateString(), 'link_type' => 'activity', 'link_id' => $trip->id])
        ->assertCreated()->assertJsonPath('data.gender', 'male')->assertJsonPath('data.link.name', 'رحلة المتحف')->json('data.id');
    $this->putJson("/api/gallery/albums/{$id}/sharing", ['visibility' => 'linked', 'allow_download' => false])->assertOk();

    $this->actingAs($this->parent, 'sanctum');
    expect($this->getJson('/api/me/gallery')->json('data.*.id'))->toBe([$id]);
    $this->actingAs($this->otherParent, 'sanctum');
    expect($this->getJson('/api/me/gallery')->json('data'))->toBe([]); // waitlisted, not registered

    // A trip open to both tracks gives a mixed album; teachers cannot link activities.
    $both = App\Models\Activity::create(['type' => 'program', 'academic_term_id' => $this->term->id, 'name_ar' => 'برنامج صيفي', 'starts_on' => today()->toDateString(), 'status' => 'open']);
    actingAsRole('supervisor');
    $this->postJson('/api/gallery/albums', ['title' => 'البرنامج', 'album_date' => today()->toDateString(), 'link_type' => 'activity', 'link_id' => $both->id])
        ->assertCreated()->assertJsonPath('data.gender', 'mixed');
    $this->actingAs($this->teacher, 'sanctum');
    $this->postJson('/api/gallery/albums', ['title' => 'ألبوم المعلم', 'album_date' => today()->toDateString(), 'link_type' => 'activity', 'link_id' => $trip->id])->assertForbidden();
});

it('lets supervisors edit any album and delete any photo; teachers only their own', function () {
    $album = ($this->makeAlbum)(['created_by' => $this->teacher->id]);
    $this->actingAs($this->teacher, 'sanctum');
    $photo = $this->post("/api/gallery/albums/{$album->id}/photos", ['file' => ($this->jpeg)(600, 400)])->json('data.id');

    $colleague = User::factory()->role('teacher')->create();
    $this->mine->update(['teacher_id' => $colleague->id]);
    $this->actingAs($colleague, 'sanctum');
    $this->putJson("/api/gallery/albums/{$album->id}", ['title' => 'ليس ألبومي'])->assertForbidden();
    $this->deleteJson("/api/gallery/photos/{$photo}")->assertForbidden();

    actingAsRole('supervisor');
    $this->putJson("/api/gallery/albums/{$album->id}", ['title' => 'عنوان المشرف'])->assertOk()->assertJsonPath('data.title', 'عنوان المشرف');
    $this->deleteJson("/api/gallery/photos/{$photo}")->assertOk();
});
